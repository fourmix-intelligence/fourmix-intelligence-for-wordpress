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
		printf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=fourmix-intelligence-operations&post_id=' . $post->ID ) ), esc_html__( 'この投稿についてAIに相談', 'fourmix-intelligence' ) );
	}
	public function assets( string $hook ): void {
		if ( 'toplevel_page_fourmix-intelligence-operations' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.js', array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-chat', 'fourmix-intelligence' );
		wp_enqueue_script( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.js', array( 'fourmix-intelligence-chat' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-admin', 'fourmix-intelligence' );
		wp_enqueue_style( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.css', array(), FOURMIX_INTELLIGENCE_VERSION );
		wp_enqueue_style( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.css', array( 'fourmix-intelligence-chat' ), FOURMIX_INTELLIGENCE_VERSION );
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
		<div class="wrap" id="fmi-operations">
			<h1><?php esc_html_e( 'Fourmix Intelligence 運営支援', 'fourmix-intelligence' ); ?></h1>
			<p class="fmi-introduction"><?php esc_html_e( 'AI Studioで作成した社内向けAIに相談できます。公開AIとは別に、本人の接続とWordPressの権限で利用します。', 'fourmix-intelligence' ); ?></p>
			<section class="fmi-connection" aria-labelledby="fmi-connection-title">
				<h2 id="fmi-connection-title"><?php esc_html_e( '利用するAI', 'fourmix-intelligence' ); ?></h2>
				<details id="fmi-token-panel" open><summary><?php esc_html_e( '本人の接続・接続し直す', 'fourmix-intelligence' ); ?></summary><form id="fmi-connect">
					<label for="fmi-personal-token"><?php esc_html_e( '本人のアクセストークン（接続は15分間）', 'fourmix-intelligence' ); ?></label>
					<div class="fmi-token-row"><input id="fmi-personal-token" type="password" autocomplete="off" required><button class="button" type="submit"><?php esc_html_e( 'AI一覧を取得', 'fourmix-intelligence' ); ?></button></div>
				</form></details>
				<div class="fmi-agent-row"><label for="fmi-staff-agent"><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></label><select id="fmi-staff-agent" disabled></select></div>
				<div id="fmi-context" class="fmi-context" hidden><label><input type="checkbox" id="fmi-include-context"> <span id="fmi-context-label"></span></label><p class="description"><?php esc_html_e( 'チェックした場合のみ、投稿ID・種類・タイトル・状態を送信します。本文は含みません。', 'fourmix-intelligence' ); ?></p></div>
			</section>
			<div id="fmi-result" role="status" aria-live="polite"></div>
			<div id="fmi-chat"><p class="fmi-chat-placeholder"><?php esc_html_e( '本人のAIに接続し、相談するAIを選択してください。', 'fourmix-intelligence' ); ?></p></div>
			<details class="fmi-manual-operations">
				<summary><?php esc_html_e( 'WordPressの操作を直接確認する', 'fourmix-intelligence' ); ?></summary>
				<p><?php esc_html_e( 'AIへの相談を使わず、許可された操作を選んで内容を確認できます。', 'fourmix-intelligence' ); ?></p><p id="fmi-modules"></p>
				<form id="fmi-operation"><p><label for="fmi-action"><?php esc_html_e( '操作', 'fourmix-intelligence' ); ?></label> <select id="fmi-action"></select></p><div id="fmi-fields"></div><button class="button" type="submit" disabled><?php esc_html_e( '内容をプレビュー', 'fourmix-intelligence' ); ?></button></form>
				<pre id="fmi-preview"></pre><button class="button button-primary" id="fmi-confirm" type="button" hidden><?php esc_html_e( '内容を確認して実行', 'fourmix-intelligence' ); ?></button>
			</details>
		</div>
		<?php
	}
}
