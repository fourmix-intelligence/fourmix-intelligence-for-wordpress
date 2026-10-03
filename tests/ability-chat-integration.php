<?php

// 独立 WordPress の実登録・会話・実行記録を検証します。外部応答は合成で、モデルは呼びません。
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'WordPress CLI の検証専用です。' );
}
use FourmixIntelligence\WordPress\Rest\StaffController;
use FourmixIntelligence\WordPress\Support\NativeIdentity;

$original = get_option( 'fourmix_intelligence_settings', array() );
$actor    = get_current_user_id();
$fixture  = wp_create_user( 'ability_chat_' . wp_generate_uuid4(), wp_generate_password(), 'ability_chat_' . wp_generate_uuid4() . '@example.test' );
get_user_by( 'id', $fixture )->set_role( 'administrator' );
wp_set_current_user( $fixture );
$identity = array( 'account_id' => 'account-a', 'workspace_id' => 'workspace-one', 'connection_id' => 'chat-connection', 'business_write' => true );
$status   = 200;
$mode     = 'success';
$calls    = array();
$receipts = array();
$scopes   = array();
$checks   = 0;
$registration_warnings = 0;
$conversations = array( 'account-a' => wp_generate_uuid4(), 'account-b' => wp_generate_uuid4() );
$check = static function ( $value, $label ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $label );
	}
};
$hook = static function ( $pre, $args, $url ) use ( &$identity, &$status, &$mode, &$calls, $conversations ) {
	if ( str_contains( $url, '/api/v3/native-business/session' ) ) {
		$body = $identity;
		$code = $status;
	} elseif ( str_ends_with( $url, '/api/v3/ai/plugins/metadata' ) ) {
		$body = array( array( 'name' => 'team-ai', 'audience' => 'internal' ) );
		$code = 200;
	} elseif ( str_ends_with( $url, '/team-ai/runs' ) ) {
		$calls[] = json_decode( $args['body'], true );
		if ( 'disconnect' === $mode ) {
			return new WP_Error( 'http_request_failed', '合成の応答断' );
		}
		$code = 200;
		$body = array( 'conversation_id' => $conversations[ $identity['account_id'] ], 'result' => array( 'answer' => '合成の回答', 'data' => array( 'account' => $identity['account_id'] ) ) );
		if ( 'invalid' === $mode ) {
			$body = array( 'result' => array( 'answer' => 12 ) );
		} elseif ( 'empty_data' === $mode ) {
			$body['result']['data'] = array();
		} elseif ( 'confirmation' === $mode ) {
			$body['result']['data']['confirmation'] = array( 'status' => 'confirmation_required', 'id' => '11111111-1111-4111-8111-111111111111' );
		}
	} else {
		throw new RuntimeException( '検証対象以外の外部 API は呼びません。' );
	}
	return array( 'headers' => array(), 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ) );
};
$record = static function ( $name ) use ( &$receipts ) {
	if ( str_starts_with( $name, 'fmi_execution_' ) ) {
		$receipts[] = $name;
	}
};
$warning = static function ( $function ) use ( &$registration_warnings ) {
	if ( 'WP_Ability::__construct' === $function ) {
		++$registration_warnings;
	}
};
$login = static function ( $account ) use ( &$identity, &$scopes ) {
	$identity['account_id'] = $account;
	NativeIdentity::save( array( 'token' => 'synthetic-ability-' . $account, 'account_id' => $account, 'connection_id' => 'chat-connection', 'provider' => 'wordpress' ) );
	$scope = NativeIdentity::scope( $identity );
	$scopes[] = $scope . '_' . hash( 'sha256', 'team-ai' );
	update_user_meta( get_current_user_id(), 'fourmix_intelligence_internal_agent_' . hash( 'sha256', $scope ), 'team-ai' );
};
try {
	update_option( 'fourmix_intelligence_settings', array( 'native_connection' => 'chat-connection', 'native_tenant' => 'chat-tenant' ) );
	add_filter( 'pre_http_request', $hook, 10, 3 );
	add_action( 'added_option', $record );
	add_action( 'doing_it_wrong_run', $warning );
	$ability = wp_get_ability( 'fourmix-intelligence/ask-agent' );
	$check( null !== $ability, '実際の登録入口を取得する。' );
	$check( 0 === $registration_warnings, 'WordPress の登録契約に反するプロパティを渡さない。' );
	$check( false === $ability->get_meta()['show_in_rest'], '公開 HTTP 入口を追加しない。' );
	$unsigned = $ability->execute( array( 'message' => '未ログイン' ) );
	$check( is_wp_error( $unsigned ) && 401 === $unsigned->get_error_data()['status'], '登録入口でも本人未ログインを拒否する。' );
	$check( 0 === count( $calls ), '未ログインでモデルへ送信しない。' );
	$login( 'account-a' );
	$first = $ability->execute( array( 'message' => '同じ質問' ) );
	$check( ! is_wp_error( $first ) && '合成の回答' === $first['answer'], 'session/chat を通じて登録入口が正常回答する。' );
	$check( 'account-a' === $first['data']['account'], '構造化データも返す。' );
	$check( 1 === count( $calls ), '一つの呼び出しで一度だけ送信する。' );
	$check( 'account-a' === $calls[0]['options']['native_context']['account_id'], '現在の本人を実行の参考情報へ付ける。' );
	$scope_a = $scopes[0];
	$thread_a = get_transient( $scope_a )['thread_id'];
	$second = $ability->execute( array( 'message' => '同じ質問' ) );
	$check( ! is_wp_error( $second ) && 2 === count( $calls ), '独立した再 execute は新しい依頼として扱う。' );
	$check( 2 === count( array_unique( $receipts ) ), '独立呼び出し間の exactly-once を仮定しない。' );
	$check( $conversations['account-a'] === $calls[1]['conversation_id'], '同じアカウントの会話は既存の境界で継続する。' );
	$login( 'account-b' );
	$other = $ability->execute( array( 'message' => 'B の質問' ) );
	$check( ! is_wp_error( $other ) && 'account-b' === $other['data']['account'], '共用 WordPress ユーザーでも B の本人で実行する。' );
	$check( ! isset( $calls[2]['conversation_id'] ), 'A の会話 ID を B へ送らない。' );
	$check( $thread_a !== get_transient( $scopes[1] )['thread_id'], 'A と B のスレッドを分離する。' );
	$login( 'account-a' );
	$ability->execute( array( 'message' => 'A へ戻る' ) );
	$check( $conversations['account-a'] === $calls[3]['conversation_id'], 'A に戻ると A の会話を使う。' );
	$mode = 'empty_data';
	$empty = $ability->execute( array( 'message' => '回答だけの依頼' ) );
	$check( ! is_wp_error( $empty ) && array() === $empty['data'], '空の構造化データも登録入口の出力 schema に適合する。' );
	$mode = 'confirmation';
	$before = count( $calls );
	$pending = $ability->execute( array( 'message' => '確認待ちの依頼' ) );
	$check( ! is_wp_error( $pending ) && 'confirmation_required' === $pending['data']['confirmation']['status'], '確認待ちの構造化結果を成功済みへ変換しない。' );
	$check( in_array( '11111111-1111-4111-8111-111111111111', get_transient( $scope_a )['actions'], true ), '確認対象を既存の本人・AI 会話の確認境界に記録する。' );
	$check( $before + 1 === count( $calls ), '確認を自動実行しない。' );
	$before = count( $calls );
	$mode = 'disconnect';
	$unknown = $ability->execute( array( 'message' => '応答断の質問' ) );
	$check( is_wp_error( $unknown ) && 'fourmix_intelligence_unknown_result' === $unknown->get_error_code(), '応答断を空の成功ではなく結果不明として返す。' );
	$check( $before + 1 === count( $calls ), '応答断でも再送しない。' );
	$receipt = $unknown->get_error_data();
	$check( 'unknown_effect' === $receipt['state'] && 409 === $receipt['status'], '結果不明の状態を維持する。' );
	$request = new WP_REST_Request( 'POST' );
	$request->set_param( 'agent', 'team-ai' );
	$request->set_param( 'request_id', $receipt['request_id'] );
	$request->set_param( 'thread_id', $receipt['thread_id'] );
	$request->set_param( 'message', '応答断の質問' );
	$check( 'unknown_effect' === ( new StaffController() )->run_status( $request )->get_data()['state'], '既存の状態照会で結果不明の実行記録を確認する。' );
	$check( 'unknown_effect' === ( new StaffController() )->chat( $request )->get_data()['state'], '同一 ID の内部実行記録は再実行しない。' );
	$check( $before + 1 === count( $calls ), '同じ不明実行記録の照会・再利用では送信数が増えない。' );
	$mode = 'success';
	$request->set_param( 'request_id', (string) (int) floor( microtime( true ) * 1000 ) . ':' . wp_generate_uuid4() );
	$request->set_param( 'message', '正常実行記録の再利用' );
	$completed = ( new StaffController() )->chat( $request )->get_data();
	$repeated = ( new StaffController() )->chat( $request )->get_data();
	$check( $completed === $repeated && $before + 2 === count( $calls ), '同じ正常 ID は既存の回答だけを再利用する。' );
	$mode = 'invalid';
	$bad = $ability->execute( array( 'message' => '形式不正' ) );
	$check( is_wp_error( $bad ) && 'fourmix_intelligence_invalid_result' === $bad->get_error_code(), '不正な回答を空の成功へ変換しない。' );
	$mode = 'success';
	$before = count( $calls );
	$status = 403;
	$removed = $ability->execute( array( 'message' => 'メンバー削除後' ) );
	$check( is_wp_error( $removed ) && 403 === $removed->get_error_data()['status'], '現在のワークスペース資格がなくなれば拒否する。' );
	$check( 'failed' === $removed->get_error_data()['state'], '権限不足をログイン要求に変えない。' );
	$status = 401;
	$revoked = $ability->execute( array( 'message' => 'ログイン失効後' ) );
	$check( is_wp_error( $revoked ) && 'login_required' === $revoked->get_error_data()['state'], 'ログイン失効は再ログインの必要性を返す。' );
	$check( $before === count( $calls ), '資格の削除・失効後に新しい実行を発行しない。' );
	WP_CLI::success( 'ability chat: ' . $checks . ' checks passed' );
} finally {
	remove_filter( 'pre_http_request', $hook, 10 );
	remove_action( 'added_option', $record );
	remove_action( 'doing_it_wrong_run', $warning );
	foreach ( array_unique( $receipts ) as $name ) {
		delete_option( $name );
		wp_clear_scheduled_hook( 'fourmix_intelligence_chat_receipt_expired', array( substr( $name, strlen( 'fmi_execution_' ) ) ) );
	}
	foreach ( array_unique( $scopes ) as $scope ) {
		delete_transient( $scope );
	}
	delete_transient( NativeIdentity::key() );
	wp_set_current_user( $actor );
	update_option( 'fourmix_intelligence_settings', $original );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $fixture );
}
