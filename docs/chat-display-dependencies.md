# 相談画面の表示部品とライセンス

Fourmix Intelligenceの主体Appに既にある依存を再利用しています。実行時にCDNへ接続せず、配布ZIPにJavaScriptとライセンスを同梱します。新しい依存のインストールは行っていません。

|部品|版|同梱ファイル|ライセンス|
|---|---|---|---|
|markdown-it|14.3.2|`assets/vendor/markdown-it.min.js`|MIT・`markdown-it.LICENSE.txt`|
|highlight.js|11.12.0|`assets/vendor/highlight.min.js`|BSD-3-Clause・`highlight.LICENSE.txt`|
|Mermaid|11.17.2|`assets/vendor/mermaid.min.js`|MIT・`mermaid.LICENSE.txt`|

markdown-itとMermaidは既存Appの配布用ファイルをそのままコピーしています。highlight.jsは既存のcommon言語集合を、Appにあるrolldown 1.2.8でIIFEへまとめています。`node tests/build-chat-vendor.mjs`でネットワークを使用せず再作成できます。Mermaidは図があるときだけ読み込みます。

MarkdownのHTMLは無効です。Mermaidはstrict設定に加えて設定指示・外部参照・クリック処理を拒否し、描画したSVGは権限のないsandbox iframeとCSP内で表示します。モデルが指定した画像は自動取得せず、利用者が開くリンクにします。添付画像は所有確認済みのcontent APIからBlobとして表示します。

公式アイコンはAppの `packages/platform-app/src/assets/brand/fourmix-intelligence-shopify-icon-1200.png` をバイト単位で再利用し、`assets/brand/fourmix-intelligence-icon.png`に保存しました。SHA-256は次の値です。

```text
3fa8ebe998db651151f7441e8682ae2cd83f8023e66e3d9a4da23f5ea7977cb3
```

ロゴの再描画、配色変更、CSSフィルター、透明度の変更は行っていません。
