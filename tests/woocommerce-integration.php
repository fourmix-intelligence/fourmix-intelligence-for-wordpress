<?php

// 合成データベースでのみ実行します。外部通信、メール、決済、公開は行いません。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! str_starts_with( DB_NAME, 'fourmix_woo_synthetic_' ) || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'WooCommerce導入済みの合成検証専用です。' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$original = get_option( 'fourmix_intelligence_settings', array() );
$prefix = 'fmi_woo_' . wp_generate_uuid4();
$users = array();
$products = array();
$order = null;
$checks = 0;
$request = static function ( $action, $params ) {
	$r = new WP_REST_Request( 'POST', '/fourmix-intelligence/v1/staff/' . $action );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_header( 'x-wp-nonce', wp_create_nonce( 'wp_rest' ) );
	$r->set_body( wp_json_encode( $params ) );
	return rest_do_request( $r );
};
$check = static function ( $condition, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( '検証失敗: ' . $label ); }
};
$catalog = array( array( 'name' => 'staff-ai', 'audience' => 'internal' ), array( 'name' => 'second-ai', 'audience' => 'internal' ), array( 'name' => 'customer-ai', 'audience' => 'customer' ) );
$http = static function ( $reply, $args, $url ) use ( &$catalog ) {
	if ( str_ends_with( $url, '/api/v3/ai/plugins/metadata' ) ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $catalog ), 'response' => array( 'code' => 200 ) );
	}
	return $reply;
};
add_filter( 'pre_http_request', $http, 5, 3 );
try {
	foreach ( array( 'shop_manager', 'contributor', 'customer' ) as $role ) {
		$id = wp_insert_user( array( 'user_login' => $prefix . $role, 'user_email' => $prefix . $role . '@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => $role ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( '合成担当者を作成できません。' ); }
		$users[$role] = $id;
	}
	update_option( 'fourmix_intelligence_settings', array( 'bridge_groups' => array( 'products', 'orders', 'customers', 'coupons' ), 'sync_enabled' => false ), false );
	wp_set_current_user( $users['shop_manager'] );
	$bridge = new FourmixIntelligence\WordPress\Rest\NativeBridgeController();
	$names = wp_list_pluck( $bridge->capabilities(), 'name' );
	$check( ! array_diff( array( 'products.get', 'products.save', 'orders.get', 'orders.update_status', 'customers.get' ), $names ), '導入済みWooCommerceと担当者の権限から能力を検出' );
	$product = new WC_Product_Simple();
	$product->set_name( $prefix );
	$product->set_status( 'draft' );
	$product->set_regular_price( '500' );
	$products[] = $product->save();
	$check( $product->get_id() === $bridge->perform( 'products.get', array( 'id' => $product->get_id() ) )['id'], '商品を本人の権限で参照' );
	$check( in_array( $product->get_id(), wp_list_pluck( $bridge->perform( 'products.list', array() ), 'id' ), true ), '商品一覧' );
	$order = wc_create_order( array( 'customer_id' => $users['customer'], 'status' => 'pending' ) );
	$order->add_product( $product, 2 );
	$order->calculate_totals();
	$check( $order->get_id() === $bridge->perform( 'orders.get', array( 'id' => $order->get_id() ) )['id'], '実際の注文データを参照' );
	$check( in_array( $order->get_id(), wp_list_pluck( $bridge->perform( 'orders.list', array() ), 'id' ), true ), '注文一覧' );
	$check( $users['customer'] === $bridge->perform( 'customers.get', array( 'id' => $users['customer'] ) )['id'], '顧客を参照' );
	$check( array( 'id', 'name' ) === array_keys( $bridge->perform( 'customers.get', array( 'id' => $users['customer'] ) ) ), '顧客のメール・住所・内部属性を公開しない' );
	$check( 422 === $request( 'preview', array( 'operation' => 'customers.get', 'arguments' => array( 'id' => $users['shop_manager'] ) ) )->get_status(), '店舗担当者を顧客として読めない' );
	$preview = $request( 'preview', array( 'operation' => 'products.save', 'arguments' => array( 'id' => $product->get_id(), 'name' => $prefix . '_updated', 'regular_price' => '700', 'stock_quantity' => 5 ) ) )->get_data();
	$check( 'confirmation_required' === ( $preview['state'] ?? '' ) && '500' === wc_get_product( $product->get_id() )->get_regular_price(), 'プレビューで商品を変更しない' );
	$check( 422 === $request( 'confirm', array( 'confirmation_id' => $preview['confirmation_id'], 'approved' => false ) )->get_status(), '明示確認が必要' );
	$first = $request( 'confirm', array( 'confirmation_id' => $preview['confirmation_id'], 'approved' => true ) )->get_data();
	$check( 'succeeded' === $first['state'] && '700' === wc_get_product( $product->get_id() )->get_regular_price(), '商品を確認後に更新' );
	$check( $first === $request( 'confirm', array( 'confirmation_id' => $preview['confirmation_id'], 'approved' => true ) )->get_data(), '同じ商品更新を再実行しない' );
	$stale = $request( 'preview', array( 'operation' => 'products.save', 'arguments' => array( 'id' => $product->get_id(), 'name' => '古い確認' ) ) )->get_data();
	$product = wc_get_product( $product->get_id() ); $product->set_name( '別担当者の更新' ); $product->save();
	$check( 422 === $request( 'confirm', array( 'confirmation_id' => $stale['confirmation_id'], 'approved' => true ) )->get_status(), '商品が変わったら再プレビュー' );
	$preview = $request( 'preview', array( 'operation' => 'orders.update_status', 'arguments' => array( 'id' => $order->get_id(), 'status' => 'on-hold', 'note' => '合成データの確認' ) ) )->get_data();
	$check( 'pending' === wc_get_order( $order->get_id() )->get_status(), '注文プレビューで副作用なし' );
	$result = $request( 'confirm', array( 'confirmation_id' => $preview['confirmation_id'], 'approved' => true ) )->get_data();
	$check( 'succeeded' === $result['state'] && 'on-hold' === wc_get_order( $order->get_id() )->get_status(), '注文状態を確認後に更新' );
	$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	$request( 'confirm', array( 'confirmation_id' => $preview['confirmation_id'], 'approved' => true ) );
	$check( $note_count === count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ), '再確認で注文メモを増やさない' );
	$check( 422 === $request( 'preview', array( 'operation' => 'orders.update_status', 'arguments' => array( 'id' => $order->get_id(), 'status' => 'invented' ) ) )->get_status(), '存在しない状態を拒否' );
	$check( 200 === $request( 'connect', array( 'token' => 'synthetic-personal-token' ) )->get_status(), 'モデルを呼ばずStudioカタログへ接続' );
	$check( 200 === $request( 'select', array( 'agent' => 'second-ai' ) )->get_status(), '選択時に保存' );
	$check( 'second-ai' === $request( 'catalog', array() )->get_data()['selected_agent'], '画面再読込でAI選択を復元' );
	$check( 422 === $request( 'select', array( 'agent' => 'customer-ai' ) )->get_status(), '公開AIを社内AIとして選べない' );
	$catalog = array( array( 'name' => 'staff-ai', 'audience' => 'internal' ) );
	$check( '' === $request( 'catalog', array() )->get_data()['selected_agent'], '権限撤回後のAIは復元しない' );
	wp_set_current_user( $users['contributor'] );
	$check( ! $bridge->capabilities(), '投稿権限はEC業務の権限を付与しない' );
	$check( 422 === $request( 'preview', array( 'operation' => 'products.get', 'arguments' => array( 'id' => $product->get_id() ) ) )->get_status(), 'EC権限のない投稿者を拒否' );
	get_user_by( 'id', $users['contributor'] )->add_cap( 'edit_products' );
	$check( 422 === $request( 'preview', array( 'operation' => 'products.get', 'arguments' => array( 'id' => $product->get_id() ) ) )->get_status(), '商品一覧権限だけでは他人の下書きを読めない' );
	$check( 422 === $request( 'preview', array( 'operation' => 'products.save', 'arguments' => array( 'name' => '公開不可の合成商品', 'status' => 'publish' ) ) )->get_status(), '商品編集権限は公開を許可しない' );
	wp_set_current_user( 0 );
	$check( in_array( $request( 'catalog', array() )->get_status(), array( 401, 403 ), true ), '匿名訪問者は管理業務を参照できない' );
	WP_CLI::success( 'WooCommerce ' . WC_VERSION . ': ' . $checks . ' 件の合成検証が成功しました。' );
} finally {
	remove_filter( 'pre_http_request', $http, 5 );
	wp_set_current_user( 0 );
	if ( $order ) { $order->delete( true ); }
	foreach ( $products as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	update_option( 'fourmix_intelligence_settings', $original, false );
}
