<?php

namespace FourmixIntelligence\WordPress\Admin;

final class OperationsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function menu(): void {
		add_menu_page( 'Fourmix Intelligence', 'Fourmix Intelligence', 'edit_posts', 'fourmix-intelligence-operations', array( $this, 'render' ), 'dashicons-format-chat', 80 );
	}
	public function meta_box(): void {
		add_meta_box( 'fourmix-intelligence-context', 'Fourmix Intelligence', array( $this, 'context_link' ), array( 'post', 'page' ), 'side' );
	}
	public function context_link( \WP_Post $post ): void {
		printf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=fourmix-intelligence-operations&post_id=' . $post->ID ) ), esc_html__( 'この内容についてAIに相談', 'fourmix-intelligence' ) );
	}
	public function assets( string $hook ): void {
		if ( 'toplevel_page_fourmix-intelligence-operations' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.js', array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-admin', 'fourmix-intelligence' );
		wp_enqueue_style( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.css', array(), FOURMIX_INTELLIGENCE_VERSION );
		wp_localize_script(
			'fourmix-intelligence-admin',
			'FourmixIntelligenceAdmin',
			array(
				'endpoint' => rest_url( 'fourmix-intelligence/v1/staff/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap" id="fmi-operations" style="max-width:1000px">
		<h1><?php esc_html_e( 'Fourmix Intelligence 運営支援', 'fourmix-intelligence' ); ?></h1>
		<p><?php esc_html_e( 'Studioで作成した社内向けAIに相談し、許可された業務を確認して実行します。公開AIとは別の本人接続を使用します。', 'fourmix-intelligence' ); ?></p>
		<div class="card" style="max-width:100%;margin:20px 0;padding:20px">
		<h2><?php esc_html_e( '本人のAIに接続', 'fourmix-intelligence' ); ?></h2>
		<form id="fmi-connect"><label for="fmi-personal-token"><?php esc_html_e( '本人のアクセストークン（15分だけ使用）', 'fourmix-intelligence' ); ?></label><p class="fmi-token-row"><input id="fmi-personal-token" type="password" autocomplete="off" class="regular-text" required> <button class="button" type="submit"><?php esc_html_e( 'AI一覧を取得', 'fourmix-intelligence' ); ?></button></p></form>
		<form id="fmi-chat"><p><label for="fmi-staff-agent"><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></label> <select id="fmi-staff-agent" required></select></p><label for="fmi-message"><?php esc_html_e( '依頼内容', 'fourmix-intelligence' ); ?></label><p><textarea id="fmi-message" class="large-text" rows="4" maxlength="5000" required></textarea></p><button class="button button-primary" type="submit"><?php esc_html_e( 'AIに相談', 'fourmix-intelligence' ); ?></button></form>
		</div>
		<div class="card" style="max-width:100%;margin:20px 0;padding:20px"><h2><?php esc_html_e( '業務操作の確認', 'fourmix-intelligence' ); ?></h2><p id="fmi-modules"></p><form id="fmi-operation"><p><label for="fmi-action"><?php esc_html_e( '操作', 'fourmix-intelligence' ); ?></label> <select id="fmi-action"></select></p><div id="fmi-fields"></div><button class="button" type="submit"><?php esc_html_e( '内容をプレビュー', 'fourmix-intelligence' ); ?></button></form><pre id="fmi-preview" style="white-space:pre-wrap;overflow-wrap:anywhere"></pre><button class="button button-primary" id="fmi-confirm" type="button" hidden><?php esc_html_e( '内容を確認して実行', 'fourmix-intelligence' ); ?></button></div>
		<div id="fmi-result" role="status" aria-live="polite" style="white-space:pre-wrap;overflow-wrap:anywhere;margin:20px 0"></div>
		<p><?php esc_html_e( 'AI側で確認が必要な操作はFourmix Intelligenceの確認画面で承認してください。結果不明の更新を繰り返さないでください。', 'fourmix-intelligence' ); ?></p>
		</div>
		<?php
	}
}
