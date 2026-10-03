<?php

namespace FourmixIntelligence\WordPress\Admin;

/** 共有の業務接続を利用するためのログインと AI 選択を管理します。 */
final class PersonalSettingsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function menu(): void {
		add_submenu_page( 'fourmix-intelligence-operations', __( '社内向けAIの設定', 'fourmix-intelligence' ), __( '社内向けAIの設定', 'fourmix-intelligence' ), 'edit_posts', 'fourmix-intelligence-personal-settings', array( $this, 'render' ) );
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
			<h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence 社内向けAIの設定', 'fourmix-intelligence' ); ?></h1>
			<p><?php esc_html_e( '管理者が登録した業務接続を使い、Fourmix Intelligence の現在のログインとワークスペースの権限で社内向け AI を利用します。共有アカウントを使う場合、履歴と監査はそのアカウントに記録されます。', 'fourmix-intelligence' ); ?></p>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 通知の表示だけで設定や認可を変更しません。
			if ( 'cancelled' === sanitize_key( wp_unslash( $_GET['fmi_identity'] ?? '' ) ) ) :
				?>
				<p role="status"><?php esc_html_e( 'ログインをキャンセルしました。未完了の操作は実行していません。', 'fourmix-intelligence' ); ?></p><?php endif; ?>
			<section class="fmi-connection">
				<h2><?php esc_html_e( 'Fourmix Intelligence のログイン', 'fourmix-intelligence' ); ?></h2>
				<a id="fmi-connect" class="button button-primary" href="<?php echo esc_url( \FourmixIntelligence\WordPress\Support\NativeIdentity::login_url( admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) ) ); ?>"><?php esc_html_e( 'Fourmix Intelligence にログイン', 'fourmix-intelligence' ); ?></a>
				<div class="fmi-agent-row"><label for="fmi-staff-agent"><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></label><select id="fmi-staff-agent" disabled></select></div>
				<p class="description"><?php esc_html_e( 'ワークスペースで公開され、この業務接続を利用できる社内向けAIから選択します。選択はログイン中のアカウントごとに保存し、利用時に権限を再確認します。', 'fourmix-intelligence' ); ?></p>
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
