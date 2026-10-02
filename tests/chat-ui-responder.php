<?php

// プラグイン本体からは読み込みません。実画面検証時だけMUローダーで読み込みます。
$fixture = get_option( 'fmi_chat_ui_fixture' );
if ( ! is_array( $fixture ) || time() >= $fixture['expires'] || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { return; }
add_filter( 'pre_wp_mail', static fn() => false );
function fourmix_chat_synthetic_policy(): array { return array( 'enabled' => true, 'extensions' => array( '.png', '.jpg', '.jpeg', '.webp', '.txt', '.md', '.csv', '.pdf' ), 'max_files' => 8, 'max_bytes' => 2 * MB_IN_BYTES, 'context_bytes' => 4 * MB_IN_BYTES ); }
function fourmix_chat_synthetic_transport( string $path ): bool { return (bool) preg_match( '#^/api/v3/(?:ai/plugins/synthetic-(?:internal|second|customer)/(?:runs/stream|customer-conversation|conversations/[a-f0-9-]{36}/attachments(?:/[a-f0-9-]{36}(?:/content)?)?)|agent-conversations/synthetic-(?:internal|second)/resolve)$#', $path ); }
if ( ! empty( $fixture['rich'] ) ) {
	add_action( 'http_api_curl', static function ( $handle, $args, $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH ); if ( ! fourmix_chat_synthetic_transport( $path ) ) { return; }
		if ( ! str_starts_with( $args['headers']['Authorization'] ?? '', 'Bearer synthetic-' ) ) { throw new RuntimeException( '合成接続だけを使用してください。' ); }
		curl_setopt( $handle, CURLOPT_URL, 'http://fourmix-wp-rich-fixture:8080' . $path );
		if ( str_ends_with( $path, '/runs/stream' ) ) {
			$body = json_decode( $args['body'], true ); $message = $body['messages'][0]['content'] ?? ''; $state = get_option( 'fmi_chat_ui_fixture' );
			$key = hash( 'sha256', $message ); $state['calls'][$key] = ($state['calls'][$key] ?? 0) + 1; $state['last_body'] = $body;
			if ( str_starts_with( $message, '合成操作' ) || str_starts_with( $message, '合成不明操作' ) ) {
				$id = wp_generate_uuid4(); $state['actions'][$id] = array( 'id' => $id, 'status' => 'confirmation_required', 'operation_id' => 'content.update', 'operation_name' => '合成記事のタイトルを更新', 'arguments' => array( 'id' => $state['posts']['draft'], 'title' => '合成確認後のタイトル' ), 'unknown' => str_starts_with( $message, '合成不明操作' ), 'posts' => 0 );
				$body['_synthetic_action'] = $id; curl_setopt( $handle, CURLOPT_POSTFIELDS, wp_json_encode( $body ) );
			}
			update_option( 'fmi_chat_ui_fixture', $state, false );
		}
	}, 10, 3 );
}
add_action( 'added_option', static function ( $name ) {
	if ( str_starts_with( $name, 'fmi_execution_' ) || str_starts_with( $name, '_transient_fmi_staff_' ) || str_starts_with( $name, '_transient_timeout_fmi_staff_' ) || str_starts_with( $name, '_transient_fmi_customer_' ) || str_starts_with( $name, '_transient_timeout_fmi_customer_' ) ) {
		$state = get_option( 'fmi_chat_ui_fixture' ); $state['options'][] = $name; update_option( 'fmi_chat_ui_fixture', $state, false );
	}
} );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	$state = get_option( 'fmi_chat_ui_fixture' );
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! str_starts_with( $path, '/api/v3/' ) ) { return new WP_Error( 'synthetic_external_denied', '合成検証中の外部通信は禁止されています。' ); }
	if ( ! empty( $state['rich'] ) && fourmix_chat_synthetic_transport( $path ) ) { return str_starts_with( $args['headers']['Authorization'] ?? '', 'Bearer synthetic-' ) ? false : new WP_Error( 'synthetic_token_required', '合成接続専用です。' ); }
	$body = is_string( $args['body'] ?? null ) ? ( json_decode( $args['body'], true ) ?: array() ) : array(); $data = array();
	if ( '/api/v3/ai/plugins/metadata' === $path ) {
		$data = empty( $state['revoked'] ) ? array( array( 'name' => 'synthetic-internal', 'service_name' => '合成・運営支援AI', 'audience' => 'internal' ), array( 'name' => 'synthetic-second', 'service_name' => '合成・編集支援AI', 'audience' => 'internal' ), array( 'name' => 'synthetic-customer', 'service_name' => '合成・公開案内AI', 'audience' => 'customer' ) ) : array();
		foreach ( $data as &$agent ) { $agent['attachments'] = fourmix_chat_synthetic_policy(); } unset( $agent );
	} elseif ( preg_match( '#^/api/v3/ai/plugins/(synthetic-(internal|second|customer))/metadata$#', $path, $matches ) ) {
		$data = array( 'name' => $matches[1], 'audience' => 'customer' === $matches[2] && empty( $state['public_internal'] ) ? 'customer' : 'internal', 'attachments' => fourmix_chat_synthetic_policy() );
	} elseif ( preg_match( '#/api/v3/ai/plugins/synthetic-(internal|second|customer)/runs$#', $path ) ) {
		$message = $body['messages'][0]['content'] ?? ''; $key = hash( 'sha256', $message );
		$state['calls'][ $key ] = 1 + ( $state['calls'][ $key ] ?? 0 ); $state['last_body'] = $body;
		update_option( 'fmi_chat_ui_fixture', $state, false );
		if ( str_starts_with( $message, '合成遅延' ) ) { usleep( 3000000 ); }
		if ( str_starts_with( $message, '合成通信不明' ) ) { return new WP_Error( 'synthetic_unknown', '合成通信断' ); }
		$answer = "これは合成応答です。実際のモデルは呼び出していません。\n" . ( str_starts_with( $message, '長文' ) ? str_repeat( "投稿内容を確認し、必要な手順を順番に整理します。長いメッセージもこの領域で読み進められます。\n", 12 ) . "```\n" . str_repeat( 'long-content-', 25 ) . "\n```\n" : 'ご相談を受け付けました: ' . mb_substr( explode( "\n", $message )[0], 0, 80 ) );
		if ( str_starts_with( $message, '合成安全表示' ) ) { $answer = '<img src=x onerror="window.fmiInjected=true"> 合成HTMLは文字として表示します。'; }
		$data = array( 'conversation_id' => $body['conversation_id'] ?? wp_generate_uuid4(), 'result' => array( 'answer' => $answer, 'data' => array() ) );
		if ( str_contains( $path, 'customer' ) ) {
			$data['customer_token'] = $body['customer_token'] ?? str_repeat( 'a', 64 );
			$data['events'] = array( array( 'type' => 'run.created', 'data' => array( 'customer_token' => 'synthetic-nested-secret', 'run_ticket' => 'synthetic-run-ticket', 'nested' => array( 'conversation_token' => 'synthetic-deeper-secret', 'authorization' => 'synthetic-authorization' ) ) ) );
		}
		if ( str_starts_with( $message, '合成操作' ) || str_starts_with( $message, '合成不明操作' ) ) {
			$id = wp_generate_uuid4(); $state['actions'][ $id ] = array( 'id' => $id, 'status' => 'confirmation_required', 'operation_id' => 'content.update', 'operation_name' => '合成記事のタイトルを更新', 'arguments' => array( 'id' => $state['posts']['draft'], 'title' => '合成確認後のタイトル' ), 'unknown' => str_starts_with( $message, '合成不明操作' ), 'posts' => 0 );
			$data['result']['data']['tool_result'] = array( 'status' => 'confirmation_required', 'id' => $id ); update_option( 'fmi_chat_ui_fixture', $state, false );
		}
	} elseif ( preg_match( '#^/api/v3/connection-actions/([a-f0-9-]{36})(/confirm)?$#', $path, $matches ) ) {
		$id = $matches[1]; if ( empty( $state['actions'][ $id ] ) ) { return new WP_Error( 'synthetic_action_missing', '合成操作がありません。' ); }
		if ( isset( $matches[2] ) ) {
			++$state['actions'][ $id ]['posts']; $state['actions'][ $id ]['status'] = $state['actions'][ $id ]['unknown'] ? 'unknown_effect' : 'completed';
			update_option( 'fmi_chat_ui_fixture', $state, false );
			if ( $state['actions'][ $id ]['unknown'] ) { return new WP_Error( 'synthetic_confirmation_unknown', '合成確認の通信断' ); }
		}
		$data = $state['actions'][ $id ]; unset( $data['unknown'], $data['posts'] );
	} elseif ( '/api/v3/ai/plugins/synthetic-customer/customer-history' === $path ) {
		$data = array( 'messages' => array( array( 'role' => 'user', 'content' => '合成公開の相談' ), array( 'role' => 'assistant', 'content' => '合成履歴です。訪問者本人の会話だけを読み込みます。' ) ) );
	} else { return new WP_Error( 'synthetic_contract_denied', '合成契約以外の通信は禁止されています。' ); }
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200 ) );
}, 1, 3 );
add_action( 'fourmix_intelligence_operation_audit', static function ( $entry ) {
	$state = get_option( 'fmi_chat_ui_fixture' ); $state['audit'][] = $entry; update_option( 'fmi_chat_ui_fixture', $state, false );
} );
