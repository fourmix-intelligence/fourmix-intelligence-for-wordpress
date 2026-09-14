<?php

namespace FourmixIntelligence\WordPress\Support;

final class Options {
	/** @return array<string, mixed> */
	public static function all(): array {
		return (array) get_option( 'fourmix_intelligence_settings', array() ); }
	public static function get( string $key, mixed $fallback = null ): mixed {
		$constant = array(
			'url'        => 'FOURMIX_INTELLIGENCE_API_URL',
			'token'      => 'FOURMIX_INTELLIGENCE_API_TOKEN',
			'agent'      => 'FOURMIX_INTELLIGENCE_AGENT',
			'dataset'    => 'FOURMIX_INTELLIGENCE_DATASET',
			'sync_token' => 'FOURMIX_INTELLIGENCE_SYNC_TOKEN',
		)[ $key ] ?? null;
		if ( $constant && defined( $constant ) && constant( $constant ) ) {
			return constant( $constant );
		}
		return self::all()[ $key ] ?? $fallback;
	}
	public static function enabled(): bool {
		return '' !== self::get( 'token', '' ) && '' !== self::get( 'agent', '' ); }
}
