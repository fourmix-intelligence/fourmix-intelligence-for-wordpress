<?php

// 独立したローカルWordPressの合成設定だけを使用する。
use FourmixIntelligence\WordPress\Rest\NativeBridgeController;
use FourmixIntelligence\WordPress\Support\NativeConnectionBinding;
use FourmixIntelligence\WordPress\Support\Options;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! WP_DEBUG || 18193 !== wp_parse_url( home_url(), PHP_URL_PORT ) ) {
	throw new RuntimeException( '独立したローカル検証環境だけで実行してください。' );
}
$names = array( 'fourmix_intelligence_settings', 'fourmix_intelligence_bridge_binding', 'fourmix_intelligence_application_id' );
$saved = array();
foreach ( $names as $name ) { $saved[ $name ] = get_option( $name, null ); }
$actor = get_current_user_id();
$secret = str_repeat( 'synthetic-handshake-', 3 );
$workspace = wp_generate_uuid4();
$connection = wp_generate_uuid4();
$instance = wp_generate_uuid4();
$checks = 0;
$nonces = array();
$check = static function ( bool $value, string $label ) use ( &$checks ): void {
	++$checks;
	if ( ! $value ) { throw new RuntimeException( $label ); }
};
$payload = array( 'schema_version' => 1, 'instance_id' => $instance, 'tenant_id' => 'synthetic-bootstrap', 'workspace_id' => $workspace, 'connection_id' => $connection, 'workspace_name' => '合成ワークスペース', 'platform_url' => 'https://platform.synthetic.test', 'portal_url' => 'https://portal.synthetic.test', 'studio_url' => 'https://studio.synthetic.test' );
$digest = static function ( array $data ): array {
	unset( $data['configuration_id'] ); ksort( $data );
	$data['configuration_id'] = hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return $data;
};
$request = static function ( string $method, string $route, ?array $data = null, ?string $target_workspace = null ) use ( $secret, $workspace, $connection, &$nonces ): WP_REST_Request {
	$r = new WP_REST_Request( $method, '/fourmix-intelligence/v1/' . $route );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( null === $data ? '' : wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	$timestamp = (string) time(); $nonce = wp_generate_uuid4(); $target_workspace ??= $workspace;
	$nonces[] = 'fmi_nonce_' . hash( 'sha256', $nonce );
	foreach ( array( 'timestamp' => $timestamp, 'nonce' => $nonce, 'workspace' => $target_workspace, 'connection' => $connection ) as $key => $value ) { $r->set_header( 'x-fourmix-' . $key, $value ); }
	$canonical = implode( "\n", array( $timestamp, $nonce, $method, '/wp-json' . $r->get_route(), $target_workspace, $connection, hash( 'sha256', $r->get_body() ) ) );
	$r->set_header( 'x-fourmix-signature', 'v1=' . hash_hmac( 'sha256', $canonical, $secret ) );
	return $r;
};
try {
	$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	$check( ! empty( $users ), '合成実行ユーザー' );
	update_option( 'fourmix_intelligence_settings', array( 'bridge_secret' => $secret, 'bridge_user_id' => $users[0]->ID, 'bridge_groups' => array( 'content' ) ), false );
	update_option( 'fourmix_intelligence_application_id', $instance, false );
	delete_option( 'fourmix_intelligence_bridge_binding' );
	rest_get_server();
	$check( 200 === rest_do_request( $request( 'GET', 'manifest' ) )->get_status(), '署名済み機能一覧' );
	$check( ! get_option( 'fourmix_intelligence_bridge_binding' ), '探測だけでは接続を固定しない' );
	$bad = $digest( array_replace( $payload, array( 'platform_url' => 'https://user:password@platform.synthetic.test' ) ) );
	$check( 422 === rest_do_request( $request( 'PUT', 'connection', $bad ) )->get_status(), '不正URLを拒否' );
	$check( ! get_option( 'fourmix_intelligence_bridge_binding' ), '失敗で部分設定を残さない' );
	$bad = $request( 'PUT', 'connection', $digest( $payload ) );
	$bad->set_header( 'x-fourmix-signature', 'v1=invalid' );
	$check( 403 === rest_do_request( $bad )->get_status(), '不正署名を拒否' );
	$check( 409 === rest_do_request( $request( 'PUT', 'connection', $digest( array_replace( $payload, array( 'instance_id' => wp_generate_uuid4() ) ) ) ) )->get_status(), '別インスタンスを拒否' );
	$check( 403 === rest_do_request( $request( 'PUT', 'connection', $digest( array_replace( $payload, array( 'workspace_id' => str_repeat( '-', 36 ) ) ) ), str_repeat( '-', 36 ) ) )->get_status(), '不正workspace識別子を拒否' );
	$good = $request( 'PUT', 'connection', $digest( $payload ) );
	$check( 200 === rest_do_request( $good )->get_status(), '初回接続' );
	$check( 403 === rest_do_request( $good )->get_status(), '署名リプレイを拒否' );
	$before = get_option( 'fourmix_intelligence_bridge_binding' );
	$check( $payload['connection_id'] === Options::get( 'native_connection' ) && $payload['portal_url'] === Options::get( 'portal_url' ), '信頼済み設定の自動利用' );
	$check( 200 === rest_do_request( $request( 'PUT', 'connection', $digest( $payload ) ) )->get_status(), '再接続の冪等性' );
	$check( $before === get_option( 'fourmix_intelligence_bridge_binding' ), '再接続で設定を変えない' );
	$check( 409 === rest_do_request( $request( 'PUT', 'connection', $digest( array_replace( $payload, array( 'portal_url' => 'https://other.synthetic.test' ) ) ) ) )->get_status(), '別環境の上書きを拒否' );
	$check( 403 === rest_do_request( $request( 'GET', 'manifest', null, wp_generate_uuid4() ) )->get_status(), '別workspaceを拒否' );
	$check( $before === get_option( 'fourmix_intelligence_bridge_binding' ), '拒否後も接続維持' );
	$check( NativeConnectionBinding::status()['configuration_id'] === $digest( $payload )['configuration_id'], '不明結果を状態取得で確認' );
	$sanitized = ( new \FourmixIntelligence\WordPress\Admin\SettingsPage() )->sanitize( array( 'native_connection' => 'forged', 'portal_url' => 'https://other.synthetic.test' ) );
	$check( 'forged' !== $sanitized['native_connection'] && 'https://other.synthetic.test' !== $sanitized['portal_url'], '画面入力で内部設定を上書きできない' );
	ob_start(); ( new \FourmixIntelligence\WordPress\Admin\SettingsPage() )->render(); $html = ob_get_clean();
	$check( ! str_contains( $html, 'name="fourmix_intelligence_settings[native_tenant]"' ) && ! str_contains( $html, 'name="fourmix_intelligence_settings[native_connection]"' ), '内部IDの手入力を表示しない' );
	delete_option( 'fourmix_intelligence_bridge_binding' );
	$settings = get_option( 'fourmix_intelligence_settings' );
	$settings['native_connection'] = $connection; $settings['native_tenant'] = 'legacy-other-environment';
	update_option( 'fourmix_intelligence_settings', $settings, false );
	$check( 409 === rest_do_request( $request( 'PUT', 'connection', $digest( $payload ) ) )->get_status(), '旧設定の接続先変更を拒否' );
	$settings['native_tenant'] = $payload['tenant_id']; update_option( 'fourmix_intelligence_settings', $settings, false );
	$check( 200 === rest_do_request( $request( 'PUT', 'connection', $digest( $payload ) ) )->get_status(), '同じ旧接続のアップグレード' );
	$settings['bridge_user_id'] = 0; update_option( 'fourmix_intelligence_settings', $settings, false ); delete_option( 'fourmix_intelligence_bridge_binding' );
	$check( 403 === rest_do_request( $request( 'PUT', 'connection', $digest( $payload ) ) )->get_status() && ! get_option( 'fourmix_intelligence_bridge_binding' ), '実行ユーザー未設定では固定しない' );
	$settings['bridge_user_id'] = $users[0]->ID; update_option( 'fourmix_intelligence_settings', $settings, false );
	$pending = $request( 'PUT', 'connection', $digest( $payload ) );
	$check( true === ( new NativeBridgeController() )->authenticate( $pending ), '更新直前の署名を確認' );
	$changed = ( new \FourmixIntelligence\WordPress\Admin\SettingsPage() )->sanitize( array( 'bridge_secret' => str_repeat( 'new-synthetic-key-', 3 ), 'bridge_user_id' => $users[0]->ID, 'bridge_groups' => array( 'content' ) ) );
	update_option( 'fourmix_intelligence_settings', $changed, false );
	$rejected = NativeConnectionBinding::configure( $pending );
	$check( is_wp_error( $rejected ) && 409 === $rejected->get_error_data()['status'], '署名確認後のキー変更を保存直前に拒否' );
	update_option( 'fourmix_intelligence_bridge_binding', $before, false );
	$check( ! NativeConnectionBinding::status()['connected'] && '' === Options::get( 'native_connection', '' ), '旧キーの遅延保存を本人確認に使わない' );
	echo wp_json_encode( array( 'checks' => $checks, 'status' => 'passed' ) ) . "\n";
} finally {
	foreach ( $saved as $name => $value ) { null === $value ? delete_option( $name ) : update_option( $name, $value, false ); }
	foreach ( $nonces as $nonce ) { delete_option( $nonce ); }
	wp_set_current_user( $actor );
}
