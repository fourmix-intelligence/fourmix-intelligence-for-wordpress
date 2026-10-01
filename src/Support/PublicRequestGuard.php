<?php

namespace FourmixIntelligence\WordPress\Support;

final class PublicRequestGuard {

	public static function same_origin( string $origin, string $site_url ): bool {
		$source = self::origin_parts( $origin, true );
		$target = self::origin_parts( $site_url, false );
		return null !== $source && $source === $target;
	}

	/** @return array{string, string, int}|null */
	private static function origin_parts( string $url, bool $strict ): ?array {
		if ( '' === $url || preg_match( '/[\s,\\\\]/', $url ) ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ( $strict && ( isset( $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) ) ) {
			return null;
		}
		return array( $scheme, strtolower( $parts['host'] ), $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
	}

	/** @param list<string> $trusted_proxies */
	public static function client_ip( string $peer, string $forwarded_for, array $trusted_proxies ): ?string {
		if ( false === filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		if ( ! self::trusted( $peer, $trusted_proxies ) || '' === $forwarded_for ) {
			return $peer;
		}
		if ( strlen( $forwarded_for ) > 2048 ) {
			return null;
		}
		$chain = array_map( 'trim', explode( ',', $forwarded_for ) );
		if ( count( $chain ) > 32 ) {
			return null;
		}
		foreach ( $chain as $address ) {
			if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
				return null;
			}
		}
		$chain[] = $peer;
		for ( $index = count( $chain ) - 1; $index > 0; --$index ) {
			if ( ! self::trusted( $chain[ $index ], $trusted_proxies ) ) {
				return $chain[ $index ];
			}
		}
		return $chain[0];
	}

	/** @param list<string> $trusted_proxies */
	private static function trusted( string $ip, array $trusted_proxies ): bool {
		$packed = inet_pton( $ip );
		foreach ( $trusted_proxies as $cidr ) {
			if ( ! is_string( $cidr ) ) {
				continue;
			}
			$parts   = explode( '/', $cidr );
			$network = false !== filter_var( $parts[0], FILTER_VALIDATE_IP ) ? inet_pton( $parts[0] ) : false;
			if ( false === $network || false === $packed || strlen( $network ) !== strlen( $packed ) || count( $parts ) > 2 ) {
				continue;
			}
			$prefix = $parts[1] ?? (string) ( strlen( $network ) * 8 );
			if ( ! ctype_digit( $prefix ) || (int) $prefix > strlen( $network ) * 8 ) {
				continue;
			}
			$bytes = intdiv( (int) $prefix, 8 );
			$bits  = (int) $prefix % 8;
			if ( substr( $packed, 0, $bytes ) === substr( $network, 0, $bytes ) && ( 0 === $bits || ( ord( $packed[ $bytes ] ) & ( 255 << ( 8 - $bits ) ) ) === ( ord( $network[ $bytes ] ) & ( 255 << ( 8 - $bits ) ) ) ) ) {
				return true;
			}
		}
		return false;
	}
}
