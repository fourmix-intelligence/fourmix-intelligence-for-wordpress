<?php

namespace FourmixIntelligence\WordPress\Privacy;

final class PrivacyIntegration {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'policy' ) ); }
	public function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( '当サイトでは、AIによる案内を提供するため、利用者が入力した内容、会話識別子、閲覧中のページ情報を Fourmix Intelligence へ送信する場合があります。送信目的、保存期間、問い合わせ先は当サイトの運営方針に合わせて追記してください。接続トークンや資料同期キーが閲覧者へ送信されることはありません。', 'fourmix-intelligence' ) . '</p>';
		wp_add_privacy_policy_content( 'Fourmix Intelligence', wp_kses_post( wpautop( $content ) ) );
		wp_add_privacy_policy_content( 'Fourmix Intelligence', '<p>' . esc_html__( '添付に対応するAIでは、利用者が選択した画像・文書とファイル名・サイズを、本人または訪問者の会話に結び付けてFourmix Intelligenceへ送信します。WordPressの公開メディアには登録しません。送信前のプレビューはブラウザー内で生成し、タブには復元用の添付情報だけを一時保存します。未送信の添付は取り除く操作で削除を確認します。送信済みファイルの保存期間、削除方法、利用目的はFourmix Intelligence側の設定とサイトの運営方針に合わせて案内してください。', 'fourmix-intelligence' ) . '</p>' );
		wp_add_privacy_policy_content( 'Fourmix Intelligence', '<p>' . esc_html__( '公開相談では、会話を訪問者本人に結び付けるためHttpOnly・SameSiteの確認用cookieを使用します。履歴モードでは最長30日、一時会話ではブラウザーを閉じるまで有効です。会話専用トークンはサイトのサーバーに保持し、ブラウザーへ渡しません。会話本文はタブ内に一時保存する場合があります。通信断後の重複送信を防ぐサイト内の送信結果は、24時間程度経過後にWordPressの定期処理で削除します。Fourmix Intelligence側の会話保存期間は、設定と運営方針に合わせて別途案内してください。', 'fourmix-intelligence' ) . '</p>' );
	}
}
