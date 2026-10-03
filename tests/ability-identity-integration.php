<?php

// 独立した WordPress の合成データだけで、Ability の選択元を検証します。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLI の検証専用です。' );
}
use FourmixIntelligence\WordPress\Abilities\AbilityIntegration;
use FourmixIntelligence\WordPress\Rest\StaffController;
use FourmixIntelligence\WordPress\Support\NativeIdentity;

$original = get_option( 'fourmix_intelligence_settings', array() );
$actor    = get_current_user_id();
$fixture  = wp_create_user( 'ability_' . wp_generate_uuid4(), wp_generate_password(), 'ability_' . wp_generate_uuid4() . '@example.test' );
get_user_by( 'id', $fixture )->set_role( 'administrator' );
wp_set_current_user( $fixture );
$checks   = 0;
$reads    = array();
$receipts = array();
$status   = 200;
$identity = array( 'account_id' => 'account-a', 'workspace_id' => 'workspace-one', 'connection_id' => 'ability-connection', 'business_write' => true );
$agents   = array( array( 'name' => 'team-a', 'audience' => 'internal' ), array( 'name' => 'team-b', 'audience' => 'internal' ) );
$check    = static function ( $value, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
};
$denied = static function ( $code ) use ( $check ) {
	try {
		( new StaffController() )->selected_agent();
		$check( false, '拒否すべき選択を返しました。' );
	} catch ( RuntimeException $error ) {
		$check( $code === $error->getCode(), '現在の本人資格と利用可能な AI を確認する。' );
	}
};
$hook = static function ( $pre, $args, $url ) use ( &$identity, &$status, &$agents ) {
	if ( str_contains( $url, '/api/v3/native-business/session' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $identity ) );
	}
	if ( str_ends_with( $url, '/api/v3/ai/plugins/metadata' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $agents ) );
	}
	throw new RuntimeException( 'このテストはモデルや外部業務を呼び出しません。' );
};
$watch = static function ( $value, $id, $key ) use ( &$reads, $fixture ) {
	if ( $fixture === $id ) {
		$reads[] = $key;
	}
	return $value;
};
$record = static function ( $name ) use ( &$receipts ) {
	if ( str_starts_with( $name, 'fmi_execution_' ) ) {
		$receipts[] = $name;
	}
};
$select = static function ( $agent ) {
	$request = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/staff/select' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params( array( 'agent' => $agent ) );
	return rest_do_request( $request );
};
try {
	update_option( 'fourmix_intelligence_settings', array( 'native_connection' => 'ability-connection', 'native_tenant' => 'ability-tenant' ) );
	update_user_meta( $fixture, 'fourmix_intelligence_internal_agent', 'team-b' );
	add_filter( 'pre_http_request', $hook, 10, 3 );
	add_filter( 'get_user_metadata', $watch, 10, 3 );
	add_action( 'added_option', $record );
	$denied( 401 );
	$check( is_wp_error( ( new AbilityIntegration() )->ask( array( 'message' => '未ログインの合成依頼' ) ) ), 'Ability も未ログインで拒否する。' );
	NativeIdentity::save( array( 'token' => 'synthetic-ability-token', 'account_id' => 'account-a', 'connection_id' => 'ability-connection', 'provider' => 'wordpress' ) );
	$denied( 403 ); // 旧キーが存在しても、現在の本人の選択には流用しない。
	$check( 200 === $select( 'team-a' )->get_status(), 'アカウント A の選択を保存する。' );
	$check( 'team-a' === ( new StaffController() )->selected_agent(), 'A は現在許可された A の選択を取得する。' );
	$key_a = 'fourmix_intelligence_internal_agent_' . hash( 'sha256', NativeIdentity::scope( $identity ) );
	$reads = array();
	( new AbilityIntegration() )->ask( array( 'message' => 'A の合成依頼' ) );
	$check( in_array( $key_a, $reads, true ), 'Ability は A の検証済み選択を読む。' );
	$check( ! in_array( 'fourmix_intelligence_internal_agent', $reads, true ), 'Ability は旧共用キーを読まない。' );
	$identity['account_id'] = 'account-b';
	$denied( 403 );
	$check( 200 === $select( 'team-b' )->get_status(), '同じ WordPress ユーザーで B の別の AI を保存する。' );
	$check( 'team-b' === ( new StaffController() )->selected_agent(), 'B は B の選択を取得する。' );
	$key_b = 'fourmix_intelligence_internal_agent_' . hash( 'sha256', NativeIdentity::scope( $identity ) );
	$reads = array();
	( new AbilityIntegration() )->ask( array( 'message' => 'B の合成依頼' ) );
	$check( in_array( $key_b, $reads, true ) && ! in_array( $key_a, $reads, true ), 'Ability は A の選択を B に渡さない。' );
	$check( ! in_array( 'fourmix_intelligence_internal_agent', $reads, true ), 'B でも旧キーを代わりに使わない。' );
	$identity['account_id'] = 'account-a';
	$check( 'team-a' === ( new StaffController() )->selected_agent(), 'A に戻ると元の選択を復元する。' );
	$agents = array( array( 'name' => 'team-b', 'audience' => 'internal' ) );
	$denied( 403 ); // AI の公開や許可が変わったとき、古い選択は利用できない。
	$identity['connection_id'] = 'different-connection';
	$denied( 403 );
	$identity['connection_id'] = 'ability-connection';
	$status = 403;
	$denied( 403 ); // FI 側の現在のワークスペース資格を尊重する。
	$status = 401;
	$denied( 401 );
	$check( false === get_transient( NativeIdentity::key() ), '失効した資格を残さない。' );
	WP_CLI::success( 'ability identity: ' . $checks . ' checks passed' );
} finally {
	remove_filter( 'pre_http_request', $hook, 10 );
	remove_filter( 'get_user_metadata', $watch, 10 );
	remove_action( 'added_option', $record );
	foreach ( $receipts as $name ) {
		delete_option( $name );
		wp_clear_scheduled_hook( 'fourmix_intelligence_chat_receipt_expired', array( substr( $name, strlen( 'fmi_execution_' ) ) ) );
	}
	foreach ( array( 'account-a', 'account-b' ) as $account ) {
		$scope_identity = array_replace( $identity, array( 'account_id' => $account ) );
		foreach ( array( 'team-a', 'team-b' ) as $agent ) {
			delete_transient( NativeIdentity::scope( $scope_identity ) . '_' . hash( 'sha256', $agent ) );
		}
	}
	delete_transient( NativeIdentity::key() );
	wp_set_current_user( $actor );
	update_option( 'fourmix_intelligence_settings', $original );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fixture );
}
