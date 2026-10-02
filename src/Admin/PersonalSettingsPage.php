<?php

namespace FourmixIntelligence\WordPress\Admin;

/** サイト全体の公開接続とは別に、本人の短期接続を管理します。 */
final class PersonalSettingsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function menu(): void {
		add_submenu_page( 'fourmix-intelligence-operations', __( '本人のAI設定', 'fourmix-intelligence' ), __( '本人のAI設定', 'fourmix-intelligence' ), 'edit_posts', 'fourmix-intelligence-personal-settings', array( $this, 'render' ) );
	}
	public function assets( string $hook ): void {
		if ( 'fourmix-intelligence_page_fourmix-intelligence-personal-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'fourmix-intelligence-personal-settings', FOURMIX_INTELLIGENCE_URL . 'assets/personal-settings.js', array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-personal-settings', 'fourmix-intelligence' );
		wp_localize_script(
			'fourmix-intelligence-personal-settings',
			'FourmixIntelligencePersonalSettings',
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
		<div class="wrap fmi-personal-settings">
			<h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence 本人のAI設定', 'fourmix-intelligence' ); ?></h1>
			<p><?php esc_html_e( '本人の接続と、利用する社内向けAIを設定します。この接続は現在のWordPressログインに結び付け、15分で期限が切れます。サイトの公開AI設定とは共用しません。', 'fourmix-intelligence' ); ?></p>
			<section class="fmi-connection">
				<h2><?php esc_html_e( '本人の接続', 'fourmix-intelligence' ); ?></h2>
				<form id="fmi-connect"><label for="fmi-personal-token"><?php esc_html_e( '本人のアクセストークン', 'fourmix-intelligence' ); ?></label><div class="fmi-token-row"><input id="fmi-personal-token" type="password" autocomplete="off" required><button type="submit" class="button"><?php esc_html_e( 'AI一覧を取得', 'fourmix-intelligence' ); ?></button></div></form>
				<div class="fmi-agent-row"><label for="fmi-staff-agent"><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></label><select id="fmi-staff-agent" disabled></select></div>
				<p class="description"><?php esc_html_e( 'AI Studioで作成した本人が利用できるAIだけを選択できます。選択は保存し、利用時に権限を再確認します。', 'fourmix-intelligence' ); ?></p>
				<p id="fmi-personal-status" role="status" aria-live="polite"></p>
			</section>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fourmix-intelligence-operations' ) ); ?>"><?php esc_html_e( '相談画面を開く', 'fourmix-intelligence' ); ?></a>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=fourmix-intelligence' ) ); ?>"><?php esc_html_e( 'サイト全体の連携設定', 'fourmix-intelligence' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}
}
