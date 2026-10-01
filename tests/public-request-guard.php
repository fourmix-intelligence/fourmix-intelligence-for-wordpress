<?php

// 公開APIの境界だけを隔離して確認し、実サイトのDBと外部AIを使用しない。
use FourmixIntelligence\WordPress\Rest\ConversationController;
use FourmixIntelligence\WordPress\Support\PublicRequestGuard;

require __DIR__ . '/../src/Support/PublicRequestGuard.php';
require __DIR__ . '/../src/Support/Options.php';
require __DIR__ . '/../src/Rest/ConversationController.php';

function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url() { return 'https://example.test/store'; }
function __( $text, $domain ) { return $text; }
function wp_salt( $scheme ) { return 'isolated-test-salt'; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return (string) $value; }
function sanitize_textarea_field( $value ) { return (string) $value; }
function get_option( $key, $fallback = null ) { return $fallback; }
function get_transient( $key ) { return $GLOBALS['counts'][ $key ] ?? 0; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['counts'][ $key ] = $value; }
function do_action( $hook, $reason ) { $GLOBALS['reasons'][] = $reason; }
define( 'MINUTE_IN_SECONDS', 60 );
define( 'FOURMIX_INTELLIGENCE_TRUSTED_PROXIES', array( '10.0.0.0/24' ) );

class WP_REST_Request {
    public function __construct( private array $headers ) {}
    public function get_header( $key ) { return $this->headers[ $key ] ?? ''; }
    public function get_param( $key ) { return null; }
}
class WP_REST_Response {
    public function __construct( public mixed $data, public int $status ) {}
}

$checks = 0;
function check( $expected, $actual ) {
    global $checks;
    ++$checks;
    if ( $expected !== $actual ) { throw new RuntimeException( '検証失敗: ' . var_export( array( $expected, $actual ), true ) ); }
}

foreach ( array( 'https://example.test', 'HTTPS://EXAMPLE.TEST:443' ) as $origin ) {
    check( true, PublicRequestGuard::same_origin( $origin, home_url() ) );
}
check( true, PublicRequestGuard::same_origin( 'http://localhost:45180', 'http://localhost:45180/subdir' ) );
foreach ( array( '', 'null', 'http://example.test', 'https://example.test:444', 'https://user@example.test', 'https://example.test/', 'https://example.test?q=1', 'https://example.test#x', 'https://example.test,https://other.test', 'https://example.test\\@other.test' ) as $origin ) {
    check( false, PublicRequestGuard::same_origin( $origin, home_url() ) );
}
check( '198.51.100.1', PublicRequestGuard::client_ip( '198.51.100.1', '203.0.113.99', array() ) );
check( '198.51.100.1', PublicRequestGuard::client_ip( '10.0.0.2', '198.51.100.1, 10.0.0.1', array( '10.0.0.0/24' ) ) );
check( '198.51.100.1', PublicRequestGuard::client_ip( '10.0.0.2', '203.0.113.99, 198.51.100.1', array( '10.0.0.0/24' ) ) );
check( '10.0.1.1', PublicRequestGuard::client_ip( '10.0.1.1', '203.0.113.99', array( '10.0.0.0/24', 'invalid/99' ) ) );
check( '2001:db8:1::1', PublicRequestGuard::client_ip( '2001:db8:2::2', '2001:db8:1::1', array( '2001:db8:2::/64' ) ) );
foreach ( array( 'garbage', '198.51.100.1:1234', str_repeat( 'a', 2049 ), implode( ',', array_fill( 0, 33, '198.51.100.1' ) ) ) as $header ) {
    check( null, PublicRequestGuard::client_ip( '10.0.0.2', $header, array( '10.0.0.0/24' ) ) );
}
check( null, PublicRequestGuard::client_ip( '', '', array() ) );

$controller = new ConversationController();
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
foreach ( array( 'chat', 'history' ) as $endpoint ) {
    check( 403, $controller->$endpoint( new WP_REST_Request( array( 'origin' => 'http://example.test' ) ) )->status );
    check( 'origin_mismatch', end( $GLOBALS['reasons'] ) );
    $GLOBALS['counts'][ 'fmi_rate_' . hash_hmac( 'sha256', inet_pton( '198.51.100.1' ), wp_salt( 'nonce' ) ) ] = 30;
    check( 429, $controller->$endpoint( new WP_REST_Request( array( 'origin' => 'https://example.test' ) ) )->status );
    check( 'rate_limited', end( $GLOBALS['reasons'] ) );
}
$GLOBALS['counts'] = array();
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
check( 422, $controller->chat( new WP_REST_Request( array( 'origin' => 'https://example.test', 'x-forwarded-for' => '198.51.100.1' ) ) )->status );
$GLOBALS['counts'][ 'fmi_rate_' . hash_hmac( 'sha256', inet_pton( '198.51.100.1' ), wp_salt( 'nonce' ) ) ] = 30;
check( 429, $controller->chat( new WP_REST_Request( array( 'origin' => 'https://example.test', 'x-forwarded-for' => '198.51.100.1' ) ) )->status );
check( 422, $controller->chat( new WP_REST_Request( array( 'origin' => 'https://example.test', 'x-forwarded-for' => '198.51.100.2' ) ) )->status );
echo $checks . " checks passed\n";
