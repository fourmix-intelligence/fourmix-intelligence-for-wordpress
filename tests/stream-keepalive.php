<?php

// WordPress・DB・外部通信を使わず、実際のパーサーとREST出力を検証します。
namespace FourmixIntelligence\WordPress\Support {

	final class Options {
		public static function get( $key, $fallback = '' ) { return 'url' === $key ? 'https://ai.synthetic.test' : 'synthetic-only'; }
		public static function local_url( $url, $kind ) { return false; }
	}
}

namespace FourmixIntelligence\WordPress\Http {

	function curl_setopt( $handle, $option, $callback ) { $GLOBALS['write_callback'] = $callback; return true; }
	function curl_getinfo( $handle, $option ) { return 200; }
}

namespace {

	const MB_IN_BYTES = 1048576;
	function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
	function esc_html__( $value, ...$args ) { return $value; }
	function __( $value, ...$args ) { return $value; }
	function wp_parse_url( $value ) { return parse_url( $value ); }
	function home_url() { return 'https://site.synthetic.test'; }
	function is_wp_error( $value ) { return false; }
	function wp_remote_retrieve_response_code( $value ) { return $value['status']; }
	function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ] = $callback; }
	function remove_action( $name, ...$args ) { unset( $GLOBALS['hooks'][ $name ] ); }
	function add_filter( $name, $callback, ...$args ) { $GLOBALS['hooks'][ $name ] = $callback; }
	function remove_filter( $name, ...$args ) { unset( $GLOBALS['hooks'][ $name ] ); }
	function wp_safe_remote_request( $url, $args ) {
		++$GLOBALS['requests'];
		$GLOBALS['hooks']['http_api_curl']( null, $args, $url );
		foreach ( $GLOBALS['chunks'] as $chunk ) {
			if ( "\n" === $chunk ) { $GLOBALS['heartbeat_seen'] = false; }
			$GLOBALS['write_callback']( null, $chunk );
			if ( "\n" === $chunk ) { check( $GLOBALS['heartbeat_seen'], '保活を後続のイベントより先に通知する' ); }
		}
		return array( 'status' => 200 );
	}
	final class WP_REST_Response {
		public function __construct( private mixed $data, private int $status, public array $headers = array() ) {}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
	function check( bool $condition, string $label ): void {
		if ( ! $condition ) { throw new \RuntimeException( $label ); }
	}
	require __DIR__ . '/../src/Http/Client.php';
	require __DIR__ . '/../src/Support/StreamResponse.php';
	$created = array( 'type' => 'run.created', 'run_id' => 'synthetic-run', 'data' => array( 'conversation_id' => 'synthetic-conversation' ) );
	$complete = array( 'type' => 'run.completed', 'data' => array( 'result' => array( 'answer' => '合成の回答' ) ) );
	$cases = array(
		array( $complete, 'complete' ),
		array( array( 'type' => 'run.failed', 'data' => array( 'code' => 'RUN_CANCELLED' ) ), 'cancelled' ),
		array( null, 'incomplete' ),
	);
	foreach ( $cases as list( $terminal, $state ) ) {
		$GLOBALS['requests'] = 0;
		$GLOBALS['chunks'] = array( wp_json_encode( $created ) . "\n", "\n" );
		if ( $terminal ) { $GLOBALS['chunks'][] = wp_json_encode( $terminal ) . "\n"; }
		$received = array();
		try {
			$reply = ( new \FourmixIntelligence\WordPress\Http\Client() )->stream( '/runs/stream', array(), 'synthetic-only', static function ( array $event ) use ( &$received ): void {
				$received[] = $event;
				if ( 'run.keepalive' === $event['type'] ) { $GLOBALS['heartbeat_seen'] = true; }
			} );
			check( 'incomplete' !== $state, '完了前の切断を成功扱いしない' );
			check( 'cancelled' === $state ? 'cancelled' === $reply['state'] : '合成の回答' === $reply['result']['answer'], '終端結果を維持する' );
		} catch ( \RuntimeException $error ) {
			if ( 'incomplete' !== $state ) { throw $error; }
			check( str_contains( $error->getMessage(), '回答の完了を確認できません' ), '途中終了のエラーを保持する' );
		}
		check( array( 'run.created', 'run.keepalive' ) === array_column( $received, 'type' ), '保活が本文や完了に化けない' );
		check( 1 === $GLOBALS['requests'], '実行要求を再送しない' );
		check( ! isset( $GLOBALS['hooks']['http_api_curl'] ), '要求後に転送フックを解除する' );
	}
	$output = '';
	$response = \FourmixIntelligence\WordPress\Support\StreamResponse::create( static function ( callable $emit ) use ( &$output ): WP_REST_Response {
		ob_start( static function ( string $chunk ) use ( &$output ): string { $output .= $chunk; return ''; }, 1 );
		$emit( array( 'type' => 'run.keepalive', 'data' => array( 'message' => 'synthetic-private-marker' ) ) );
		return new WP_REST_Response( array( 'result' => array( 'answer' => '合成の回答' ) ), 200 );
	} );
	try { $GLOBALS['hooks']['rest_pre_serve_request']( false, $response ); } finally { ob_end_clean(); }
	check( str_starts_with( $output, "\n{" ), 'RESTでは保活を空行として配信する' );
	check( ! str_contains( $output, 'synthetic-private-marker' ) && ! str_contains( $output, 'run.keepalive' ), '保活に表示情報を含めない' );
	check( str_contains( $output, 'run.completed' ), '保活の後に正式な完了結果を配信する' );
	echo "保活・取消・途中終了・RESTの空行配信を検証しました。\n";
}
