# AGENTS.md

## 製品名と言語

- 製品名は必ず「Fourmix Intelligence」と表記する。「Fourmix」だけをサービス名として使わない。
- README、設定画面、ブロック、エラー、コミット、リリースノートなどの成果物は、日本企業のお客様が読める自然な日本語で作成する。WordPress.org が英語を必須とする `readme.txt` だけは例外とし、日本語の README も併記する。
- WordPress.org の国際化規約に従い、表示文字列は翻訳可能にする。

## WordPress 方針

- WordPress Coding Standards、Settings API、REST API、Block API、Privacy API を優先する。
- WooCommerce は任意連携とし、未導入のサイトでも本体を正常動作させる。
- フロントエンドへ接続トークンや資料同期キーを渡さない。
- 公開 REST API は同一サイト確認、レート制限、入力上限を持たせる。
- 商品在庫などの変動情報は索引へ頻繁に同期せず、表示時に WooCommerce から確認する。
- 破壊的変更は CHANGELOG に記載し、SemVer に従う。

## 検証

- PHP 構文、WordPress Coding Standards、JavaScript、ZIP 内容を確認してからリリースする。
- 実運用の秘密や会話専用トークンをテストデータ、ログ、Git に含めない。
