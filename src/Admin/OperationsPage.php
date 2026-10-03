<?php

namespace FourmixIntelligence\WordPress\Admin;

final class OperationsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function menu(): void {
		add_menu_page( 'Fourmix Intelligence', 'Fourmix Intelligence', 'edit_posts', 'fourmix-intelligence-operations', array( $this, 'render' ), FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png', 80 );
		add_submenu_page( 'fourmix-intelligence-operations', __( 'WordPressの業務操作', 'fourmix-intelligence' ), __( 'WordPressの業務操作', 'fourmix-intelligence' ), 'edit_posts', 'fourmix-intelligence-native-operations', array( $this, 'render_native' ) );
	}
	public function meta_box(): void {
		add_meta_box( 'fourmix-intelligence-context', 'Fourmix Intelligence', array( $this, 'context_link' ), array( 'post', 'page' ), 'side' );
	}
	public function context_link( \WP_Post $post ): void {
		printf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=fourmix-intelligence-operations&post_id=' . $post->ID ) ), esc_html__( 'この内容についてAIに相談', 'fourmix-intelligence' ) );
	}
	public function assets( string $hook ): void {
		if ( 'fourmix-intelligence_page_fourmix-intelligence-native-operations' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'fourmix-intelligence-admin', FOURMIX_INTELLIGENCE_URL . 'assets/admin.js', array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-admin', 'fourmix-intelligence' );
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
			<h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence 運営支援', 'fourmix-intelligence' ); ?></h1>
			<p class="fmi-introduction"><?php esc_html_e( '業務についてAIに相談できます。操作が必要な場合は、内容を確認してから実行します。', 'fourmix-intelligence' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) ); ?>"><?php esc_html_e( '本人のAI設定', 'fourmix-intelligence' ); ?></a></p>
			<div id="fmi-chat-page"><div id="fmi-chat"></div></div>
		</div>
		<?php
	}
	public function render_native(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap" id="fmi-native-operations">
			<h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence WordPressの業務操作', 'fourmix-intelligence' ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fourmix-intelligence-operations' ) ); ?>"><?php esc_html_e( '選択した Studio AI に相談', 'fourmix-intelligence' ); ?></a></p>
			<section class="fmi-manual-operations"><p><?php esc_html_e( '本人に許可された操作を直接確認できます。変更する場合はプレビュー後に承認してください。', 'fourmix-intelligence' ); ?></p><p id="fmi-modules"></p>
				<div id="fmi-result" role="status" aria-live="polite"></div><form id="fmi-operation"><p><label for="fmi-action"><?php esc_html_e( '操作', 'fourmix-intelligence' ); ?></label> <select id="fmi-action"></select></p><div id="fmi-fields"></div><button class="button" type="submit" disabled><?php esc_html_e( '内容をプレビュー', 'fourmix-intelligence' ); ?></button></form>
				<pre id="fmi-preview"></pre><button class="button button-primary" id="fmi-confirm" type="button" hidden><?php esc_html_e( '内容を確認して実行', 'fourmix-intelligence' ); ?></button>
			</section>
		</div>
		<?php
	}
}
