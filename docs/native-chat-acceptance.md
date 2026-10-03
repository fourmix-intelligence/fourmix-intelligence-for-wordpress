# WordPressのネイティブ相談画面と検証結果

開発版 `2.0.0-dev` の変更です。配布・公開・配備は実施していません。Fourmix Intelligenceの既存のNDJSON応答、AI Studio一覧、会話履歴、非公開添付、接続操作の確認APIを利用します。JSON互換経路も保持しています。

## 人による確認入口

本タスクの候補版は独立した WordPress `http://localhost:18193` で検証します。既存の統合環境 `http://localhost:48093` は切り替えていません。候補版の管理画面 **Fourmix Intelligence → 社内向けAIの設定** で、Fourmix Intelligence にログインし、ワークスペースの社内向けAIを選択します。既存 URL `/wp-admin/admin.php?page=fourmix-intelligence-personal-settings` は維持しています。

### ログインと AI 選択の追補検証

独立した合成 WordPress で `wp eval-file tests/ability-identity-integration.php` を実行します。同じ WordPress ユーザーが複数の Fourmix Intelligence アカウントを使うときの AI 選択、旧共用キーを使わないこと、未ログイン・失効・接続不一致・AI の利用不可を検証します。外部の本人確認と一覧は合成応答とし、モデルは呼び出しません。実画面の案内は `tests/identity-labels-e2e.cjs` で設定ページ・相談ページ・浮窓を確認します。

WordPress Ability `fourmix-intelligence/ask-agent` は、現在の本人の選択を検証してから既存の `session()` と `chat()` を利用します。`tests/ability-chat-integration.php` で実登録入口・合成応答・実 WordPress DB の会話と実行記録を検証します。`tests/ability-core-integration.php` は本タスクの固定 Docker 構成に限定し、Fourmix Intelligence のログイン、code 交換、Studio・Python の会話保存と元のログアウトを確認します。モデルだけを固定応答に置き換え、外部モデルを利用しません。CLI には画面を提供するコンテナと同じ `WORDPRESS_DEBUG=1` と `WORDPRESS_CONFIG_EXTRA` の固定 API URL、および検証専用の未使用 recovery code を渡します。

一回の Ability 呼び出しには新しい送信 ID を生成します。結果不明では自動再送せず、`WP_Error` の `request_id` と `thread_id` を既存の状態照会に使います。同じ ID の送信は既存の実行記録で処理しますが、呼び出し元が独立した `execute()` を行うと別の依頼です。独立呼び出し間の自動重複排除や exactly-once は保証しません。上流の不正な回答や結果不明を空の成功へ変換しません。

**AIに相談** は会話専用のページです。`/wp-admin/admin.php?page=fourmix-intelligence-operations`、または編集権限を持つ利用者の管理画面右下の公式アイコンから開けます。ページと浮窓は同じDOM・会話・確認経路を使い、入力や添付を維持します。未設定時は設定への案内だけを表示します。直接操作の従来のカタログは `fourmix-intelligence-native-operations` に移しました。

投稿・固定ページの編集画面にも **この内容についてAIに相談** の入口があります。現在の画面を参考情報として送信するか、毎回明示的に選択できます。送信対象は画面ID・名前と、閲覧可能な投稿のID・種類・タイトル・状態だけで、本文や一覧の行データは含めません。

公開画面は管理者が設定したお客様向けAIだけを使用します。**AIコンシェルジュ** ブロック、または次の短コードで配置できます。訪問者に社内AIの選択欄はありません。

```text
[fourmix_intelligence_chat title="サイトの内容をAIに相談"]
```

検証用の記事・利用者・接続情報は削除し、元の設定へ戻します。現在の開発サイトには実Studioの公開tokenと公開AIが設定されていないため、設定後の実AI応答は未確認です。実運用の資格情報をソースや証跡に含めていません。

## 相談・確認の状態

