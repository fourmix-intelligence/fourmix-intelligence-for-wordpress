<?php

namespace FourmixIntelligence\WordPress\Support;

/** 一つの option に接続情報を保存し、設定途中の状態を公開しない。 */
final class NativeConnectionBinding {

	public static function status(): array {
		$binding = Options::binding();
		return array(
			'connected'        => ! empty( $binding['bootstrap'] ),
			'configuration_id' => $binding['bootstrap']['configuration_id'] ?? '',
			'workspace_name'   => $binding['bootstrap']['workspace_name'] ?? '',
		);
	}

	public static function configure( \WP_REST_Request $request ): array|\WP_Error {
		$data = $request->get_json_params();
		$keys = array( 'schema_version', 'instance_id', 'tenant_id', 'workspace_id', 'connection_id', 'workspace_name', 'platform_url', 'portal_url', 'studio_url', 'configuration_id' );
		if ( ! is_array( $data ) || array_diff( $keys, array_keys( $data ) ) || array_diff( array_keys( $data ), $keys ) || strlen( $request->get_body() ) > 8192 || 1 !== $data['schema_version'] ) {
			return self::error( 'invalid_configuration', 422 );
		}
		foreach ( array_diff( $keys, array( 'schema_version' ) ) as $key ) {
			if ( ! is_string( $data[ $key ] ) || '' === $data[ $key ] || strlen( $data[ $key ] ) > 2048 ) {
				return self::error( 'invalid_configuration', 422 );
			}
		}
		if ( ! hash_equals( (string) get_option( 'fourmix_intelligence_application_id', '' ), $data['instance_id'] ) || ! hash_equals( $request->get_header( 'x-fourmix-workspace' ), $data['workspace_id'] ) || ! hash_equals( $request->get_header( 'x-fourmix-connection' ), $data['connection_id'] ) ) {
			return self::error( 'binding_mismatch', 409 );
		}
		foreach ( array( 'platform_url', 'portal_url', 'studio_url' ) as $key ) {
			$url   = wp_parse_url( $data[ $key ] );
			$local = defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $url['host'] ?? '', array( 'localhost', '127.0.0.1', 'host.docker.internal', 'platform', 'intelligence' ), true );
			if ( ! is_array( $url ) || empty( $url['host'] ) || array_intersect( array_keys( $url ), array( 'user', 'pass', 'query', 'fragment' ) ) || ( 'https' !== ( $url['scheme'] ?? '' ) && ! ( $local && 'http' === ( $url['scheme'] ?? '' ) ) ) ) {
				return self::error( 'invalid_url', 422 );
			}
		}
		$configuration_id = $data['configuration_id'];
		unset( $data['configuration_id'] );
		ksort( $data );
		if ( ! hash_equals( hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS ) ), $configuration_id ) ) {
			return self::error( 'invalid_configuration', 422 );
		}
		$data['configuration_id'] = $configuration_id;
		global $wpdb;
		$lock = 'fmi_binding_' . substr( hash( 'sha256', $wpdb->options ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return self::error( 'configuration_busy', 409 );
		}
		try {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'fourmix_intelligence_settings', 'options' );
			wp_cache_delete( 'fourmix_intelligence_bridge_binding', 'options' );
			$secret    = (string) Options::get( 'bridge_secret', '' );
			$canonical = implode( "\n", array( $request->get_header( 'x-fourmix-timestamp' ), $request->get_header( 'x-fourmix-nonce' ), $request->get_method(), '/wp-json' . $request->get_route(), $data['workspace_id'], $data['connection_id'], hash( 'sha256', $request->get_body() ) ) );
			if ( strlen( $secret ) < 32 || ! hash_equals( 'v1=' . hash_hmac( 'sha256', $canonical, $secret ), $request->get_header( 'x-fourmix-signature' ) ) || get_current_user_id() !== (int) Options::get( 'bridge_user_id', 0 ) ) {
				return self::error( 'configuration_changed', 409 );
			}
			$bound = Options::binding();
			if ( $bound && ( ( $bound['workspace'] ?? '' ) !== $data['workspace_id'] || ( $bound['connection'] ?? '' ) !== $data['connection_id'] ) ) {
				return self::error( 'bound', 409 );
			}
			$previous = $bound['bootstrap'] ?? array();
			foreach ( array( 'tenant_id', 'platform_url', 'portal_url', 'studio_url' ) as $key ) {
				if ( ! empty( $previous[ $key ] ) && $previous[ $key ] !== $data[ $key ] ) {
					return self::error( 'environment_changed', 409 );
				}
			}
			// 旧設定の本人確認が有効だった場合も、接続先を勝手に移さない。
			$legacy = (array) get_option( 'fourmix_intelligence_settings', array() );
			if ( ! $previous && ! empty( $legacy['native_connection'] ) ) {
				foreach ( array(
					'native_connection' => 'connection_id',
					'native_tenant'     => 'tenant_id',
					'platform_url'      => 'platform_url',
					'portal_url'        => 'portal_url',
				) as $old => $new ) {
					if ( ! empty( $legacy[ $old ] ) && rtrim( $legacy[ $old ], '/' ) !== $data[ $new ] ) {
						return self::error( 'environment_changed', 409 );
					}
				}
			}
			$value = array(
				'workspace'       => $data['workspace_id'],
				'connection'      => $data['connection_id'],
				'bootstrap'       => $data,
				'key_fingerprint' => hash( 'sha256', $secret ),
			);
			update_option( 'fourmix_intelligence_bridge_binding', $value, false );
			if ( get_option( 'fourmix_intelligence_bridge_binding' ) !== $value ) {
				return self::error( 'configuration_failed', 503 );
			}
			return self::status();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( '接続情報を確認してください。別の環境へ接続する場合は、管理者が新しい共有キーを設定してから再接続してください。業務データと会話履歴は削除されません。', 'fourmix-intelligence' ), array( 'status' => $status ) );
	}
}
