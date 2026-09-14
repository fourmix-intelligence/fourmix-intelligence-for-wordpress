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
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, (array) Options::get( 'sync_post_types', array( 'post', 'page', 'product' ) ), true ) ) {
			return;
		}
		$this->enqueue( $post->post_type, $post_id, 'replace', max( 1, strtotime( $post->post_modified_gmt . ' UTC' ) ) );
	}
	public function deleted( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$this->enqueue( $post->post_type, $post_id, 'delete', time() );
	}
	private function enqueue( string $type, int $id, string $operation, int $version ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'fourmix_intelligence_outbox';
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (object_type,object_id,operation,version,available_at,created_at) VALUES (%s,%d,%s,%d,%s,%s)', $table, $type, $id, $operation, $version, current_time( 'mysql', true ), current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'fourmix_intelligence_process_sync', array(), 'fourmix-intelligence', true );
		}
	}
	public function process(): void {
		if ( ! Options::get( 'dataset' ) || ! Options::get( 'sync_token' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'fourmix_intelligence_outbox';
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE available_at <= %s ORDER BY id ASC LIMIT 50', $table, current_time( 'mysql', true ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $rows ) {
			return;
		}
		$records = array_map( array( $this, 'record' ), $rows );
		try {
			( new Client() )->post( '/api/v3/data/' . rawurlencode( (string) Options::get( 'dataset' ) ) . '/documents/sync', array( 'records' => $records ), true, wp_generate_uuid4() );
			$ids          = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$query        = $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- 直前で可変個数のプレースホルダーを prepare 済みです。
		} catch ( \Throwable $e ) {
			foreach ( $rows as $row ) {
				$delay = min( HOUR_IN_SECONDS, 60 * ( 2 ** min( 5, (int) $row['attempts'] ) ) );
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
		if ( ! $post ) {
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
}
