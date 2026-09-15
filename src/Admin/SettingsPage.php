<?php

namespace FourmixIntelligence\WordPress\Admin;

final class SettingsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FOURMIX_INTELLIGENCE_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		add_options_page( 'Fourmix Intelligence', 'Fourmix Intelligence', 'manage_options', 'fourmix-intelligence', array( $this, 'render' ) );
	}

	public function settings(): void {
		register_setting(
			'fourmix_intelligence',
			'fourmix_intelligence_settings',
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'fourmix_intelligence',
			'fourmix_intelligence_remove_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	/** @param mixed $input @return array<string, mixed> */
	public function sanitize( mixed $input ): array {
		$input         = is_array( $input ) ? $input : array();
		$current       = (array) get_option( 'fourmix_intelligence_settings', array() );
		$bridge_secret = (string) ( $input['bridge_secret'] ?? '' );
		if ( '' !== $bridge_secret && strlen( $bridge_secret ) < 32 ) {
			add_settings_error( 'fourmix_intelligence_settings', 'bridge_secret', __( 'FinCube接続共有キーは32文字以上で入力してください。', 'fourmix-intelligence' ) );
			$bridge_secret = (string) ( $current['bridge_secret'] ?? '' );
		}
		if ( '' !== $bridge_secret && ! hash_equals( (string) ( $current['bridge_secret'] ?? '' ), $bridge_secret ) ) {
			delete_option( 'fourmix_intelligence_bridge_binding' );
		}
		return array(
			'url'               => esc_url_raw( (string) ( $input['url'] ?? 'https://mcp.ai.fourmix.co.jp' ) ),
			'token'             => '' !== (string) ( $input['token'] ?? '' ) ? sanitize_text_field( (string) $input['token'] ) : (string) ( $current['token'] ?? '' ),
			'agent'             => sanitize_key( (string) ( $input['agent'] ?? '' ) ),
			'dataset'           => sanitize_text_field( (string) ( $input['dataset'] ?? '' ) ),
			'sync_token'        => '' !== (string) ( $input['sync_token'] ?? '' ) ? sanitize_text_field( (string) $input['sync_token'] ) : (string) ( $current['sync_token'] ?? '' ),
			'conversation_mode' => in_array( $input['conversation_mode'] ?? '', array( 'temporary', 'history' ), true ) ? $input['conversation_mode'] : 'history',
			'sync_post_types'   => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['sync_post_types'] ?? array() ) ), get_post_types( array( 'public' => true ) ) ) ),
			'bridge_secret'     => '' !== $bridge_secret ? sanitize_text_field( $bridge_secret ) : (string) ( $current['bridge_secret'] ?? '' ),
			'bridge_groups'     => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['bridge_groups'] ?? array() ) ), array( 'content', 'media', 'users', 'products', 'orders', 'coupons' ) ) ),
		);
	}

	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=fourmix-intelligence' ) ) . '">' . esc_html__( '設定', 'fourmix-intelligence' ) . '</a>' );
		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$options    = (array) get_option( 'fourmix_intelligence_settings', array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Fourmix Intelligence 連携設定', 'fourmix-intelligence' ); ?></h1>
		<p><?php esc_html_e( 'サイトの案内、AI接客、コンテンツ同期を一つの設定で管理します。秘密情報はブラウザーへ公開されません。', 'fourmix-intelligence' ); ?></p>
		<form action="options.php" method="post"><?php settings_fields( 'fourmix_intelligence' ); ?>
		<table class="form-table" role="presentation">
		<tr><th><label for="fmi-url"><?php esc_html_e( '接続先', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-url" name="fourmix_intelligence_settings[url]" type="url" value="<?php echo esc_attr( $options['url'] ?? 'https://mcp.ai.fourmix.co.jp' ); ?>"></td></tr>
		<tr><th><label for="fmi-token"><?php esc_html_e( '接続トークン', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-token" name="fourmix_intelligence_settings[token]" type="password" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( empty( $options['token'] ) ? __( '接続トークンを入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"></td></tr>
		<tr><th><label for="fmi-agent"><?php esc_html_e( '利用するAI', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-agent" name="fourmix_intelligence_settings[agent]" value="<?php echo esc_attr( $options['agent'] ?? '' ); ?>"></td></tr>
		<tr><th><?php esc_html_e( '会話の続け方', 'fourmix-intelligence' ); ?></th><td><select name="fourmix_intelligence_settings[conversation_mode]"><option value="history" <?php selected( $options['conversation_mode'] ?? 'history', 'history' ); ?>><?php esc_html_e( 'ページを移動しても会話を続ける', 'fourmix-intelligence' ); ?></option><option value="temporary" <?php selected( $options['conversation_mode'] ?? '', 'temporary' ); ?>><?php esc_html_e( 'この画面を開いている間だけ続ける', 'fourmix-intelligence' ); ?></option></select></td></tr>
		<tr><th><label for="fmi-dataset"><?php esc_html_e( '資料庫ID', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-dataset" name="fourmix_intelligence_settings[dataset]" value="<?php echo esc_attr( $options['dataset'] ?? '' ); ?>"></td></tr>
		<tr><th><label for="fmi-sync-token"><?php esc_html_e( '資料同期キー', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-sync-token" name="fourmix_intelligence_settings[sync_token]" type="password" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( empty( $options['sync_token'] ) ? __( '資料同期キーを入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"></td></tr>
		<tr><th><?php esc_html_e( '同期する内容', 'fourmix-intelligence' ); ?></th><td>
		<?php
		foreach ( $post_types as $type ) :
			?>
			<label style="display:block"><input type="checkbox" name="fourmix_intelligence_settings[sync_post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) ( $options['sync_post_types'] ?? array( 'post', 'page', 'product' ) ), true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e( 'WooCommerceの商品在庫は索引へ同期せず、接客時に最新情報を確認します。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><label for="fmi-bridge-secret"><?php esc_html_e( 'FinCube接続共有キー', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-bridge-secret" name="fourmix_intelligence_settings[bridge_secret]" type="password" minlength="32" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $options['bridge_secret'] ) ? __( '32文字以上の共有キーを入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"><p class="description"><?php esc_html_e( 'Fourmix Intelligenceのワークスペース接続にも同じ共有キーを設定します。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( 'FinCubeへ許可する業務', 'fourmix-intelligence' ); ?></th><td>
		<?php
		foreach ( array(
			'content'  => '投稿・固定ページ',
			'media'    => 'メディア',
			'users'    => '利用者',
			'products' => 'WooCommerce商品',
			'orders'   => 'WooCommerce注文',
			'coupons'  => 'WooCommerceクーポン',
		) as $key => $label ) :
			?>
			<label style="display:block"><input type="checkbox" name="fourmix_intelligence_settings[bridge_groups][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) ( $options['bridge_groups'] ?? array() ), true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e( '選んだ業務だけが署名付きで公開され、実行時にも再確認されます。', 'fourmix-intelligence' ); ?></p></td></tr>
		</table><?php submit_button( __( '設定を保存', 'fourmix-intelligence' ) ); ?></form></div>
		<?php
	}
}
