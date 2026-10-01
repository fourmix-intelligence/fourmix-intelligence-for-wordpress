<?php

namespace FourmixIntelligence\WordPress\Support;

/** 会話を本人・訪問者・AIに結び付け、通信断後の重複送信を防ぎます。 */
final class ChatSession {
	public static function register(): void {
		add_action( 'fourmix_intelligence_chat_receipt_expired', array( self::class, 'expire' ) );
	}

	public static function expire( string $hash ): void {
		if ( preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			delete_option( 'fmi_execution_' . $hash );
		}
	}

	public static function run( string $scope, string $key, array $intent, callable $callback ): array {
		self::key( $key );
		$hash = hash( 'sha256', $scope . ':' . $key );
		if ( ! wp_next_scheduled( 'fourmix_intelligence_chat_receipt_expired', array( $hash ) ) ) {
			wp_schedule_single_event( time() + DAY_IN_SECONDS + 120, 'fourmix_intelligence_chat_receipt_expired', array( $hash ) );
		}
		$entry = ( new ExecutionJournal() )->run( $scope, $key, $intent, $callback );
		return 'succeeded' === $entry['state'] ? $entry['data'] : array(
			'state'      => 'unknown_effect',
			'request_id' => $key,
		);
	}

	public static function status( string $scope, string $key ): array {
		self::key( $key );
		$entry = get_option( 'fmi_execution_' . hash( 'sha256', $scope . ':' . $key ), null );
		if ( ! is_array( $entry ) ) {
			return array( 'state' => 'not_started' );
		}
		return 'succeeded' === $entry['state'] ? array(
			'state'    => 'succeeded',
			'response' => $entry['data'],
		) : array( 'state' => 'unknown_effect' );
	}

	public static function key( string $key ): string {
		if ( ! preg_match( '/^([0-9]{13}):[a-f0-9-]{36}$/iD', $key, $parts ) ) {
			throw new \InvalidArgumentException( esc_html__( '送信の確認情報がありません。画面を開き直してください。', 'fourmix-intelligence' ) );
		}
		$age = time() - (int) floor( (int) $parts[1] / 1000 );
		if ( -60 > $age || DAY_IN_SECONDS <= $age ) {
			throw new \InvalidArgumentException( esc_html__( '送信の確認情報がありません。画面を開き直してください。', 'fourmix-intelligence' ) );
		}
		return $key;
	}

	public static function visitor(): string {
		$name  = 'fourmix_intelligence_visitor';
		$value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			$value = bin2hex( random_bytes( 32 ) );
			setcookie(
				$name,
				$value,
				array(
					'expires'  => 'history' === Options::get( 'conversation_mode', 'history' ) ? time() + 30 * DAY_IN_SECONDS : 0,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
			$_COOKIE[ $name ] = $value;
		}
		return hash_hmac( 'sha256', get_current_blog_id() . ':' . Options::public_agent() . ':' . $value, wp_salt( 'secure_auth' ) );
	}

	public static function actions( array $value, int $depth = 0 ): array {
		if ( $depth > 12 ) {
			return array();
		}
		$ids = array();
		if ( 'confirmation_required' === ( $value['status'] ?? '' ) && preg_match( '/^[a-f0-9-]{36}$/iD', (string) ( $value['id'] ?? '' ) ) ) {
			$ids[] = $value['id'];
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$ids = array_merge( $ids, self::actions( $child, $depth + 1 ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