- メッセージ一覧と入力領域を分離し、長文は一覧内でスクロールします。Enterで改行、Ctrl/⌘ + Enterで送信します。二重クリックでは同じ依頼を増やしません。
- 上流のNDJSONを実際の到着時に転送し、回答の断片・進行状態・完了を表示します。一括回答を文字に分割して再生しません。実行結果を保存できた時点だけを完了とします。受信停止はブラウザー側の待機を止める操作で、サーバーの実行取消ではありません。固定回数のツール呼出し制限は追加していません。
- 通信断・画面再読込後は同じ送信の結果を照会します。未着の送信だけを同じ確認情報で再開でき、結果不明の実行は再送しません。新しい相談への切替が前の操作を取り消さないことも確認画面で表示します。
- 構造化された確認IDを会話と本人に結び付け、中央サーバーから正式な操作名・引数・監査IDを取得します。AI本文から操作を生成しません。
- この画面の承認は、サイトで有効なWordPress能力と本人の対象権限で再検証できる操作に限定します。その他の接続操作の確認はFourmix Intelligence側で扱います。確認APIは中央側でも本人・接続・AI・ワークスペースの権限を再検証します。
- **今回は実行しない** はこの画面での実行を見送り、確認要求自体は期限切れまで保留します。後続メッセージや再読込でも、この画面で見送った状態を保持します。
- 確認送信前に実行記録を取得し、成功・失敗・実行中・結果不明を区別します。結果不明では承認を再送せず、状態照会と対象データ・監査の確認を案内します。

## 回答と添付

Markdownの見出し・引用・リスト・強調・表・コードを表示します。表は表だけを横にスクロールでき、TSVとしてコピーできます。コードは構文色分けとコピー、回答は全体のコピーに対応します。次の質問は入力へ反映し、利用者が送信します。

Mermaidは実際にSVGへ描画し、図の拡大・閉じる操作に対応します。HTML、危険リンク、図の設定上書き・クリック処理・外部参照を拒否します。SVGは無権限sandboxとCSP内に置きます。モデル指定の画像は自動取得せず、明示操作で開くリンクにします。表示部品の版とライセンスは[表示部品とライセンス](chat-display-dependencies.md)を参照してください。

添付はStudioが返す許可ポリシーとWordPressの受付上限の両方を適用します。選択・ドラッグ・貼り付けに対応し、画像プレビュー、実転送の進捗、保存待ち、取り除き、エラーと結果照会を表示します。最大値は既存上流の8件・1件20MiB・合計40MiBで、AIの設定やWordPressの制限が小さい場合はそちらを使用します。対応候補はPNG/JPEG/WebP、PDF、UTF-8テキスト・Markdown・CSV/TSV、DOCX/XLSX/PPTXです。実際の一覧はAIの許可に従います。今回の合成実測はPNG・TXT、偽PNG、非対応形式、過大ファイルです。Office文書の内容抽出は未実測です。

添付だけの相談も送信できます。ファイルはWordPressの公開メディアへ登録せず、既存の会話別の非公開APIへ送ります。保存確認まで相談の送信を止め、未到達を照会できた場合だけ同じ確認IDで再試行します。保存後に通信が切れた場合は保存済み結果を取得し、二重アップロードしません。未送信の添付を取り除くときは専用DELETEの完了を確認します。

社内添付はnonce・本人接続・AI・サイト・現在の会話を確認します。公開添付は同一サイト・レート制限・お客様向けAI・HttpOnly訪問者cookie・サーバー保持の会話用tokenを確認します。会話ID・添付IDの持込みで別訪問者のファイルを読めません。

## 訪問者と保存の境界

公開相談は同一サイト確認・入力上限・既存の毎分レート制限を通過させます。cookie・サイト・公開AIで訪問者を区別し、会話専用tokenはサーバー側の期限付き保存に移しました。会話IDとtokenを別訪問者が持ち込んでも履歴や会話を継続しません。ブラウザーには接続token・資料同期キー・会話専用tokenを保存しません。

管理画面の本文は本人のWordPressセッションとAI別にタブ内で15分、公開本文は訪問者とブロック種別ごとにタブ内で24時間保存します。公開本文の保存はcookieによる会話の所有確認を代替しません。送信結果は24時間有効で、期限後の定期処理で削除します。古い送信キーと未来時刻のキーを拒否するため、実行記録の削除後に古い依頼を再実行できません。重要な確認操作の監査記録は従来の実行記録に残します。

**互換性変更:** 公開RESTには `request_id` が必要で、`ミリ秒時刻:UUID` の形式です。管理画面では本人のsession APIが返す `thread_id` も必要です。旧版のブラウザー保存tokenと公開会話を引き継がないため、メジャー開発版としています。PHPとJavaScriptを同時に更新してください。WordPress.orgの既存stable tagや既存リリースは変更していません。

## 実施した検証

今回の責務整理と、同条件での性能測定・残る制約は[相談画面の責務とローカル性能確認](chat-architecture-and-performance.md)に記録しています。

