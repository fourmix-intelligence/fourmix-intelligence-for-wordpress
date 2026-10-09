<?php

namespace FourmixIntelligence\WordPress\Admin;

/** 本人権限のある通常の管理画面にだけ、相談用の共通UIを置きます。 */
final class StaffChat {
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_footer', array( $this, 'dock' ) );
	}
	public function assets(): void {
		if ( is_network_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$screen  = get_current_screen();
		$post_id = 0;
		if ( 'post' === $screen->base && ! empty( $GLOBALS['post']->ID ) ) {
			$post_id = (int) $GLOBALS['post']->ID;
		} elseif ( 'toplevel_page_fourmix-intelligence-operations' === $screen->id ) {
			$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 参照候補だけを取得し、RESTで本人の対象権限を再確認します。
		}
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}
		$title = get_admin_page_title();
		if ( 'edit' === $screen->base ) {
			$title = 'page' === $screen->post_type ? __( '固定ページ一覧', 'fourmix-intelligence' ) : __( '投稿一覧', 'fourmix-intelligence' );
		} elseif ( $post_id ) {
			$title = get_the_title( $post_id );
		}
		\FourmixIntelligence\WordPress\Support\ChatAssets::register();
		$page_chat = 'toplevel_page_fourmix-intelligence-operations' === $screen->id;
		if ( $page_chat ) {
			wp_enqueue_script( 'fourmix-intelligence-chat' );
		}
		wp_enqueue_script( 'fourmix-intelligence-staff-chat', FOURMIX_INTELLIGENCE_URL . 'assets/staff-chat.js', $page_chat ? array( 'fourmix-intelligence-chat' ) : array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION . '-' . substr( hash_file( 'sha256', FOURMIX_INTELLIGENCE_DIR . 'assets/staff-chat.js' ), 0, 12 ), true );
		wp_set_script_translations( 'fourmix-intelligence-staff-chat', 'fourmix-intelligence' );
		wp_enqueue_style( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.css', array(), \FourmixIntelligence\WordPress\Support\ChatAssets::version( 'assets/chat.css' ) );
		wp_enqueue_style( 'fourmix-intelligence-answer' );
		wp_enqueue_style( 'fourmix-intelligence-attachments' );
		wp_enqueue_style( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.css', array( 'fourmix-intelligence-chat' ), \FourmixIntelligence\WordPress\Support\ChatAssets::version( 'assets/admin.css' ) );
		wp_enqueue_style( 'fourmix-intelligence-dock', FOURMIX_INTELLIGENCE_URL . 'assets/dock.css', array( 'fourmix-intelligence-admin' ), FOURMIX_INTELLIGENCE_VERSION . '-' . substr( hash_file( 'sha256', FOURMIX_INTELLIGENCE_DIR . 'assets/dock.css' ), 0, 12 ) );
		wp_localize_script(
			'fourmix-intelligence-staff-chat',
			'FourmixIntelligenceStaff',
			array(
				'endpoint'                => rest_url( 'fourmix-intelligence/v1/staff/' ),
				'nonce'                   => wp_create_nonce( 'wp_rest' ),
				'uiScope'                 => hash_hmac( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . wp_get_session_token(), wp_salt( 'auth' ) ),
				'settingsUrl'             => admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ),
				'chatUrl'                 => admin_url( 'admin.php?page=fourmix-intelligence-operations' ),
				'loginConfigurationReady' => \FourmixIntelligence\WordPress\Support\NativeIdentity::login_configuration_ready(),
				'setupMessage'            => \FourmixIntelligence\WordPress\Support\NativeIdentity::setup_message(),
				'setupAdminMessage'       => \FourmixIntelligence\WordPress\Support\NativeIdentity::setup_admin_message(),
				'setupSettingsLabel'      => \FourmixIntelligence\WordPress\Support\NativeIdentity::setup_settings_label(),
				'setupSettingsUrl'        => current_user_can( 'manage_options' ) ? \FourmixIntelligence\WordPress\Support\NativeIdentity::setup_settings_url() : '',
				'scripts'                 => \FourmixIntelligence\WordPress\Support\ChatAssets::lazy_scripts(),
				'mermaidUrl'              => FOURMIX_INTELLIGENCE_URL . 'assets/vendor/mermaid.min.js',
				'context'                 => array(
					'screen'  => $screen->id,
					'title'   => wp_strip_all_tags( $title ),
					'post_id' => $post_id,
				),
			)
		);
	}
	public function dock(): void {
		if ( is_network_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div id="fmi-dock">
			<section id="fmi-dock-panel" class="fmi-dock-panel" role="dialog" aria-modal="false" aria-labelledby="fmi-dock-title" hidden>
				<header class="fmi-dock-header"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="28" height="28"><span id="fmi-dock-title">Fourmix Intelligence</span><button id="fmi-dock-close" type="button" aria-label="<?php esc_attr_e( '相談を閉じる', 'fourmix-intelligence' ); ?>">×</button></header>
				<div id="fmi-dock-body"></div>
			</section>
			<button id="fmi-dock-toggle" type="button" aria-expanded="false" aria-controls="fmi-dock-panel" aria-label="<?php esc_attr_e( 'Fourmix Intelligenceの相談を開く', 'fourmix-intelligence' ); ?>"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"></button>
		</div>
		<?php
	}
}
