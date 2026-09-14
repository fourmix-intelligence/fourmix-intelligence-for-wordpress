<?php

namespace FourmixIntelligence\WordPress\Http;

use FourmixIntelligence\WordPress\Support\Options;

final class Client {
	/** @param array<string, mixed> $body @return array<string, mixed> */
	public function post( string $path, array $body, bool $sync = false, ?string $idempotency_key = null ): array {
		$token = (string) Options::get( $sync ? 'sync_token' : 'token', '' );
		if ( '' === $token ) {
			throw new \RuntimeException( $sync ? '資料同期キーが設定されていません。' : '接続トークンが設定されていません。' ); }
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key; }
		$response = wp_safe_remote_post(
			rtrim( (string) Options::get( 'url', 'https://mcp.ai.fourmix.co.jp' ), '/' ) . '/' . ltrim( $path, '/' ),
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'Fourmix-Intelligence-for-WordPress/' . FOURMIX_INTELLIGENCE_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( sanitize_text_field( $response->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 画面出力ではなく例外本文です。
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) ? ( $data['detail'] ?? $data['message'] ?? null ) : null;
			throw new \RuntimeException( is_string( $message ) ? sanitize_text_field( $message ) : 'Fourmix Intelligence との通信に失敗しました。' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 画面出力ではなく例外本文です。
		}
		return is_array( $data ) ? $data : array();
	}
}
