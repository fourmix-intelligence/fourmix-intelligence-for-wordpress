<?php

namespace FourmixIntelligence\WordPress\Knowledge;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\Options;

final class Synchronizer {
	public function register(): void {
		add_action( 'save_post', array( $this, 'saved' ), 20, 2 );
		add_action( 'before_delete_post', array( $this, 'deleted' ) );
		add_action( 'fourmix_intelligence_process_sync', array( $this, 'process' ) );
	}
	public function saved( int $post_id, \WP_Post $post ): void {
		if ( ! Options::get( 'sync_enabled', false ) || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, (array) Options::get( 'sync_post_types', array( 'post', 'page', 'product' ) ), true ) ) {
			return;
		}
		$this->enqueue( $post->post_type, $post_id, $this->public_content( $post ) ? 'replace' : 'delete', $this->next_version() );
	}
	public function deleted( int $post_id ): void {
		if ( ! Options::get( 'sync_enabled', false ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$this->enqueue( $post->post_type, $post_id, 'delete', $this->next_version() );
	}
	/** 同秒の編集と並行保存にも、再起動後まで一意の更新番号を割り当てる。 */
	private function next_version(): int {
		global $wpdb;
		$name = 'fourmix_intelligence_sync_version';
		$seed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $wpdb->options, $name, '0', 'no' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// UPDATE 自体で採番し、接続固有の LAST_INSERT_ID から取得する。他の保存と競合しない。
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = LAST_INSERT_ID(GREATEST(CAST(option_value AS UNSIGNED) + 1, %d)) WHERE option_name = %s', $wpdb->options, (int) floor( microtime( true ) * 1000 ), $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$version = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $seed || 1 !== $updated || ! is_numeric( $version ) || (int) $version < 1 ) {
			throw new \RuntimeException( '資料同期の更新番号を発行できませんでした。' );
		}
		return (int) $version;
	}
	private function enqueue( string $type, int $id, string $operation, int $version ): void {
		if ( '' === (string) Options::get( 'dataset', '' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'fourmix_intelligence_outbox';
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (object_type,object_id,operation,version,target_hash,available_at,created_at) VALUES (%s,%d,%s,%d,%s,%s,%s)', $table, $type, $id, $operation, $version, $this->target(), current_time( 'mysql', true ), current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'fourmix_intelligence_process_sync', array(), 'fourmix-intelligence', true );
		} else {
			$this->schedule( MINUTE_IN_SECONDS );
		}
	}
	private function target(): string {
		return hash( 'sha256', rtrim( (string) Options::get( 'url', 'https://platform.ai.fourmix.co.jp/intelligence' ), '/' ) . "\n" . (string) Options::get( 'dataset' ) );
	}
	public function process(): void {
		if ( ! Options::get( 'sync_enabled', false ) || ! Options::get( 'dataset' ) || ! Options::get( 'sync_token' ) ) {
			return;
		}
		global $wpdb;
		$lock = 'fmi_sync_' . substr( hash( 'sha256', $wpdb->options ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->schedule( 10 );
			return;
		}
		try {
			$this->process_batch();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}
	private function process_batch(): void {
		global $wpdb;
		$table       = $wpdb->prefix . 'fourmix_intelligence_outbox';
		$receipt_key = 'fourmix_intelligence_sync_receipt';
		$receipt     = (array) get_option( $receipt_key, array() );
		$dataset     = (string) Options::get( 'dataset' );
		$target      = $this->target();
		if ( $receipt && ( $receipt['target'] !== $target || (int) ( $receipt['retry_at'] ?? 0 ) > time() ) ) {
			$this->schedule( 60 );
			return;
		}
		$rows = $receipt['rows'] ?? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE target_hash = %s AND available_at <= %s ORDER BY id ASC LIMIT 50', $table, $target, current_time( 'mysql', true ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $rows ) {
			return;
		}
		if ( ! $receipt ) {
			$receipt = array(
				'target'     => $target,
				'rows'       => $rows,
				'records'    => array_map( array( $this, 'record' ), $rows ),
				'request_id' => wp_generate_uuid4(),
				'job_id'     => null,
			);
			// 送信前に不変の本文と受付キーを保存し、通信結果不明でも同じ受付を確認する。
			update_option( $receipt_key, $receipt, false );
		}
		try {
			$client = new Client();
			if ( ! $receipt['job_id'] ) {
				$accepted = $client->post( '/api/v3/data/' . rawurlencode( $dataset ) . '/documents/sync', array( 'records' => $receipt['records'] ), true, $receipt['request_id'] );
				if ( ! preg_match( '/^[a-f0-9-]{36}$/Di', (string) ( $accepted['id'] ?? '' ) ) ) {
					throw new \RuntimeException( '資料同期の受付番号を確認できませんでした。' );
				}
				$receipt['job_id'] = $accepted['id'];
				update_option( $receipt_key, $receipt, false );
			}
			$job = $client->request( 'GET', '/api/v3/data/' . rawurlencode( $dataset ) . '/sync/' . rawurlencode( $receipt['job_id'] ), null, (string) Options::get( 'sync_token' ) );
			if ( in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
				$this->schedule( 10 );
				return;
			}
			$result = $job['result'] ?? array();
			if ( 'succeeded' !== ( $job['status'] ?? '' ) || ! isset( $result['received'], $result['failed'], $result['error_count'] ) || count( $rows ) !== (int) $result['received'] || 0 < (int) $result['failed'] || 0 < (int) $result['error_count'] ) {
				if ( in_array( $job['status'] ?? '', array( 'succeeded', 'failed' ), true ) ) {
					// 確定した失敗だけ新しい受付で再試行できる。未確定の受付は維持する。
					delete_option( $receipt_key );
					$receipt = array();
				}
				throw new \RuntimeException( '資料同期の完了を確認できませんでした。' );
			}
			$ids          = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$query        = $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- 直前で可変個数のプレースホルダーを prepare 済みです。
			delete_option( $receipt_key );
			$this->schedule( 1 );
		} catch ( \Throwable $e ) {
			$next_delay = HOUR_IN_SECONDS;
			foreach ( $rows as $row ) {
				$delay      = min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 5, (int) $row['attempts'] ) ) );
				$next_delay = min( $next_delay, $delay );
				$wpdb->update(
					$table,
					array(
						'attempts'     => (int) $row['attempts'] + 1,
						'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%d', '%s' ),
					array( '%d' )
				); }
			if ( $receipt ) {
				$receipt['retry_at'] = time() + $next_delay;
				update_option( $receipt_key, $receipt, false );
			}
			$this->schedule( $next_delay );
		}
	}
	private function schedule( int $delay ): void {
		// 周期確認とは引数を分け、既存の5分周期に短い再確認を妨げさせない。
		$args    = array( 'pending_batch' );
		$desired = time() + max( 1, $delay );
		$next    = wp_next_scheduled( 'fourmix_intelligence_process_sync', $args );
		if ( ! $next || $next > $desired ) {
			if ( $next ) {
				wp_unschedule_event( $next, 'fourmix_intelligence_process_sync', $args );
			}
			wp_schedule_single_event( $desired, 'fourmix_intelligence_process_sync', $args );
		}
	}
	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function record( array $row ): array {
		$record = array(
			'key'       => $row['object_type'] . ':' . $row['object_id'],
			'version'   => (int) $row['version'],
			'operation' => $row['operation'],
		);
		if ( 'delete' === $row['operation'] ) {
			return $record;
		}
		$post = get_post( (int) $row['object_id'] );
		if ( ! $post || ! $this->public_content( $post ) ) {
			$record['operation'] = 'delete';
			return $record; }
		$parts = array( '# ' . get_the_title( $post ), 'URL: ' . get_permalink( $post ), wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post );
			if ( $product ) {
				$parts[] = '商品ID: ' . $product->get_id() . "\n商品コード: " . $product->get_sku() . "\n商品分類: " . wc_get_product_category_list( $product->get_id(), ', ', '', '' );
			}
		}
		$record['text'] = implode( "\n\n", array_filter( $parts ) );
		return $record;
	}

	private function public_content( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return false;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			return $product && 'hidden' !== $product->get_catalog_visibility();
		}
		return true;
	}
}
