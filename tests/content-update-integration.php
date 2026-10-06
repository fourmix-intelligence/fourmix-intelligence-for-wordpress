<?php

// WordPress CLIで、合成投稿の部分更新と実行者権限を確認します。
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLIのローカル検証専用です。' );
}
$before = get_option( 'fourmix_intelligence_settings', array() );
$actor = get_current_user_id();
$prefix = 'fmi_partial_' . wp_generate_uuid4();
$user = wp_insert_user( array( 'user_login' => $prefix, 'user_email' => $prefix . '@example.test', 'user_pass' => wp_generate_password( 48 ), 'role' => 'contributor' ) );
$post = 0;
try {
	if ( is_wp_error( $user ) ) { throw new RuntimeException( '合成担当者を作成できません。' ); }
	wp_set_current_user( $user );
	update_option( 'fourmix_intelligence_settings', array_replace( $before, array( 'bridge_groups' => array( 'content' ), 'sync_enabled' => false ) ), false );
	$post = wp_insert_post( array( 'post_title' => $prefix, 'post_content' => '保持する合成本文', 'post_status' => 'draft', 'post_author' => $user ), true );
	if ( is_wp_error( $post ) ) { throw new RuntimeException( '合成投稿を作成できません。' ); }
	$bridge = new FourmixIntelligence\WordPress\Rest\NativeBridgeController();
	$bridge->perform( 'content.update', array( 'id' => $post, 'status' => 'pending' ) );
	$record = get_post( $post );
	if ( 'pending' !== $record->post_status || $prefix !== $record->post_title || '保持する合成本文' !== $record->post_content || (int) $user !== (int) $record->post_author ) { throw new RuntimeException( '状態だけの更新で既存内容が変わりました。' ); }
	$bridge->perform( 'content.update', array( 'id' => $post, 'title' => $prefix . '_updated' ) );
	$record = get_post( $post );
	if ( $prefix . '_updated' !== $record->post_title || '保持する合成本文' !== $record->post_content || 'pending' !== $record->post_status ) { throw new RuntimeException( 'タイトル更新で本文や状態が変わりました。' ); }
	try {
		$bridge->validate_operation( 'content.update', array( 'id' => $post, 'status' => 'publish' ) );
		throw new LogicException( '投稿者の公開権限を拒否しませんでした。' );
	} catch ( RuntimeException $expected ) {
		if ( 'pending' !== get_post_status( $post ) ) { throw new RuntimeException( '拒否された公開で状態が変わりました。' ); }
	}
	echo "部分更新の内容・状態・作者の保持と公開拒否を検証しました。\n";
} finally {
	wp_set_current_user( $actor );
	update_option( 'fourmix_intelligence_settings', $before, false );
	if ( $post && ! is_wp_error( $post ) ) { wp_delete_post( $post, true ); }
	if ( ! is_wp_error( $user ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user ); }
}
