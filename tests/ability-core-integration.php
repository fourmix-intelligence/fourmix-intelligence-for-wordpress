<?php

// 本タスクの独立 Docker のみ。認証・Studio・会話は実 API、モデルだけを固定応答にします。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLI の検証専用です。' );
}
use FourmixIntelligence\WordPress\Rest\StaffController;
use FourmixIntelligence\WordPress\Support\NativeIdentity;
use FourmixIntelligence\WordPress\Support\Options;

if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || 'identity_fixture' !== Options::get( 'native_tenant', '' ) || 'http://platform:8000' !== Options::get( 'platform_url', '' ) || 'http://codex-wpodoo-model-seam:8080' !== Options::get( 'url', '' ) || 18193 !== wp_parse_url( home_url(), PHP_URL_PORT ) ) {
	throw new RuntimeException( '本タスクの独立 Docker 以外では実行しません。' );
}
$health = json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://codex-wpodoo-model-seam:8080/healthz' ) ), true );
if ( 'offline' !== ( $health['model'] ?? '' ) || 'production' !== ( $health['authorization'] ?? '' ) ) {
	throw new RuntimeException( 'モデルだけを置き換えた独立 API が必要です。' );
}
$actor   = get_current_user_id();
$fixture = wp_create_user( 'ability_core_' . wp_generate_uuid4(), wp_generate_password(), 'ability_core_' . wp_generate_uuid4() . '@example.test' );
get_user_by( 'id', $fixture )->set_role( 'administrator' );
wp_set_current_user( $fixture );
$source   = '';
$scopes   = array();
$receipts = array();
$calls    = 0;
$checks   = 0;
$check = static function ( $value, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
};
$api = static function ( $path, $body, $token = '' ) {
	$reply = wp_remote_request( 'http://platform:8000' . $path, array( 'method' => 'POST', 'timeout' => 30, 'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token ), 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $reply ) || wp_remote_retrieve_response_code( $reply ) >= 300 ) {
		throw new RuntimeException( '合成 API の失敗: ' . $path . ' / ' . wp_remote_retrieve_response_code( $reply ) );
	}
	return 204 === wp_remote_retrieve_response_code( $reply ) ? array() : json_decode( wp_remote_retrieve_body( $reply ), true );
};
$watch = static function ( $pre, $args, $url ) use ( &$calls ) {
	if ( str_ends_with( $url, '/identity-wordpress/runs' ) ) {
		++$calls;
	}
	return $pre; // 実 HTTP 応答は置き換えない。
};
$record = static function ( $name ) use ( &$receipts ) {
	if ( str_starts_with( $name, 'fmi_execution_' ) ) {
		$receipts[] = $name;
	}
};
try {
	add_filter( 'pre_http_request', $watch, 10, 3 );
	add_action( 'added_option', $record );
	$ability = wp_get_ability( 'fourmix-intelligence/ask-agent' );
	$check( is_wp_error( $ability->execute( array( 'message' => '本人未ログインの合成質問' ) ) ), '実登録入口でも WordPress 管理者だけでは実行しない。' );
	$login = $api( '/api/v3/auth/login', array( 'tenant' => 'identity_fixture', 'email' => 'member@identity.example.test', 'password' => 'Synthetic-Identity-20261003!', 'otp' => getenv( 'FOURMIX_ABILITY_RECOVERY_CODE' ) ) );
	$source = $login['token'];
	$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	$input = array( 'tenant' => 'identity_fixture', 'connection_id' => Options::get( 'native_connection', '' ), 'redirect_uri' => NativeIdentity::callback_url(), 'state' => bin2hex( random_bytes( 32 ) ), 'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
	$api( '/api/v3/native-business/preview', $input, $source );
	$grant = $api( '/api/v3/native-business/authorize', $input, $source );
	parse_str( wp_parse_url( $grant['redirect_uri'], PHP_URL_QUERY ), $callback );
	$identity = $api( '/api/v3/native-business/identity_fixture/' . $input['connection_id'] . '/exchange', array( 'code' => $callback['code'], 'redirect_uri' => $input['redirect_uri'], 'code_verifier' => $verifier ) );
	NativeIdentity::save( $identity );
	$current = NativeIdentity::current();
	$scopes[] = NativeIdentity::scope( $current ) . '_' . hash( 'sha256', 'identity-wordpress' );
	$check( $current['account_id'] === $identity['account_id'], '実 FI ログインの現在の本人と共有接続を確認する。' );
	$request = new WP_REST_Request( 'POST' );
	$request->set_param( 'agent', 'identity-wordpress' );
	$check( 200 === ( new StaffController() )->select( $request )->get_status(), '実 Studio 一覧で共有接続の AI を選択する。' );
	$answer = $ability->execute( array( 'message' => '外部モデルを使わない登録入口の合成質問' ) );
	$check( ! is_wp_error( $answer ) && str_contains( $answer['answer'], '隔離検証の回答' ), '実 PHP/Python・会話保存・モデル置換で登録入口が成功する。' );
	$check( 1 === $calls, '登録入口からモデル置換 API へ一度だけ送信する。' );
	$check( ! empty( get_transient( $scopes[0] )['conversation_id'] ), '現在の本人の会話 ID を実応答から保存する。' );
	$api( '/api/v3/auth/logout', array(), $source );
	$source = '';
	$revoked = $ability->execute( array( 'message' => '元の FI ログインを終了した後の質問' ) );
	$check( is_wp_error( $revoked ) && 401 === $revoked->get_error_data()['status'], '元の FI ログアウト後は登録入口でも新しい実行を拒否する。' );
	$check( 1 === $calls, '元のログアウト後にモデル置換 API へ再送しない。' );
	WP_CLI::success( 'ability real core: ' . $checks . ' checks passed' );
} finally {
	if ( $source ) {
		$api( '/api/v3/auth/logout', array(), $source );
	}
	remove_filter( 'pre_http_request', $watch, 10 );
	remove_action( 'added_option', $record );
	foreach ( $receipts as $name ) {
		delete_option( $name );
		wp_clear_scheduled_hook( 'fourmix_intelligence_chat_receipt_expired', array( substr( $name, strlen( 'fmi_execution_' ) ) ) );
	}
	foreach ( $scopes as $scope ) {
		delete_transient( $scope );
	}
	delete_transient( NativeIdentity::key() );
	wp_set_current_user( $actor );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fixture );
}