|対象|結果と実際の範囲|
|---|---|
|既存WordPressのREST契約|43項目成功。本人接続・AI保存と撤回・投稿情報と画面名の選択・連続会話・二重送信・旧会話拒否・正式確認・投稿者の公開拒否・不明結果・別訪問者の履歴拒否・JSONイベントの入れ子の秘密除外を実RESTで検証|
|本機Edgeの実画面|26項目成功、JavaScript例外0。WordPressの実HTMLと実RESTを操作。連続会話、二重クリック、受信停止、戻る操作・再読込、通信未到達からの復旧、AI切替、確認見送りと承認、暗色・1365px/390px、公開履歴・別訪問者を検証|
|管理画面の浮窓|[結果JSON](evidence/dock-ui-results.json)。未設定、本人設定、30回の開閉、ページの共用DOM、送信中の閉じる操作、通常リンク・戻る、文脈選択、明示確認・結果不明、390px・暗色・高さ500px、標準投稿編集、認証失効を検証|
|回答と添付|23項目成功、JavaScript例外0。[結果JSON](evidence/rich-ui-results.json)。実NDJSONの最初の断片を完了より1.713秒前に観測。表・コードのコピー、Mermaid実描画と拡大、安全表示、実multipart進捗・削除・通信断からの復旧・非公開画像復元・別訪問者のファイル拒否を検証|
|合成応答の範囲|Studio一覧、会話応答、中央操作のプレビュー・実行結果を合成。別のローカルHTTPサーバーからNDJSON・添付を実転送。中央ツールからの実業務更新・実AI・実Studioとの逐次応答は未実測。UIで成功した合成確認を実業務の完了とは扱わない|
|静的・単体|PHP構文、WordPress Coding Standards全24ファイル、公開リクエスト保護34項目、実行記録、送信期限・構造化確認の単体検証、JavaScript構文を実行。Windowsの改行を正規化した作業コピーで全体を確認し、PHPの改行規則をGit属性にも固定|
|既存契約の回帰|ネイティブ業務22項目、Studio選択10項目を実WordPressで再実行。設定・署名・権限と公開結果の絞込みを維持|
|画面証跡|[結果JSON](evidence/chat-ui-results.json)、[管理画面](evidence/chat-admin-desktop.png)、[管理画面390px](evidence/chat-admin-narrow.png)、[暗色390px](evidence/chat-admin-dark-narrow.png)、[結果不明](evidence/chat-admin-unknown.png)、[公開画面](evidence/chat-public-desktop.png)、[公開画面390px](evidence/chat-public-narrow.png)、[公開暗色390px](evidence/chat-public-dark-narrow.png)、[既存テーマ内の公開画面](evidence/chat-public-page-desktop.png)|

専用のCUA/コンピューター操作ツールはこの実行環境では公開されていません。本機のEdgeをPlaywrightで操作し、保存した画像を目視で確認しました。テーマのナビゲーションを残し、余白、メッセージ幅、行高、長文、スクロール、入力、確認カードを調整しています。

今回の追加証跡は[浮窓の狭い画面](evidence/dock-tool-narrow.png)、[標準編集画面](evidence/dock-native-editor.png)、[表の狭い画面](evidence/rich-table-narrow.png)、[図の拡大](evidence/rich-diagram-expanded.png)、[添付プレビュー](evidence/rich-attachment-preview.png)、[公開添付](evidence/rich-public-attachment-narrow.png)です。コピー検証では本機のクリップボードを変更せず、ブラウザー内の合成Clipboardへ書きました。実スマートフォンのIME・仮想キーボードは未実測です。

共有Python側でも、審査後の完成回答を12文字ごとに分割して再生する処理を削除しました。事前に現在の商品事実を確認した読み取り専用のEC-CUBE経路では生成中の本文増分を配信します。一般のお客様向けAIは従来どおり全回答の審査を行い、その後に確定メッセージを一括で返します。対応条件、stream非対応のモデル、取消・timeout・バッファの制限は[共有Pythonの逐次応答契約](../../../python/docs/customer-streaming-contract.md)を参照してください。実Studio・実モデルを使うWordPressとの総合接続試験は未実施です。

本文増分がない審査経路用に `tests/reviewed-chat-ui-e2e.cjs` と合成HTTPの応答を追加しました。2026年10月2日に実WordPress・本機Edge・合成HTTPで5項目すべて成功し、管理・公開画面の審査待ち、審査前の本文非表示、確定本文の一回表示、JavaScript例外を確認しています。既存3スクリプトも同じ環境で再実行し、ページ26・浮窓26・表示と添付23・審査5の計80項目、JavaScript例外0、画面証跡27枚を確認しました。

