<?php

// 合成検証の統計だけを出力し、本文・引数・cookie・トークンは返しません。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'ローカル合成検証専用です。' );
}
$state = get_option( 'fmi_chat_ui_fixture' );
if ( ! is_array( $state ) ) { throw new RuntimeException( '準備済みの合成検証だけを集計します。' ); }
echo wp_json_encode( array(
	'studio_responses' => 'synthetic_only',
	'run_responses' => array_sum( $state['calls'] ),
	'max_confirmation_posts_per_action' => max( array_merge( array( 0 ), array_column( $state['actions'], 'posts' ) ) ),
	'audit_records' => count( (array) ( $state['audit'] ?? array() ) ),
	'original_settings_sha256' => hash( 'sha256', wp_json_encode( $state['settings'] ) ),
) );
