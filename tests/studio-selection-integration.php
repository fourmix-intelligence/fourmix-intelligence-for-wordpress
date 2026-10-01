<?php

// 既存のローカルWordPressで、モデルを呼ばず本人接続と設定保存を検証します。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'ローカルWordPress CLI検証専用です。' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$original = get_option( 'fourmix_intelligence_settings', array() );
$actor = get_current_user_id();
$prefix = 'fmi_studio_' . wp_generate_uuid4();
$owner = wp_insert_user( array( 'user_login' => $prefix, 'user_email' => $prefix . '@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => 'contributor' ) );
$checks = 0;
$catalog = array( array( 'name' => 'internal-ai', 'audience' => 'internal' ), array( 'name' => 'second-ai', 'audience' => 'internal' ), array( 'name' => 'customer-ai', 'audience' => 'customer' ) );
$http = static function ( $reply, $args, $url ) use ( &$catalog ) {
	if ( ! str_ends_with( $url, '/api/v3/ai/plugins/metadata' ) ) { throw new RuntimeException( 'モデルや外部サービスを呼び出す検証は禁止されています。' ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $catalog ), 'response' => array( 'code' => 200 ) );
};
$request = static function ( $action, $params ) {
	$r = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/staff/' . $action );
	$r->set_header( 'content-type', 'application/json' ); $r->set_header( 'x-wp-nonce', wp_create_nonce( 'wp_rest' ) );
	$r->set_body( wp_json_encode( $params ) ); return rest_do_request( $r );
};
$check = static function ( $condition, $label ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( '検証失敗: ' . $label ); } };
add_filter( 'pre_http_request', $http, 5, 3 );
try {
	if ( is_wp_error( $owner ) ) { throw new RuntimeException( '合成担当者を作成できません。' ); }
	wp_set_current_user( $owner );
	update_option( 'fourmix_intelligence_settings', array( 'bridge_groups' => array( 'content' ), 'sync_post_types' => array( 'post', 'page' ), 'sync_enabled' => false, 'token' => 'synthetic-public-token' ), false );
	$check( 200 === $request( 'connect', array( 'token' => 'synthetic-personal-token' ) )->get_status(), '社内Studio一覧へ接続' );
	$check( 200 === $request( 'select', array( 'agent' => 'second-ai' ) )->get_status(), '送信なしで選択を保存' );
	$data = $request( 'catalog', array() )->get_data();
	$check( 'second-ai' === $data['selected_agent'], '同じ本人セッションで選択を復元' );
	$check( ! array_diff( wp_list_pluck( $data['operations'], 'domain' ), array( 'content' ) ), '選択しても未許可の能力を増やさない' );
	$check( 422 === $request( 'select', array( 'agent' => 'customer-ai' ) )->get_status(), 'お客様向けAIを社内接続に使えない' );
	$page = new FourmixIntelligence\WordPress\Admin\SettingsPage();
	$saved = $page->sanitize( array( 'agent' => 'customer-ai', 'token' => 'synthetic-public-token', 'bridge_groups' => array( 'content' ) ) );
	$check( 'customer-ai' === $saved['agent'], '許可されたお客様向けAIを設定に保存' );
	update_option( 'fourmix_intelligence_settings', $saved, false );
	$check( 'customer-ai' === get_option( 'fourmix_intelligence_settings' )['agent'], '公開AIの設定を復元' );
	$check( 'customer-ai' === $page->sanitize( array( 'agent' => 'internal-ai' ) )['agent'], '社内AIへの公開設定変更を拒否して以前の設定を保持' );
	$catalog = array( array( 'name' => 'internal-ai', 'audience' => 'internal' ) );
	$check( '' === $request( 'catalog', array() )->get_data()['selected_agent'], 'Studioで許可を撤回したAIは復元しない' );
	wp_set_current_user( 0 );
	$check( in_array( $request( 'catalog', array() )->get_status(), array( 401, 403 ), true ), '匿名訪問者を拒否' );
	WP_CLI::success( 'Studio選択・設定保存: ' . $checks . ' 件の検証が成功しました。カタログは合成応答、RESTと保存は実WordPressです。' );
} finally {
	remove_filter( 'pre_http_request', $http, 5 );
	wp_set_current_user( $owner );
	$session = 'fmi_staff_' . hash_hmac( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . wp_get_session_token(), wp_salt( 'auth' ) );
	delete_transient( $session );
	wp_set_current_user( $actor );
	if ( ! is_wp_error( $owner ) ) { wp_delete_user( $owner ); }
	update_option( 'fourmix_intelligence_settings', $original, false );
}
