<?php

// 実サイト・DB・外部通信を使わず、本人確認済みの URL 組合せと実際の呼び出し先を確認する。
namespace FourmixIntelligence\WordPress\Support {
	final class NativeIdentity {
		public static function token(): string { return 'synthetic-only'; }
		public static function current( bool $write = false, ?string $token = null ): array { return array( 'account_id' => 'synthetic-actor' ); }
		public static function scope( array $identity ): string { return 'synthetic-scope'; }
	}
}

namespace {
	define( 'MB_IN_BYTES', 1024 * 1024 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'WP_DEBUG', true );
	function esc_html__( $text, $domain ) { return $text; }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function home_url() { return 'https://site.synthetic.test'; }
	function get_option( $key, $default = null ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
	function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; }
	function get_current_user_id() { return 10; }
	function do_action( $hook, $data ) {}
	function sanitize_key( $value ) { return $value; }
	function wp_list_pluck( $items, $key ) { return array_column( $items, $key ); }
	function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; }
	function wp_max_upload_size() { return 20 * MB_IN_BYTES; }
	function is_wp_error( $value ) { return false; }
	function wp_remote_retrieve_body( $reply ) { return $reply['body']; }
	function wp_remote_retrieve_response_code( $reply ) { return 200; }
	function wp_safe_remote_request( $url, $args ) { return synthetic_reply( $url, $args, 'safe' ); }
	function wp_remote_request( $url, $args ) { return synthetic_reply( $url, $args, 'local' ); }
	function synthetic_reply( $url, $args, $transport ) {
		$GLOBALS['requests'][] = array( $url, $args, $transport );
		$path = parse_url( $url, PHP_URL_PATH );
		$data = match ( true ) {
			str_ends_with( $path, '/plugins/metadata' ) => array( array( 'name' => 'studio-test', 'audience' => 'internal' ) ),
			str_ends_with( $path, '/studio-test/metadata' ) => array( 'attachments' => array( 'enabled' => true ) ),
			str_ends_with( $path, '/resolve' ) => array( 'identify' => '12345678-1234-4234-8234-123456789012' ),
			str_ends_with( $path, '/confirm' ) => array( 'status' => 'succeeded' ),
			default => array( 'status' => 'confirmation_required', 'connection_id' => 'other-connection' ),
		};
		return array( 'body' => json_encode( $data ) );
	}
	class WP_REST_Request {
		public function __construct( private array $params ) {}
		public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	}
	class WP_REST_Response {
		public function __construct( public array $data, public int $status, array $headers ) {}
	}
	require __DIR__ . '/../src/Support/Options.php';
	require __DIR__ . '/../src/Support/Attachments.php';
	require __DIR__ . '/../src/Support/ExecutionJournal.php';
	require __DIR__ . '/../src/Support/StreamResponse.php';
	require __DIR__ . '/../src/Http/Client.php';
	require __DIR__ . '/../src/Rest/StaffController.php';
	function check( bool $value ): void { if ( ! $value ) { throw new \RuntimeException( '公開 API と Studio API の分流を確認できません。' ); } }
	foreach ( array(
		array( 'https://demo.platform.ai.fourmix.co.jp', 'https://demo.platform.ai.fourmix.co.jp/intelligence', 'safe' ),
		array( 'http://platform:8000', 'http://intelligence:8000', 'local' ),
	) as list( $platform, $studio, $transport ) ) {
		$GLOBALS['requests'] = array();
		$GLOBALS['options'] = array( 'fourmix_intelligence_bridge_binding' => array( 'bootstrap' => array( 'platform_url' => $platform, 'studio_url' => $studio ), 'key_fingerprint' => hash( 'sha256', '' ) ) );
		$scope = 'synthetic-scope_' . hash( 'sha256', 'studio-test' );
		$GLOBALS['transients'] = array( $scope => array( 'thread_id' => 'synthetic-thread', 'actions' => array( 'synthetic-action-1' ) ) );
		$request = new WP_REST_Request( array( 'agent' => 'studio-test', 'thread_id' => 'synthetic-thread', 'id' => 'synthetic-action-1', 'approved' => true ) );
		$staff = new \FourmixIntelligence\WordPress\Rest\StaffController();
		$access = $staff->attachment_access( $request, true );
		check( '12345678-1234-4234-8234-123456789012' === $access['conversation_id'] );
		check( 200 === $staff->action( $request )->status );
		check( 'succeeded' === $staff->confirm_action( $request )->data['status'] );
		$client = new \FourmixIntelligence\WordPress\Http\Client();
		$client->raw( 'GET', '/api/v3/ai/plugins/studio-test/conversations/' . $access['conversation_id'] . '/attachments', null, 'synthetic-only' );
		$GLOBALS['options']['fourmix_intelligence_settings']['sync_token'] = 'synthetic-sync';
		$client->post( '/api/v3/ai/plugins/studio-test/sync', array(), true, 'synthetic-idempotency' );
		$confirmations = 0;
		foreach ( $GLOBALS['requests'] as list( $url, $args, $actual_transport ) ) {
			$php = str_contains( $url, '/agent-conversations/' ) || str_contains( $url, '/connection-actions/' );
			check( str_starts_with( $url, ( $php ? $platform : $studio ) . '/api/v3/' ) );
			check( $transport === $actual_transport && 0 === $args['redirection'] );
			if ( str_ends_with( $url, '/confirm' ) ) { ++$confirmations; }
		}
		check( 1 === $confirmations );
		$last = $GLOBALS['requests'][ array_key_last( $GLOBALS['requests'] ) ][1];
		check( 'synthetic-sync' === substr( $last['headers']['Authorization'], 7 ) && 'synthetic-idempotency' === $last['headers']['Idempotency-Key'] );
	}
	echo "Demo とローカルの添付開始・操作確認・資料同期の送信先を検証しました。\n";
}
