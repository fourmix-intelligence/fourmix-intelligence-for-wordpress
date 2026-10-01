<?php

namespace FourmixIntelligence\WordPress\Http;

use FourmixIntelligence\WordPress\Support\Options;

final class Client {
	public function request( string $method, string $path, ?array $body = null, ?string $token = null, ?string $idempotency_key = null ): array {
		$token ??= (string) Options::get( 'token', '' );
		if ( '' === $token ) {
			throw new \RuntimeException( esc_html__( '接続トークンを確認してください。', 'fourmix-intelligence' ) );
		}
		$url  = rtrim( (string) Options::get( 'url', 'https://mcp.ai.fourmix.co.jp' ), '/' ) . '/' . ltrim( $path, '/' );
		$args = array(
			'method'              => $method,
			'timeout'             => 'POST' === $method ? 180 : 20,
			'redirection'         => 0,
			'limit_response_size' => 2 * MB_IN_BYTES,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Origin'        => $this->origin(),
			),
			'body'                => null === $body ? null : wp_json_encode( $body ),
		);
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}
		// 統合開発環境の固定設定だけを許可し、設定画面の任意URLには適用しません。
		$local_constant = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'FOURMIX_INTELLIGENCE_API_URL' )
			&& 'http' === wp_parse_url( $url, PHP_URL_SCHEME )
			&& in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'platform', 'localhost', '127.0.0.1' ), true )
			&& str_starts_with( $url, rtrim( (string) constant( 'FOURMIX_INTELLIGENCE_API_URL' ), '/' ) . '/' );
		$response       = $local_constant ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			throw new \RuntimeException( esc_html__( '接続先で処理を確認できませんでした。更新を繰り返さず、履歴と対象データを確認してください。', 'fourmix-intelligence' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( esc_html__( '接続先の応答を確認できませんでした。', 'fourmix-intelligence' ) );
		}
		return $data;
	}

	public function catalog( string $audience, ?string $token = null ): array {
		$items = $this->request( 'GET', '/api/v3/ai/plugins/metadata', null, $token );
		return array_values( array_filter( $items, static fn( $item ) => is_array( $item ) && ( $item['audience'] ?? '' ) === $audience && 'fincube' !== ( $item['name'] ?? '' ) ) );
	}
	/** @param array<string, mixed> $body @return array<string, mixed> */
	public function post( string $path, array $body, bool $sync = false, ?string $idempotency_key = null ): array {
		$token = (string) Options::get( $sync ? 'sync_token' : 'token', '' );
		if ( '' === $token ) {
			throw new \RuntimeException( $sync ? esc_html__( '資料同期キーが設定されていません。', 'fourmix-intelligence' ) : esc_html__( '接続トークンが設定されていません。', 'fourmix-intelligence' ) );
		}
		return $this->request( 'POST', $path, $body, $token, $idempotency_key );
	}

	private function origin(): string {
		$parts = wp_parse_url( home_url() );
		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
