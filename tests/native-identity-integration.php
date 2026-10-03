<?php

// 独立したローカル WordPress のみで実行する合成テストです。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLI の検証専用です。' );
}
use FourmixIntelligence\WordPress\Support\NativeIdentity;
$original = get_option( 'fourmix_intelligence_settings', array() );
$actor = get_current_user_id();
$fixture = wp_create_user( 'identity_' . wp_generate_uuid4(), wp_generate_password(), 'identity_' . wp_generate_uuid4() . '@example.test' );
get_user_by( 'id', $fixture )->set_role( 'administrator' );
wp_set_current_user( $fixture );
$checks = 0;
$check = static function ( $value, $label ) use ( &$checks ) { ++$checks; if ( ! $value ) { throw new RuntimeException( $label ); } };
$identity = array( 'account_id' => 'synthetic-account-a', 'workspace_id' => 'synthetic-workspace', 'connection_id' => 'synthetic-connection', 'role' => 'member', 'business_write' => true );
$status = 200;
$calls = 0;
$hook = static function ( $pre, $args, $url ) use ( &$identity, &$status, &$calls ) {
	if ( str_contains( $url, '/api/v3/native-business/session' ) ) {
		++$calls;
		return array( 'headers' => array(), 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $identity ) );
	}
	if ( str_ends_with( $url, '/api/v3/ai/plugins/metadata' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( array( 'name' => 'synthetic-internal', 'audience' => 'internal' ) ) ) );
	}
	if ( str_ends_with( $url, '/synthetic-internal/metadata' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'tools' => array( array( 'connection_id' => 'synthetic-connection', 'operation_id' => 'content.list' ), array( 'connection_id' => 'synthetic-connection', 'operation_id' => 'content.create' ) ) ) ) );
	}
	return $pre;
};
$request = static function ( $action, $body = array() ) {
	$r = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/staff/' . $action );
	$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$r->set_body_params( $body );
	return rest_do_request( $r );
};
try {
	update_option( 'fourmix_intelligence_settings', array( 'native_connection' => 'synthetic-connection', 'native_tenant' => 'synthetic-tenant', 'bridge_groups' => array( 'content' ) ) );
	$check( 401 === $request( 'catalog' )->get_status(), 'WordPress 管理者でも本人未ログインは拒否' );
	$check( 401 === $request( 'preview', array( 'operation' => 'content.list', 'arguments' => array() ) )->get_status(), '未ログインのローカル情報取得を拒否' );
	NativeIdentity::save( array( 'token' => 'synthetic-session-token', 'workspace_id' => 'synthetic-workspace', 'account_id' => 'synthetic-account-a', 'connection_id' => 'synthetic-connection', 'provider' => 'wordpress' ) );
	add_filter( 'pre_http_request', $hook, 10, 3 );
	$a = NativeIdentity::scope( NativeIdentity::current() );
	$check( ! str_contains( wp_json_encode( get_transient( NativeIdentity::key() ) ), 'synthetic-session-token' ), '本人トークンを暗号化' );
	$captured = NativeIdentity::token();
	NativeIdentity::save( array( 'token' => 'synthetic-other-token', 'workspace_id' => 'synthetic-workspace', 'account_id' => 'synthetic-account-b', 'connection_id' => 'synthetic-connection', 'provider' => 'wordpress' ) );
	try { NativeIdentity::current( false, $captured ); $check( false, '切替前の資格を実行に使わない' ); } catch ( RuntimeException $error ) { $check( 403 === $error->getCode(), '本人切替中の要求は保存範囲と資格を混在させず拒否' ); }
	NativeIdentity::save( array( 'token' => 'synthetic-session-token', 'workspace_id' => 'synthetic-workspace', 'account_id' => 'synthetic-account-a', 'connection_id' => 'synthetic-connection', 'provider' => 'wordpress' ) );
	$check( 200 === $request( 'select', array( 'agent' => 'synthetic-internal' ) )->get_status(), '本人の AI 選択を保存' );
	$identity['account_id'] = 'synthetic-account-b';
	$b = NativeIdentity::scope( NativeIdentity::current() );
	$check( '' === $request( 'catalog' )->get_data()['selected_agent'], '別の本人へ AI 選択を渡さない' );
	$check( $a !== $b, '共用 WordPress アカウントでも本人の保存範囲を分離' );
	$identity['account_id'] = 'synthetic-account-a';
	$check( $a === NativeIdentity::scope( NativeIdentity::current() ), '同じ Fourmix Intelligence アカウントの帰属を維持' );
	$check( 'synthetic-internal' === $request( 'catalog' )->get_data()['selected_agent'], '本人に戻ると AI 選択を復元' );
	$identity['role'] = 'viewer'; $identity['business_write'] = false;
	$check( 200 === $request( 'preview', array( 'agent' => 'synthetic-internal', 'operation' => 'content.list', 'arguments' => array() ) )->get_status(), '読み取り専用メンバーの許可された情報取得を許可' );
	$check( 1 === count( $request( 'catalog' )->get_data()['operations'] ), '読み取り専用の直接操作は読み取りだけ' );
	$check( NativeIdentity::current()['account_id'] === 'synthetic-account-a', '読み取り専用メンバーの本人確認を許可' );
	$check( 403 === $request( 'preview', array( 'operation' => 'content.create', 'arguments' => array( 'post_type' => 'post', 'title' => 'synthetic', 'status' => 'draft' ) ) )->get_status(), '読み取り専用の業務更新を拒否' );
	$check( 403 === $request( 'confirm', array( 'confirmation_id' => wp_generate_uuid4(), 'approved' => true ) )->get_status(), '降権後の古い確認を実行しない' );
	foreach ( array( 'member', 'admin' ) as $role ) {
		$identity['role'] = $role; $identity['business_write'] = true;
		$check( NativeIdentity::current( true )['business_write'], '通常メンバーと管理者の業務権限を同じ二段階で扱う' );
	}
	$status = 403;
	$check( 403 === $request( 'confirm', array() )->get_status(), 'メンバー削除はログイン要求に変えず拒否' );
	$status = 401;
	$check( 401 === $request( 'confirm', array() )->get_status(), '元のログインの失効を即時拒否' );
	$check( false === get_transient( NativeIdentity::key() ), '失効した本人資格を破棄' );
	$check( $calls >= 10, '現在の会員資格を各要求で再確認' );
	WP_CLI::success( 'native identity: ' . $checks . ' checks passed' );
} finally {
	remove_filter( 'pre_http_request', $hook, 10 );
	delete_transient( NativeIdentity::key() );
	wp_set_current_user( $actor );
	update_option( 'fourmix_intelligence_settings', $original );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fixture );
}
