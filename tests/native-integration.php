<?php

// wp eval-file tests/native-integration.php: ローカルの合成データだけを使用します。
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLIのローカル検証専用です。' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$original = get_option( 'fourmix_intelligence_settings', array() );
$original_binding = get_option( 'fourmix_intelligence_bridge_binding', false );
$old_actor = get_current_user_id();
$prefix = 'fmi_synthetic_' . wp_generate_uuid4();
$owner = wp_insert_user( array( 'user_login' => $prefix, 'user_email' => $prefix . '@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => 'contributor' ) );
$other = wp_insert_user( array( 'user_login' => $prefix . '_other', 'user_email' => $prefix . '_other@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => 'contributor' ) );
if ( is_wp_error( $owner ) || is_wp_error( $other ) ) {
	throw new RuntimeException( '合成担当者を作成できませんでした。' );
}
$posts = array();
$GLOBALS['fmi_native_checks'] = 0;
$cleanup = array();
$http_fixture = null;
function native_check( $condition, $label ) {
	++$GLOBALS['fmi_native_checks'];
	if ( ! $condition ) { throw new RuntimeException( '検証失敗: ' . $label ); }
}
function native_request( $action, $params, $nonce = true ) {
	$request = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/staff/' . $action );
	$request->set_header( 'content-type', 'application/json' );
	if ( $nonce ) { $request->set_header( 'x-wp-nonce', wp_create_nonce( 'wp_rest' ) ); }
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}
try {
	update_option( 'fourmix_intelligence_settings', array( 'bridge_groups' => array( 'content' ), 'sync_post_types' => array( 'post', 'page' ) ), false );
	wp_set_current_user( $owner );
	$catalog = native_request( 'catalog', array() );
	native_check( 200 === $catalog->get_status(), '本人の能力一覧' );
	native_check( 403 === native_request( 'catalog', array(), false )->get_status(), '本人でもREST nonceなしでは拒否' );
	native_check( class_exists( 'WooCommerce' ) === $catalog->get_data()['woocommerce'], '実際のWooCommerce導入状態を反映' );
	$preview = native_request( 'preview', array( 'operation' => 'content.create', 'arguments' => array( 'post_type' => 'post', 'title' => $prefix, 'status' => 'draft' ) ) );
	native_check( 'confirmation_required' === $preview->get_data()['state'], '更新のプレビュー' );
	$id = $preview->get_data()['confirmation_id'];
	$session = 'fmi_staff_' . hash_hmac( 'sha256', get_current_blog_id() . ':' . $owner . ':' . wp_get_session_token(), wp_salt( 'auth' ) );
	$cleanup[] = 'fmi_preview_' . hash( 'sha256', $session . $id );
	$cleanup[] = 'fmi_execution_' . hash( 'sha256', $session . ':' . $id );
	$not_approved = native_request( 'confirm', array( 'confirmation_id' => $id, 'approved' => false ) );
	native_check( 422 === $not_approved->get_status(), '確認なしの更新を拒否' );
	wp_set_current_user( $other );
	native_check( 422 === native_request( 'confirm', array( 'confirmation_id' => $id, 'approved' => true ) )->get_status(), '別担当者の確認を拒否' );
	wp_set_current_user( $owner );
	$confirmed = native_request( 'confirm', array( 'confirmation_id' => $id, 'approved' => true ) );
	native_check( 'succeeded' === $confirmed->get_data()['state'], '本人が確認して作成' );
	$post_id = $confirmed->get_data()['data']['id'];
	$posts[] = $post_id;
	native_check( 'draft' === get_post_status( $post_id ), '下書きのまま作成' );
	native_check( $post_id === native_request( 'confirm', array( 'confirmation_id' => $id, 'approved' => true ) )->get_data()['data']['id'], '同じ確認を再実行しない' );
	native_check( 422 === native_request( 'preview', array( 'operation' => 'content.update', 'arguments' => array( 'id' => $post_id, 'status' => 'publish' ) ) )->get_status(), '投稿者の公開権限を継承' );
	$foreign_id = wp_insert_post( array( 'post_title' => $prefix . '_foreign', 'post_author' => $other, 'post_status' => 'draft' ) );
	$posts[] = $foreign_id;
	native_check( 422 === native_request( 'preview', array( 'operation' => 'content.get', 'arguments' => array( 'id' => $foreign_id ) ) )->get_status(), '他人の下書きを拒否' );
	$list = native_request( 'preview', array( 'operation' => 'content.list', 'arguments' => array() ) )->get_data()['data'];
	native_check( ! in_array( $foreign_id, wp_list_pluck( $list, 'id' ), true ), '一覧から他人の下書きを除外' );
	$stale = native_request( 'preview', array( 'operation' => 'content.update', 'arguments' => array( 'id' => $post_id, 'title' => '確認後の合成データ' ) ) )->get_data();
	$cleanup[] = 'fmi_preview_' . hash( 'sha256', $session . $stale['confirmation_id'] );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => '先に変更された合成データ' ) );
	native_check( 422 === native_request( 'confirm', array( 'confirmation_id' => $stale['confirmation_id'], 'approved' => true ) )->get_status(), '古いプレビューを拒否' );
	$bridge_secret = wp_generate_password( 48, false );
	$settings = get_option( 'fourmix_intelligence_settings' );
	$settings['bridge_secret'] = $bridge_secret;
	$settings['bridge_user_id'] = $owner;
	$settings['token'] = 'synthetic-public-token';
	$settings['agent'] = 'synthetic-ai';
	update_option( 'fourmix_intelligence_settings', $settings, false );
	delete_option( 'fourmix_intelligence_bridge_binding' );
	$workspace = wp_generate_uuid4();
	$connection = wp_generate_uuid4();
	$signed = static function ( $target_workspace ) use ( $bridge_secret, $connection, &$cleanup ) {
		$request = new WP_REST_Request( 'GET', '/fourmix-intelligence/v1/manifest' );
		$timestamp = (string) time();
		$nonce = wp_generate_uuid4();
		$cleanup[] = 'fmi_nonce_' . hash( 'sha256', $nonce );
		foreach ( array( 'timestamp' => $timestamp, 'nonce' => $nonce, 'workspace' => $target_workspace, 'connection' => $connection ) as $key => $value ) { $request->set_header( 'x-fourmix-' . $key, $value ); }
		$canonical = implode( "\n", array( $timestamp, $nonce, 'GET', '/wp-json' . $request->get_route(), $target_workspace, $connection, hash( 'sha256', '' ) ) );
		$request->set_header( 'x-fourmix-signature', 'v1=' . hash_hmac( 'sha256', $canonical, $bridge_secret ) );
		return $request;
	};
	$bridge = new FourmixIntelligence\WordPress\Rest\NativeBridgeController();
	$signed_request = $signed( $workspace );
	native_check( true === $bridge->authenticate( $signed_request ), '正しい署名と実行ユーザーの接続' );
	native_check( 'replay' === $bridge->authenticate( $signed_request )->get_error_code(), '署名要求の再利用を拒否' );
	native_check( 'bound' === $bridge->authenticate( $signed( wp_generate_uuid4() ) )->get_error_code(), '別ワークスペースの接続を拒否' );
	$invalid_signature = $signed( $workspace );
	$invalid_signature->set_header( 'x-fourmix-signature', 'v1=invalid' );
	native_check( 'forbidden' === $bridge->authenticate( $invalid_signature )->get_error_code(), '不正署名を拒否' );
	$audience = 'internal';
	$model_calls = 0;
	$http_fixture = static function ( $pre, $args, $url ) use ( &$audience, &$model_calls, $foreign_id ) {
		if ( str_ends_with( $url, '/metadata' ) ) { $body = array( 'audience' => $audience ); }
		else { ++$model_calls; $body = array( 'result' => array( 'answer' => '合成回答', 'data' => array( 'items' => array( array( 'post_id' => $foreign_id ) ) ) ) ); }
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $http_fixture, 10, 3 );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.76';
	$public_request = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/chat' );
	$public_request->set_header( 'origin', home_url() );
	$public_request->set_param( 'message', '合成検証' );
	$public = new FourmixIntelligence\WordPress\Rest\ConversationController();
	native_check( 502 === $public->chat( $public_request )->get_status() && 0 === $model_calls, '社内向けAIの匿名実行をモデル呼び出し前に拒否' );
	$audience = 'customer';
	$reply = $public->chat( $public_request );
	native_check( 200 === $reply->get_status() && array() === $reply->get_data()['result']['data']['items'], 'お客様向け結果から下書きカードを除外' );
	$synchronizer = new FourmixIntelligence\WordPress\Knowledge\Synchronizer();
	$record_method = new ReflectionMethod( $synchronizer, 'record' );
	$record_method->setAccessible( true );
	$sync_record = $record_method->invoke( $synchronizer, array( 'object_type' => 'post', 'object_id' => $foreign_id, 'version' => time(), 'operation' => 'replace' ) );
	native_check( 'delete' === $sync_record['operation'] && ! isset( $sync_record['text'] ), '同期待ちの投稿が下書きになっても本文を送らない' );
	$public_method = new ReflectionMethod( $synchronizer, 'public_content' );
	$public_method->setAccessible( true );
	$password_post = clone get_post( $post_id );
	$password_post->post_status = 'publish';
	$password_post->post_password = 'synthetic-protection';
	native_check( false === $public_method->invoke( $synchronizer, $password_post ), 'パスワード付き公開投稿をお客様向け同期から除外' );
	wp_set_current_user( 0 );
	native_check( 401 === native_request( 'catalog', array(), false )->get_status(), '匿名の管理APIを拒否' );
	echo $GLOBALS['fmi_native_checks'] . " 件のWordPress実環境検証を完了しました。外部AIは呼び出していません。\n";
} finally {
	if ( $http_fixture ) { remove_filter( 'pre_http_request', $http_fixture, 10 ); }
	wp_set_current_user( $old_actor );
	update_option( 'fourmix_intelligence_settings', $original, false );
	if ( false === $original_binding ) { delete_option( 'fourmix_intelligence_bridge_binding' ); } else { update_option( 'fourmix_intelligence_bridge_binding', $original_binding, false ); }
	foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $cleanup as $option ) { delete_option( $option ); wp_clear_scheduled_hook( 'fourmix_intelligence_cleanup_nonce', array( $option ) ); }
	wp_delete_user( $owner );
	wp_delete_user( $other );
}