当初はWindows bind mountでPHP画面がtimeoutとなりました。再接続後にHTTPが回復したため、その時点の基線も測定しています。ユーザー承認を受け、統合リポジトリの `scripts/wordpress-runtime.ps1` で唯一のWindows checkoutから内容ハッシュ別のLinuxコードvolumeへ同期し、読取専用で実行しました。同期・ファイル削除の反映・冪等性・元のmountへの回復を確認しています。コピー対象から必須の`assets/vendor`を除外した初回の不備も修正し、最終受入では全静的ファイルを含めて再検証しました。[回帰記録](evidence/backend-streaming-regression.json)と統合リポジトリの `docs/wordpress-local-runtime.md` を参照してください。

追加証跡は[管理画面の審査待ち](evidence/staff-review-waiting.png)、[管理画面の確定回答](evidence/staff-reviewed-final.png)、[公開画面の審査待ち](evidence/public-review-waiting.png)、[公開画面の確定回答](evidence/public-reviewed-final.png)、[結果JSON](evidence/reviewed-chat-ui-results.json)です。モデル生成や審査自体は合成応答です。全試行後に設定ハッシュの一致、合成担当者・記事・MUローダー・一時認証JSON・専用応答コンテナーの不在を確認しました。

## ローカルでの再実行

既存のWordPressだけを使用します。WooCommerceの追加インストール、他サービスの起動、実データの初期化は不要です。

1. 既存の検証イメージで `tests/rich-upstream.php` をPHPのHTTPサーバーとして一時起動します。既存のDockerネットワーク内だけで、コンテナー名を `fourmix-wp-rich-fixture`、ポートを8080にします。ホストへの公開やDBは不要です。
2. `wordpress-init` に明示的に `wp eval-file .../tests/chat-ui-fixture.php prepare-rich` を渡し、JSONをGit外の一時ファイルへ保存します。既定の初期化commandは実行しません。
   実行用コピーを使う場合は、WP-CLIにも `-f .local/wordpress-runtime.compose.yaml` を追加し、応答サーバーには同じコードvolumeを読取専用でマウントします。浮窓検証はこの追加設定を自動検出します。
3. `wp eval-file .../tests/chat-contract-integration.php` を実行します。
4. 既存のPlaywrightを `FMI_PLAYWRIGHT_MODULE` で指定し、`node tests/chat-ui-e2e.cjs <一時JSON> <証跡先>`、`dock-ui-e2e.cjs`、`rich-ui-e2e.cjs`、`reviewed-chat-ui-e2e.cjs`を順番に実行します。本機Edgeを使用します。
5. 結果にかかわらず `wp eval-file .../tests/chat-ui-fixture.php cleanup` を実行し、一時JSONを削除し、専用の合成HTTPコンテナーを停止します。設定・読み込みファイルの同時変更を検知した場合は上書きせず停止します。

合成検証用MUローダーは一時的に読み込むファイルで、プラグイン本体からは読み込みません。検証中はモデル以外の外部HTTPとメールも止めます。検証の終了・復元後に普段のサイト設定へ戻ります。APIの支払いや外部公開は実行しません。

## 最終確認で補った履歴の復元

「履歴を読み込む」で既知の画像・通常ファイルの表示と次の依頼候補が消える問題を修正しました。サーバーの履歴に添付メタデータがない場合は、同じ会話のブラウザー内履歴と対応が確認できる添付を保ちます。同文が複数あり対応位置も確定しない場合は、別の添付を推測して付けません。サーバーが返す次の依頼候補を復元します。また、読込中に新しい会話を始めた場合、送信を開始した場合、表示部品を破棄した場合は、遅れて返る旧履歴を反映しません。

実製品のchat.js・attachments.jsとEdge、合成HTTPで7条件を検証しました。画像プレビュー、通常ファイルの取得内容、次の依頼候補、重複文の誤照合防止、遅れて返る履歴による新しい会話の上書き防止、JavaScript例外0件を含みます。実モデル・資格情報・業務更新は使用していません。実行は`node tests/history-ui-e2e.cjs <証拠の出力先>`、証拠は統合開発ルートの`.local/media-uploads-20261002/wp-history-final/`です。

サーバー側の公開会話履歴は現在、メッセージごとの添付情報を返していません。この修正の添付復元は同じブラウザーで既知のメタデータが残っている場合が対象です。別ブラウザーやブラウザー保存の消去後に、サーバー履歴だけから添付カードを再構成できるとは扱いません。
