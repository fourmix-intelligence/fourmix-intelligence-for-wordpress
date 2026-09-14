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
	}
}
