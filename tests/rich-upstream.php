<?php

// 独立したローカル合成HTTPサーバー専用。WordPressや実AI・外部サービスは読み込みません。
if ( PHP_SAPI !== 'cli-server' ) { http_response_code( 403 ); exit; }
if ( ! str_starts_with( $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer synthetic-' ) ) { http_response_code( 403 ); exit; }
$storage = '/tmp/fourmix-wordpress-rich-fixture.json';
$state = file_exists( $storage ) ? json_decode( file_get_contents( $storage ), true ) : array();
$state = array_merge( array( 'calls' => 0, 'uploads' => 0, 'deletes' => 0, 'files' => array() ), $state ?: array() );
$save = static function () use ( &$state, $storage ) { file_put_contents( $storage, json_encode( $state, JSON_UNESCAPED_UNICODE ), LOCK_EX ); };
$uuid = static function () { $bytes = random_bytes( 16 ); $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 ); $hex = bin2hex( $bytes ); return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 ); };
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body = json_decode( file_get_contents( 'php://input' ), true ) ?: array();
header( 'Cache-Control: no-store' ); header( 'Content-Type: application/json' );
if ( preg_match( '#^/api/v3/agent-conversations/[^/]+/resolve$#', $path ) ) { echo json_encode( array( 'identify' => $uuid() ) ); exit; }
if ( str_ends_with( $path, '/customer-conversation' ) ) { echo json_encode( array( 'conversation_id' => $uuid(), 'customer_token' => str_repeat( 'a', 64 ) ) ); exit; }
if ( preg_match( '#^/api/v3/ai/plugins/(synthetic-(?:internal|second|customer))/runs/stream$#', $path, $matches ) ) {
	++$state['calls']; $state['last_body'] = $body; $save();
	$run = $uuid(); $conversation = $body['conversation_id'] ?? $uuid();
	$message = $body['messages'][0]['content'] ?? '';
	$emit = static function ( $type, $data ) use ( $run ) { echo json_encode( array( 'type' => $type, 'data' => $data, 'run_id' => $run ), JSON_UNESCAPED_UNICODE ) . "\n"; flush(); };
	header( 'Content-Type: application/x-ndjson' ); header( 'X-Accel-Buffering: no' ); ignore_user_abort( true );
	$emit( 'run.created', array( 'conversation_id' => $conversation, 'customer_token' => str_repeat( 'a', 64 ), 'run_ticket' => 'synthetic-private-run-ticket' ) );
	$emit( 'run.status', array( 'message' => '合成の逐次応答を準備しています' ) );
	if ( str_starts_with( $message, '合成審査後の一括回答' ) ) {
		$emit( 'run.status', array( 'phase' => 'answer_review', 'message' => '回答内容を確認しています' ) );
		usleep( 1700000 );
		$result = array( 'answer' => '審査後の合成確定回答です。実際のモデルは呼び出していません。', 'data' => array(), 'follow_up_questions' => array() );
		$emit( 'assistant.message', array( 'text' => $result['answer'] ) );
		$emit( 'run.completed', array( 'result' => $result ) ); exit;
	}
	if ( str_starts_with( $message, '合成遅延' ) ) { usleep( 3000000 ); }
	$first = "これは合成応答です。実際のモデルは呼び出していません。\n";
	$rest = str_starts_with( $message, '長文' ) ? str_repeat( "投稿内容を確認し、必要な手順を順番に整理します。長いメッセージもこの領域で読み進められます。\n", 12 ) . "```\n" . str_repeat( 'long-content-', 25 ) . "\n```\n" : 'ご相談を受け付けました: ' . mb_substr( explode( "\n", $message )[0], 0, 80 );
	if ( str_starts_with( $message, '合成リッチ' ) ) {
		$first = "# 業務の確認結果\n**強調**・*補足*・~~取り消し~~・[安全な参照](https://example.test/)\n\n> 次の内容は合成検証です。\n\n- 投稿の確認\n- 操作の承認\n\n| 業務 | 状態 | 担当 | 期限 | 確認方法 | 備考 |\n| --- | --- | --- | --- | --- | --- |\n| 記事編集 | 確認待ち | 運営担当 | 本日 | 内容をプレビュー | 長い説明を読み進められます |\n";
		$rest = "| 権限確認 | 完了 | 本人 | 毎回 | 対象権限を照合 | 本文は送信しません |\n\n```javascript\nconst approved = false;\nif (approved) console.log('明示確認後');\n```\n\n```mermaid\nflowchart LR\n A[内容を確認] --> B{承認するか}\n B -->|承認| C[実行結果を確認]\n B -->|保留| D[実行しない]\n```\n";
	}
	if ( str_starts_with( $message, '合成禁止図' ) ) { $rest = "```mermaid\n%%{init: {'securityLevel': 'loose'}}%%\ngraph LR\nA-->B\nclick A 'javascript:alert(1)'\n```\n<img src=x onerror=\"window.fmiInjected=true\">\n[危険](javascript:alert(1))\n![外部画像](https://example.test/tracker.png)"; }
	if ( str_starts_with( $message, '合成安全表示' ) ) { $first = ''; $rest = '<img src=x onerror="window.fmiInjected=true"> 合成HTMLは文字として表示します。'; }
	$emit( 'assistant.delta', array( 'text' => $first ) ); usleep( str_starts_with( $message, '合成リッチ' ) ? 1700000 : 100000 );
	if ( str_starts_with( $message, '合成通信不明' ) ) { exit; }
	$emit( 'assistant.delta', array( 'text' => $rest ) );
	$result = array( 'answer' => $first . $rest, 'data' => array(), 'follow_up_questions' => array( array( 'prompt' => '次に確認する内容を整理してください。', 'options' => array() ) ) );
	if ( ! empty( $body['_synthetic_action'] ) ) { $result['data']['tool_result'] = array( 'status' => 'confirmation_required', 'id' => $body['_synthetic_action'] ); }
	$emit( 'assistant.message', array( 'text' => $result['answer'] ) ); $emit( 'run.completed', array( 'result' => $result ) ); exit;
}
if ( preg_match( '#^/api/v3/ai/plugins/([^/]+)/conversations/([a-f0-9-]{36})/attachments(?:/([a-f0-9-]{36})(/content)?)?$#', $path, $matches ) ) {
	$scope = hash( 'sha256', $matches[1] . ':' . $matches[2] . ':' . ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) . ':' . ( $_SERVER['HTTP_X_FOURMIX_CUSTOMER_TOKEN'] ?? '' ) );
	$id = $matches[3] ?? ''; $files = (array) ( $state['files'][ $scope ] ?? array() );
	$method = $_SERVER['REQUEST_METHOD'];
	if ( 'POST' === $method && ! $id ) {
		$file = $_FILES['file'] ?? null; if ( ! $file || $file['error'] || !$file['size'] || $file['size'] > 2 * 1024 * 1024 ) { http_response_code( 422 ); exit; }
		$types = array( 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'txt' => 'text/plain', 'md' => 'text/plain', 'csv' => 'text/csv', 'pdf' => 'application/pdf' );
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ); if ( ! isset( $types[$extension] ) ) { http_response_code( 415 ); exit; }
		if ( str_contains( $file['name'], '遅延' ) ) { usleep( 1000000 ); }
		$id = $uuid(); $metadata = array( 'id' => $id, 'name' => $file['name'], 'mime' => $types[$extension], 'size' => $file['size'], 'expires_at' => time() + 3600 );
		$files[$id] = array( 'metadata' => $metadata, 'body' => base64_encode( file_get_contents( $file['tmp_name'] ) ) ); $state['files'][$scope] = $files; ++$state['uploads']; $save(); http_response_code( 201 ); echo json_encode( $metadata, JSON_UNESCAPED_UNICODE ); exit;
	}
	if ( 'GET' === $method && ! $id ) { echo json_encode( array( 'data' => array_values( array_map( static fn($file) => $file['metadata'], $files ) ) ), JSON_UNESCAPED_UNICODE ); exit; }
	if ( ! isset( $files[$id] ) ) { http_response_code( 404 ); exit; }
	if ( 'DELETE' === $method ) { unset( $files[$id] ); $state['files'][$scope] = $files; ++$state['deletes']; $save(); http_response_code( 204 ); exit; }
	if ( 'GET' === $method && isset( $matches[4] ) ) { header( 'Content-Type: ' . $files[$id]['metadata']['mime'] ); echo base64_decode( $files[$id]['body'] ); exit; }
}
http_response_code( 404 );
