<?php

// 実サイトや外部AIを使用せず、更新状態と重複防止の契約を確認します。
require __DIR__ . '/../src/Support/ExecutionJournal.php';
function __( $text, $domain ) { return $text; }
function esc_html__( $text, $domain ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
function get_option( $key, $default = null ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; }
function get_current_user_id() { return 10; }
function do_action( $hook, $data ) { $GLOBALS['audit'][] = $data; }
function check( $condition ) { if ( ! $condition ) { throw new RuntimeException( '検証失敗' ); } }
$journal = new FourmixIntelligence\WordPress\Support\ExecutionJournal();
$calls = 0;
$intent = array( 'operation' => 'content.create', 'arguments' => array( 'title' => '合成データ' ) );
$callback = function () use ( &$calls ) { ++$calls; return array( 'id' => 1 ); };
$first = $journal->run( 'site-a:actor-10', 'request-0001', $intent, $callback );
check( 'succeeded' === $first['state'] );
check( $first === $journal->run( 'site-a:actor-10', 'request-0001', $intent, $callback ) && 1 === $calls );
try { $journal->run( 'site-a:actor-10', 'request-0001', array( 'operation' => 'content.delete' ), $callback ); throw new RuntimeException( 'キーの再利用を拒否しませんでした。' ); } catch ( InvalidArgumentException $expected ) { }
$unknown = function () use ( &$calls ) { ++$calls; throw new RuntimeException( '合成通信断' ); };
check( 'unknown_effect' === $journal->run( 'site-a:actor-10', 'request-0002', $intent, $unknown )['state'] );
check( 'unknown_effect' === $journal->run( 'site-a:actor-10', 'request-0002', $intent, $unknown )['state'] && 2 === $calls );
$journal->run( 'site-b:actor-10', 'request-0001', $intent, $callback );
check( 3 === $calls );
check( ! str_contains( json_encode( $GLOBALS['audit'] ), '合成データ' ) );
echo "実行記録の成功・重複・内容変更・結果不明・分離・監査を検証しました。\n";
