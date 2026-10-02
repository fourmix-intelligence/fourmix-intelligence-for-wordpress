<?php

// ローカル実画面の検証用。prepare の JSON は一時ファイルにだけ保存してください。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'ローカルWordPress CLI専用です。' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$name = 'fmi_chat_ui_fixture';
$loader = WP_CONTENT_DIR . '/mu-plugins/fourmix-chat-ui-synthetic.php';
$mode = $args[0] ?? 'inspect';
if ( in_array( $mode, array( 'prepare', 'prepare-rich' ), true ) ) {
	if ( get_option( $name, false ) || file_exists( $loader ) ) { throw new RuntimeException( '前回の検証を片付けてください。' ); }
	add_filter( 'pre_http_request', static fn() => new WP_Error( 'synthetic_external_denied', '合成準備中の外部通信は禁止されています。' ), 1 );
	add_filter( 'pre_wp_mail', static fn() => false );
	$state = array( 'settings' => get_option( 'fourmix_intelligence_settings', array() ), 'expires' => time() + HOUR_IN_SECONDS, 'posts' => array(), 'actor' => 0, 'calls' => array(), 'actions' => array(), 'rich' => 'prepare-rich' === $mode );
	add_option( $name, $state, '', false );
	$prefix = 'fmi_chat_' . wp_generate_uuid4();
	$state['prefix'] = $prefix; update_option( $name, $state, false );
	$state['actor'] = wp_insert_user( array( 'user_login' => $prefix, 'user_email' => $prefix . '@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => 'contributor' ) );
	if ( is_wp_error( $state['actor'] ) ) { throw new RuntimeException( '合成担当者を作成できません。' ); }
	update_option( $name, $state, false );
	update_option( 'fourmix_intelligence_settings', array_merge( $state['settings'], array( 'agent' => 'synthetic-customer', 'token' => 'synthetic-public-chat-token', 'sync_enabled' => false, 'conversation_mode' => 'history', 'bridge_groups' => array( 'content' ), 'sync_post_types' => array( 'post', 'page' ) ) ), false );
	foreach ( array( 'draft' => '合成検証の運営記事', 'publish' => '合成検証の公開相談' ) as $status => $title ) {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_author' => $state['actor'], 'post_status' => $status, 'post_title' => $title, 'post_content' => 'publish' === $status ? '[fourmix_intelligence_chat title="サイトの内容をAIに相談"]' : '送信しない合成本文' ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( '合成記事を作成できません。' ); }
		update_post_meta( $id, '_fourmix_chat_ui_synthetic', $prefix ); $state['posts'][ $status ] = $id; update_option( $name, $state, false );
	}
	$state['prefix'] = $prefix; update_option( $name, $state, false );
	update_option( 'fourmix_intelligence_settings', array_merge( $state['settings'], array( 'agent' => 'synthetic-customer', 'token' => 'synthetic-public-chat-token', 'sync_enabled' => false, 'conversation_mode' => 'history', 'bridge_groups' => array( 'content' ), 'sync_post_types' => array( 'post', 'page' ) ) ), false );
	if ( ! is_dir( dirname( $loader ) ) ) { mkdir( dirname( $loader ) ); }
	$source = "<?php\n// Fourmix Intelligence ローカル合成検証専用。\nrequire WP_PLUGIN_DIR . '/fourmix-intelligence/tests/chat-ui-responder.php';\n";
	if ( false === file_put_contents( $loader, $source ) ) { throw new RuntimeException( '合成応答を読み込めません。' ); }
	$state['loader_hash'] = hash( 'sha256', $source ); update_option( $name, $state, false );
	$expires = time() + 2400; $session = WP_Session_Tokens::get_instance( $state['actor'] )->create( $expires );
	echo wp_json_encode( array( 'draft_id' => $state['posts']['draft'], 'url' => get_permalink( $state['posts']['publish'] ), 'cookies' => array(
		array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $state['actor'], $expires, 'auth', $session ), 'domain' => 'localhost', 'path' => '/', 'httpOnly' => true, 'secure' => false ),
		array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $state['actor'], $expires, 'logged_in', $session ), 'domain' => 'localhost', 'path' => '/', 'httpOnly' => true, 'secure' => false ),
	) ) );
} elseif ( 'expire-personal' === $mode ) {
	$state = get_option( $name );
	$hash = $args[1] ?? '';
	$key = 'fmi_staff_' . $hash;
	if ( ! is_array( $state ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! in_array( '_transient_' . $key, (array) ( $state['options'] ?? array() ), true ) ) { throw new RuntimeException( '検証担当者の短期接続だけを期限切れにできます。' ); }
	delete_transient( $key ); WP_CLI::success( '検証担当者の本人接続を期限切れにしました。' );
} elseif ( 'cleanup' === $mode ) {
	$state = get_option( $name ); if ( ! is_array( $state ) ) { throw new RuntimeException( '検証の保存情報がありません。' ); }
	$current = get_option( 'fourmix_intelligence_settings', array() );
	if ( 'synthetic-public-chat-token' !== ( $current['token'] ?? '' ) || 'synthetic-customer' !== ( $current['agent'] ?? '' ) ) { throw new RuntimeException( '設定が他の作業で変更されています。上書きを中止しました。' ); }
	if ( file_exists( $loader ) && hash_file( 'sha256', $loader ) !== $state['loader_hash'] ) { throw new RuntimeException( '検証用読み込みファイルが変更されています。削除を中止しました。' ); }
	if ( file_exists( $loader ) ) { unlink( $loader ); }
	update_option( 'fourmix_intelligence_settings', $state['settings'], false );
	foreach ( $state['posts'] as $id ) { if ( get_post_meta( $id, '_fourmix_chat_ui_synthetic', true ) === $state['prefix'] ) { wp_delete_post( $id, true ); } }
	$user = get_userdata( $state['actor'] ); if ( $user && $user->user_login === $state['prefix'] ) { wp_delete_user( $user->ID ); }
	foreach ( (array) ( $state['options'] ?? array() ) as $option ) {
		delete_option( $option );
		if ( str_starts_with( $option, 'fmi_execution_' ) ) { wp_clear_scheduled_hook( 'fourmix_intelligence_chat_receipt_expired', array( substr( $option, 14 ) ) ); }
	}
	delete_option( $name ); WP_CLI::success( '合成担当者・記事・短期セッション・検証設定を復元しました。' );
} else {
	$state = (array) get_option( $name ); unset( $state['settings'] ); echo wp_json_encode( $state );
}
