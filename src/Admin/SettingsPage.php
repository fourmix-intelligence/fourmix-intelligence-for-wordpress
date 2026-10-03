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
		$input   = is_array( $input ) ? $input : array();
		$current = (array) get_option( 'fourmix_intelligence_settings', array() );
		$agent   = sanitize_key( (string) ( $input['agent'] ?? '' ) );
		$token   = '' !== (string) ( $input['token'] ?? '' ) ? sanitize_text_field( (string) $input['token'] ) : (string) ( $current['token'] ?? '' );
		if ( '' !== $agent && ( $current['agent'] ?? '' ) !== $agent ) {
			try {
				$catalog = ( new \FourmixIntelligence\WordPress\Http\Client() )->catalog( 'customer', $token );
				if ( ! in_array( $agent, wp_list_pluck( $catalog, 'name' ), true ) ) {
					throw new \RuntimeException( esc_html__( '利用できるお客様向けStudio AIを選択してください。', 'fourmix-intelligence' ) );
				}
			} catch ( \Throwable $error ) {
				add_settings_error( 'fourmix_intelligence_settings', 'agent', $error->getMessage() );
				$agent = (string) ( $current['agent'] ?? '' );
			}
		}
		$bridge_secret = (string) ( $input['bridge_secret'] ?? '' );
		if ( '' !== $bridge_secret && strlen( $bridge_secret ) < 32 ) {
			add_settings_error( 'fourmix_intelligence_settings', 'bridge_secret', __( 'FinCube接続共有キーは32文字以上で入力してください。', 'fourmix-intelligence' ) );
			$bridge_secret = (string) ( $current['bridge_secret'] ?? '' );
		}
		if ( '' !== $bridge_secret && ! hash_equals( (string) ( $current['bridge_secret'] ?? '' ), $bridge_secret ) ) {
			delete_option( 'fourmix_intelligence_bridge_binding' );
		}
		return array(
			'platform_url'      => esc_url_raw( (string) ( $input['platform_url'] ?? $current['platform_url'] ?? 'https://platform.ai.fourmix.co.jp' ) ),
			'portal_url'        => esc_url_raw( (string) ( $input['portal_url'] ?? $current['portal_url'] ?? 'https://ai.fourmix.co.jp' ) ),
			'native_tenant'     => sanitize_text_field( (string) ( $input['native_tenant'] ?? $current['native_tenant'] ?? '' ) ),
			'native_connection' => sanitize_text_field( (string) ( $input['native_connection'] ?? $current['native_connection'] ?? '' ) ),
			'url'               => esc_url_raw( (string) ( $input['url'] ?? 'https://mcp.ai.fourmix.co.jp' ) ),
			'token'             => $token,
			'agent'             => $agent,
			'internal_agent'    => sanitize_key( (string) ( $input['internal_agent'] ?? '' ) ),
			'bridge_user_id'    => absint( $input['bridge_user_id'] ?? 0 ),
			'dataset'           => sanitize_text_field( (string) ( $input['dataset'] ?? '' ) ),
			'sync_token'        => '' !== (string) ( $input['sync_token'] ?? '' ) ? sanitize_text_field( (string) $input['sync_token'] ) : (string) ( $current['sync_token'] ?? '' ),
			'sync_enabled'      => ! empty( $input['sync_enabled'] ),
			'conversation_mode' => in_array( $input['conversation_mode'] ?? '', array( 'temporary', 'history' ), true ) ? $input['conversation_mode'] : 'history',
			'sync_post_types'   => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['sync_post_types'] ?? array() ) ), get_post_types( array( 'public' => true ) ) ) ),
			'bridge_secret'     => '' !== $bridge_secret ? sanitize_text_field( $bridge_secret ) : (string) ( $current['bridge_secret'] ?? '' ),
			'bridge_groups'     => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['bridge_groups'] ?? array() ) ), array( 'content', 'media', 'users', 'products', 'orders', 'customers', 'coupons' ) ) ),
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
		<div class="wrap"><h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence 連携設定', 'fourmix-intelligence' ); ?></h1>
		<p><?php esc_html_e( 'サイトの案内、AI接客、コンテンツ同期を一つの設定で管理します。秘密情報はブラウザーへ公開されません。', 'fourmix-intelligence' ); ?></p>
		<form action="options.php" method="post"><?php settings_fields( 'fourmix_intelligence' ); ?>
		<table class="form-table" role="presentation">
		<?php
		foreach ( array(
			'platform_url'      => '認証 API の URL',
			'portal_url'        => 'ログイン画面の URL',
			'native_tenant'     => 'テナント ID',
			'native_connection' => '共有の業務接続 ID',
		) as $key => $label ) :
			?>
		<tr><th><label for="fmi-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input class="regular-text" id="fmi-<?php echo esc_attr( $key ); ?>" name="fourmix_intelligence_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $options[ $key ] ?? '' ); ?>"></td></tr>
		<?php endforeach; ?>
		<tr><th><label for="fmi-url"><?php esc_html_e( '接続先', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-url" name="fourmix_intelligence_settings[url]" type="url" value="<?php echo esc_attr( $options['url'] ?? 'https://mcp.ai.fourmix.co.jp' ); ?>"></td></tr>
		<tr><th><label for="fmi-token"><?php esc_html_e( '接続トークン', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-token" name="fourmix_intelligence_settings[token]" type="password" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( empty( $options['token'] ) ? __( '接続トークンを入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"></td></tr>
		<tr><th><label for="fmi-agent"><?php esc_html_e( 'お客様向けAI', 'fourmix-intelligence' ); ?></label></th><td><select id="fmi-agent" name="fourmix_intelligence_settings[agent]"><option value=""><?php esc_html_e( 'Studioで作成したAIを選択', 'fourmix-intelligence' ); ?></option>
		<?php
		try {
			foreach ( ( new \FourmixIntelligence\WordPress\Http\Client() )->catalog( 'customer' ) as $agent ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $agent['name'] ), selected( $options['agent'] ?? '', $agent['name'], false ), esc_html( $agent['service_name'] ?? $agent['name'] ) );
			}
		} catch ( \Throwable $error ) {
			echo '<option value="' . esc_attr( $options['agent'] ?? '' ) . '" selected>' . esc_html__( '接続後にAI一覧を取得します', 'fourmix-intelligence' ) . '</option>';
		}
		?>
		</select><p class="description"><?php esc_html_e( 'AIはすべてFourmix IntelligenceのStudioで作成します。ここでは公開するAIだけを選びます。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></th><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) ); ?>"><?php esc_html_e( '本人のAI設定を開く', 'fourmix-intelligence' ); ?></a><p class="description"><?php esc_html_e( '本人が接続した後、Studioの社内向けAIから選択します。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( '接続の実行ユーザー', 'fourmix-intelligence' ); ?></th><td>
		<?php
		wp_dropdown_users(
			array(
				'name'              => 'fourmix_intelligence_settings[bridge_user_id]',
				'selected'          => $options['bridge_user_id'] ?? 0,
				'show_option_none'  => __( '未設定（業務接続は停止）', 'fourmix-intelligence' ),
				'option_none_value' => 0,
			)
		);
		?>
				<p class="description"><?php esc_html_e( '専用ユーザーのWordPress権限と、下記で選択した業務の両方を実行時に確認します。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( '会話の続け方', 'fourmix-intelligence' ); ?></th><td><select name="fourmix_intelligence_settings[conversation_mode]"><option value="history" <?php selected( $options['conversation_mode'] ?? 'history', 'history' ); ?>><?php esc_html_e( 'ページを移動しても会話を続ける', 'fourmix-intelligence' ); ?></option><option value="temporary" <?php selected( $options['conversation_mode'] ?? '', 'temporary' ); ?>><?php esc_html_e( 'この画面を開いている間だけ続ける', 'fourmix-intelligence' ); ?></option></select></td></tr>
		<tr><th><?php esc_html_e( '資料の自動同期', 'fourmix-intelligence' ); ?></th><td><label><input type="checkbox" name="fourmix_intelligence_settings[sync_enabled]" value="1" <?php checked( ! empty( $options['sync_enabled'] ) ); ?>> <?php esc_html_e( '公開内容の変更を資料庫へ自動同期する', 'fourmix-intelligence' ); ?></label><p class="description"><?php esc_html_e( '有効にするまで資料は送信されません。', 'fourmix-intelligence' ); ?></p></td></tr>
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
			'content'   => '投稿・固定ページ',
			'media'     => 'メディア',
			'users'     => '利用者',
			'products'  => 'WooCommerce商品',
			'orders'    => 'WooCommerce注文',
			'customers' => 'WooCommerce顧客（ID・表示名）',
			'coupons'   => 'WooCommerceクーポン',
		) as $key => $label ) :
			?>
			<label style="display:block"><input type="checkbox" name="fourmix_intelligence_settings[bridge_groups][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) ( $options['bridge_groups'] ?? array() ), true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?><p class="description"><?php esc_html_e( '選んだ業務だけが署名付きで公開され、実行時にも再確認されます。', 'fourmix-intelligence' ); ?></p></td></tr>
		</table><?php submit_button( __( '設定を保存', 'fourmix-intelligence' ) ); ?></form></div>
		<?php
	}
}
