<?php

// 時刻付き送信キーと結果照会の境界を、外部通信なしで確認します。
require __DIR__ . '/execution-journal.php';
require __DIR__ . '/../src/Support/ChatSession.php';
define( 'DAY_IN_SECONDS', 86400 );
function wp_next_scheduled( $hook, $args ) { return false; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['schedule'][] = array( $time, $args ); }
function delete_option( $name ) { unset( $GLOBALS['options'][ $name ] ); }
use FourmixIntelligence\WordPress\Support\ChatSession;
$uuid = '12345678-1234-4234-8234-123456789012';
$key = ( time() * 1000 ) . ':' . $uuid;
$calls = 0;
$intent = array( 'operation' => 'chat', 'message' => '合成応答' );
$callback = static function () use ( &$calls ) { ++$calls; return array( 'result' => array( 'answer' => '合成応答' ) ); };
check( 'not_started' === ChatSession::status( 'actor-a', $key )['state'] );
$first = ChatSession::run( 'actor-a', $key, $intent, $callback );
check( $first === ChatSession::run( 'actor-a', $key, $intent, $callback ) && 1 === $calls );
check( $first === ChatSession::status( 'actor-a', $key )['response'] );
check( 'not_started' === ChatSession::status( 'actor-b', $key )['state'] );
foreach ( array( '', 'arbitrary-key', ( ( time() - DAY_IN_SECONDS ) * 1000 ) . ':' . $uuid, ( ( time() + 120 ) * 1000 ) . ':' . $uuid ) as $invalid ) {
	try { ChatSession::run( 'actor-a', $invalid, $intent, $callback ); throw new RuntimeException( '不正な送信キーを拒否しませんでした。' ); } catch ( InvalidArgumentException $expected ) { }
}
check( 1 === $calls );
$unknown_key = ( time() * 1000 ) . ':22345678-1234-4234-8234-123456789012';
$unknown = static function () use ( &$calls ) { ++$calls; throw new RuntimeException( '合成通信断' ); };
check( 'unknown_effect' === ChatSession::run( 'actor-a', $unknown_key, $intent, $unknown )['state'] );
check( 'unknown_effect' === ChatSession::run( 'actor-a', $unknown_key, $intent, $unknown )['state'] && 2 === $calls );
check( 'unknown_effect' === ChatSession::status( 'actor-a', $unknown_key )['state'] );
$confirmation = array( 'status' => 'confirmation_required', 'id' => $uuid );
check( array( $uuid ) === ChatSession::actions( array( 'tool' => $confirmation, 'nested' => array( $confirmation ) ) ) );
check( array() === ChatSession::actions( array( 'answer' => json_encode( $confirmation ) ) ) );
$deep = $confirmation; for ( $i = 0; $i < 14; ++$i ) { $deep = array( 'nested' => $deep ); }
check( array() === ChatSession::actions( $deep ) );
check( min( array_column( $GLOBALS['schedule'], 0 ) ) >= time() + DAY_IN_SECONDS );
echo "送信期限・重複・本人分離・不明結果・構造化確認を検証しました。\n";
