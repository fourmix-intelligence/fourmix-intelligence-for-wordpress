<?php

namespace FourmixIntelligence\WordPress\Http;

use FourmixIntelligence\WordPress\Support\Options;

final class Client {
	/** 会話の解決と確認操作は platform、それ以外の AI・同期 API は Studio を使います。 */
	public function request( string $method, string $path, ?array $body = null, ?string $token = null, ?string $idempotency_key = null, bool $platform = false ): array {
		$token ??= (string) Options::get( 'token', '' );
		if ( '' === $token ) {
			throw new \RuntimeException( esc_html__( '接続トークンを確認してください。', 'fourmix-intelligence' ) );
		}
		$headers  = $idempotency_key ? array( 'Idempotency-Key' => $idempotency_key ) : array();
		$response = $this->raw( $method, $path, null === $body ? null : wp_json_encode( $body ), $token, $headers, 2 * MB_IN_BYTES, $platform );
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( esc_html__( '接続先の応答を確認できませんでした。', 'fourmix-intelligence' ) );
		}
		return $data;
	}

	public function catalog( string $audience, ?string $token = null ): array {
		$items = $this->request( 'GET', '/api/v3/ai/plugins/metadata', null, $token );
		return array_values( array_filter( $items, static fn( $item ) => is_array( $item ) && ( $item['audience'] ?? '' ) === $audience && 'fincube' !== ( $item['name'] ?? '' ) ) );
	}
	/** WordPressのURL検証・TLS・HTTP転送を維持したバイナリ/NDJSON用の入口。 */
	public function raw( string $method, string $path, ?string $body, ?string $token = null, array $headers = array(), int $response_bytes = 20 * MB_IN_BYTES, bool $platform = false ): array {
		$token ??= (string) Options::get( 'token', '' );
		if ( '' === $token ) {
			throw new \RuntimeException( esc_html__( '本人の接続を確認してください。', 'fourmix-intelligence' ) );
		}
		$url  = $this->url( $path, $platform );
		$args = array(
			'method'              => $method,
			'timeout'             => 'POST' === $method ? 180 : 20,
			'redirection'         => 0,
			'limit_response_size' => min( 20 * MB_IN_BYTES, $response_bytes ),
			'headers'             => array_merge(
				array(
					'Authorization'   => 'Bearer ' . $token,
					'Origin'          => $this->origin(),
					'Accept'          => 'application/json',
					'Content-Type'    => 'application/json',
					'Accept-Encoding' => 'identity',
				),
				$headers
			),
			'body'                => $body,
		);
		// 統合開発環境の固定設定だけを許可し、設定画面の任意URLには適用しません。
		$local = Options::local_url( $url, $platform ? 'platform_url' : 'url' );
		$reply = $local ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $reply ) || wp_remote_retrieve_response_code( $reply ) < 200 || wp_remote_retrieve_response_code( $reply ) >= 300 ) {
			throw new \RuntimeException( esc_html__( '接続先で処理を確認できません。更新を繰り返さず結果を確認してください。', 'fourmix-intelligence' ), is_wp_error( $reply ) ? 502 : (int) wp_remote_retrieve_response_code( $reply ) );
		}
		return $reply;
	}
	private function url( string $path, bool $platform = false ): string {
		$base = $platform ? Options::get( 'platform_url', 'https://platform.ai.fourmix.co.jp' ) : Options::get( 'url', 'https://platform.ai.fourmix.co.jp/intelligence' );
		return rtrim( (string) $base, '/' ) . '/' . ltrim( $path, '/' );
	}
	/** 上流のNDJSON断片だけを即時転送し、完了後の分割再生はしません。 */
	public function stream( string $path, array $body, ?string $token, callable $emit ): array {
		$buffer  = '';
		$bytes   = 0;
		$created = array();
		$final   = null;
		$failure = null;
		$used    = false;
		$url     = $this->url( $path );
		$read    = static function ( string $chunk ) use ( &$buffer, &$bytes, &$created, &$final, &$failure, $emit ): void {
			$bytes += strlen( $chunk );
			if ( $bytes > 2 * MB_IN_BYTES ) {
				$failure = true;
				return;
			}
			$buffer .= $chunk;
			while ( str_contains( $buffer, "\n" ) ) {
				$end    = strpos( $buffer, "\n" );
				$line   = trim( substr( $buffer, 0, $end ) );
				$buffer = substr( $buffer, $end + 1 );
				if ( '' === $line ) {
					continue;
				}
				$event = json_decode( $line, true );
				if ( ! is_array( $event ) || ! is_string( $event['type'] ?? null ) || ! is_array( $event['data'] ?? null ) ) {
					$failure = true;
					continue;
				}
				if ( 'run.created' === $event['type'] ) {
					$created = $event;
				} elseif ( 'run.completed' === $event['type'] ) {
					$final = $event['data']['result'] ?? null;
					continue;
				} elseif ( 'run.failed' === $event['type'] ) {
					$failure = true;
					continue;
				}
				$emit( $event );
			}
		};
		$curl    = static function ( $handle, $args, $target ) use ( $url, $read, &$used, &$failure ): void {
			if ( $target !== $url ) {
				return;
			}
			$used = true;
			// WP HTTP APIの転送ハンドルだけを利用します。例外や短い戻り値でPOST再試行を起こしません。
			curl_setopt(
				$handle,
				CURLOPT_WRITEFUNCTION,
				static function ( $handle, $chunk ) use ( $read, &$failure ): int { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WordPress標準HTTP転送の逐次受信フックです。
					try {
						if ( curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) >= 200 && curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ) < 300 ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- WordPress標準HTTP転送の状態確認です。
							$read( $chunk );
						}
					} catch ( \Throwable $error ) {
						$failure = true;
					}
					return strlen( $chunk );
				}
			);
		};
		add_action( 'http_api_curl', $curl, PHP_INT_MAX, 3 );
		try {
			$this->raw( 'POST', $path, wp_json_encode( $body ), $token, array( 'Accept' => 'application/x-ndjson' ) );
			if ( '' !== trim( $buffer ) ) {
				$read( "\n" );
			}
			if ( ! $used || $failure || ! is_array( $final ) ) {
				throw new \RuntimeException( esc_html__( '回答の完了を確認できません。再送せず送信結果を確認してください。', 'fourmix-intelligence' ) );
			}
			return array(
				'run_id'          => $created['run_id'] ?? '',
				'conversation_id' => $created['data']['conversation_id'] ?? $body['conversation_id'] ?? '',
				'customer_token'  => $created['data']['customer_token'] ?? '',
				'result'          => $final,
			);
		} finally {
			remove_action( 'http_api_curl', $curl, PHP_INT_MAX );
		}
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
