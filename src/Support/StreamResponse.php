<?php

namespace FourmixIntelligence\WordPress\Support;

use WP_REST_Response;

/** RESTの認可後にだけ、上流から受信したNDJSONを逐次配信します。 */
final class StreamResponse {
	public static function clean( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, array( 'customer_token', 'conversation_token', 'run_ticket', 'authorization' ), true ) ) {
				unset( $value[ $key ] );
			} elseif ( 'artifacts' === $key && is_array( $item ) ) {
				$value[ $key ] = array_map( static fn( $artifact ) => array_intersect_key( $artifact, array_flip( array( 'id', 'name', 'mime', 'size', 'expires_at', 'kind', 'download_url' ) ) ), array_values( array_filter( $item, 'is_array' ) ) );
			} elseif ( is_array( $item ) ) {
				$value[ $key ] = self::clean( $item );
			}
		}
		return $value;
	}
	public static function create( callable $run ): WP_REST_Response {
		$response = new WP_REST_Response(
			null,
			200,
			array(
				'Content-Type'           => 'application/x-ndjson; charset=UTF-8',
				'Cache-Control'          => 'private, no-store, no-transform',
				'X-Accel-Buffering'      => 'no',
				'X-Content-Type-Options' => 'nosniff',
			)
		);
		$serve    = static function ( $served, $result ) use ( $run, $response, &$serve ) {
			if ( $result !== $response ) {
				return $served;
			}
			remove_filter( 'rest_pre_serve_request', $serve );
			// 受信停止や画面移動でも実行記録を確定させ、未知の更新を再送しません。
			ignore_user_abort( true );
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			$emit  = static function ( array $event ): void {
				echo wp_json_encode( self::clean( $event ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NDJSONとしてJSON符号化し、HTMLでは返しません。
				flush();
			};
			$reply = $run( $emit );
			$data  = $reply->get_data();
			if ( $reply->get_status() < 300 && ! in_array( $data['state'] ?? '', array( 'unknown_effect', 'cancelled' ), true ) ) {
				$emit(
					array(
						'type' => 'run.completed',
						'data' => array(
							'response' => $data,
							'result'   => $data['result'] ?? array(),
						),
					)
				);
			} else {
				$emit(
					array(
						'type' => 'run.failed',
						'data' => array(
							'message'     => $data['message'] ?? __( '結果を確認できません。再送せず送信結果を確認してください。', 'fourmix-intelligence' ),
							'status_code' => $reply->get_status(),
							'login_url'   => $data['login_url'] ?? null,
							'state'       => 'cancelled' === ( $data['state'] ?? '' ) ? 'cancelled' : 'unknown_effect',
							'code'        => 'RUN_CANCELLED' === ( $data['code'] ?? '' ) ? 'RUN_CANCELLED' : null,
						),
					)
				);
			}
			return true;
		};
		add_filter( 'rest_pre_serve_request', $serve, 10, 2 );
		return $response;
	}
}
