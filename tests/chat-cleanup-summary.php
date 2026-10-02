<?php

// 復元後の安全な統計だけを出力します。実設定の値は返しません。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'ローカル検証専用です。' ); }
global $wpdb;
echo wp_json_encode( array(
	'settings_sha256' => hash( 'sha256', wp_json_encode( get_option( 'fourmix_intelligence_settings', array() ) ) ),
	'fixture_active' => false !== get_option( 'fmi_chat_ui_fixture', false ),
	'fixture_loader_present' => file_exists( WP_CONTENT_DIR . '/mu-plugins/fourmix-chat-ui-synthetic.php' ),
	'synthetic_chat_users' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'fmi_chat_' ) . '%' ) ),
	'synthetic_chat_posts' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_fourmix_chat_ui_synthetic' ) ),
) );
