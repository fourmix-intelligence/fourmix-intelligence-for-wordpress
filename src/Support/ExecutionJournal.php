<?php

namespace FourmixIntelligence\WordPress\Support;

/** 通信断後も同じ更新を繰り返さないため、実行前に状態を保存します。 */
final class ExecutionJournal {
	public function find( string $scope, string $key, array $intent ): ?array {
		$entry = get_option( 'fmi_execution_' . hash( 'sha256', $scope . ':' . $key ), null );
		if ( ! is_array( $entry ) ) {
			return null;
		}
		if ( ! hash_equals( (string) ( $entry['fingerprint'] ?? '' ), hash( 'sha256', wp_json_encode( $intent ) ) ) ) {
			throw new \InvalidArgumentException( esc_html__( '同じ重複防止キーで内容を変更できません。', 'fourmix-intelligence' ) );
		}
		return $entry;
	}
	public function run( string $scope, string $key, array $intent, callable $callback ): array {
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,120}$/D', $key ) ) {
			throw new \InvalidArgumentException( esc_html__( '重複防止キーを確認してください。', 'fourmix-intelligence' ) );
		}
		$name        = 'fmi_execution_' . hash( 'sha256', $scope . ':' . $key );
		$fingerprint = hash( 'sha256', wp_json_encode( $intent ) );
		$entry       = array(
			'actor'       => get_current_user_id(),
			'operation'   => $intent['operation'] ?? '',
			'fingerprint' => $fingerprint,
			'state'       => 'unknown_effect',
			'created_at'  => time(),
		);
		if ( ! add_option( $name, $entry, '', false ) ) {
			$existing = (array) get_option( $name, array() );
			if ( ! hash_equals( (string) ( $existing['fingerprint'] ?? '' ), $fingerprint ) ) {
				throw new \InvalidArgumentException( esc_html__( '同じ重複防止キーで内容を変更できません。', 'fourmix-intelligence' ) );
			}
			return $existing;
		}
		try {
			$entry['data']  = $callback();
			$entry['state'] = 'succeeded';
		} catch ( \Throwable $error ) {
			$entry['state'] = 'unknown_effect';
		}
		update_option( $name, $entry, false );
		do_action(
			'fourmix_intelligence_operation_audit',
			array(
				'actor'      => get_current_user_id(),
				'operation'  => $intent['operation'] ?? '',
				'state'      => $entry['state'],
				'request_id' => hash( 'sha256', $key ),
			)
		);
		return $entry;
	}
}
