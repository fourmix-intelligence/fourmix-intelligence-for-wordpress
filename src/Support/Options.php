<?php

namespace FourmixIntelligence\WordPress\Support;

final class Options {
	/** @return array<string, mixed> */
	public static function all(): array {
		return (array) get_option( 'fourmix_intelligence_settings', array() ); }
	public static function binding(): array {
		$binding = (array) get_option( 'fourmix_intelligence_bridge_binding', array() );
		if ( ! empty( $binding['bootstrap'] ) && ! hash_equals( (string) ( $binding['key_fingerprint'] ?? '' ), hash( 'sha256', (string) ( self::all()['bridge_secret'] ?? '' ) ) ) ) {
			return array();
		}
		return $binding;
	}
	public static function get( string $key, mixed $fallback = null ): mixed {
		$mapped  = array(
			'platform_url'      => 'platform_url',
			'portal_url'        => 'portal_url',
			'native_tenant'     => 'tenant_id',
			'native_connection' => 'connection_id',
			'url'               => 'studio_url',
		)[ $key ] ?? null;
		$binding = self::binding();
		if ( $mapped && isset( $binding['bootstrap'][ $mapped ] ) ) {
			return $binding['bootstrap'][ $mapped ];
		}
		$constant = array(
			'platform_url' => 'FOURMIX_INTELLIGENCE_PLATFORM_URL',
			'url'          => 'FOURMIX_INTELLIGENCE_API_URL',
			'token'        => 'FOURMIX_INTELLIGENCE_API_TOKEN',
			'agent'        => 'FOURMIX_INTELLIGENCE_AGENT',
			'dataset'      => 'FOURMIX_INTELLIGENCE_DATASET',
			'sync_token'   => 'FOURMIX_INTELLIGENCE_SYNC_TOKEN',
		)[ $key ] ?? null;
		if ( $constant && defined( $constant ) && constant( $constant ) ) {
			return constant( $constant );
		}
		return self::all()[ $key ] ?? $fallback;
	}
	public static function enabled(): bool {
		return '' !== self::get( 'token', '' ) && '' !== self::get( 'agent', '' ); }

	/** ローカル開発で、署名済み同期またはサーバー定数の固定URLだけを使う。 */
	public static function local_url( string $url, string $key ): bool {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || 'http' !== wp_parse_url( $url, PHP_URL_SCHEME ) || ! in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'platform', 'intelligence', 'localhost', '127.0.0.1', 'host.docker.internal' ), true ) ) {
			return false;
		}
		$binding  = self::binding();
		$mapped   = 'url' === $key ? 'studio_url' : 'platform_url';
		$constant = 'url' === $key ? 'FOURMIX_INTELLIGENCE_API_URL' : 'FOURMIX_INTELLIGENCE_PLATFORM_URL';
		$base     = $binding['bootstrap'][ $mapped ] ?? ( defined( $constant ) ? constant( $constant ) : '' );
		return $base && str_starts_with( $url, rtrim( $base, '/' ) . '/' );
	}

	public static function public_agent(): string {
		return (string) self::get( 'agent', '' );
	}
}
