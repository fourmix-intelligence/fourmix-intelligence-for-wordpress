<?php

namespace FourmixIntelligence\WordPress\Admin;

use FourmixIntelligence\WordPress\Support\Options;

final class SettingsPage {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'settings' ) );
		add_action( 'update_option_fourmix_intelligence_settings', array( $this, 'updated' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( FOURMIX_INTELLIGENCE_FILE ), array( $this, 'action_links' ) );
	}

	public function menu(): void {
		add_options_page( 'Fourmix Intelligence', 'Fourmix Intelligence', 'manage_options', 'fourmix-intelligence', array( $this, 'render' ) );
	}

	public function updated( mixed $previous, mixed $current ): void {
		if ( is_array( $previous ) && is_array( $current ) && ! hash_equals( (string) ( $previous['bridge_secret'] ?? '' ), (string) ( $current['bridge_secret'] ?? '' ) ) ) {
			delete_option( 'fourmix_intelligence_bridge_binding' );
		}
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
			add_settings_error( 'fourmix_intelligence_settings', 'bridge_secret', __( '業務接続の共有キーは32文字以上で入力してください。', 'fourmix-intelligence' ) );
			$bridge_secret = (string) ( $current['bridge_secret'] ?? '' );
		}
		if ( '' !== $bridge_secret && ! hash_equals( (string) ( $current['bridge_secret'] ?? '' ), $bridge_secret ) ) {
			unset( $current['native_tenant'], $current['native_connection'], $current['platform_url'], $current['portal_url'] );
		}
		return array(
			'platform_url'      => (string) ( $current['platform_url'] ?? '' ),
			'portal_url'        => (string) ( $current['portal_url'] ?? '' ),
			'native_tenant'     => (string) ( $current['native_tenant'] ?? '' ),
			'native_connection' => (string) ( $current['native_connection'] ?? '' ),
			'url'               => esc_url_raw( (string) ( $current['url'] ?? 'https://mcp.ai.fourmix.co.jp' ) ),
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

	/** 管理画面だけで接続状態を案内し、保存済み設定や秘密値は変更しません。 */
	private function customer_catalog( array $options ): array {
		$state = array(
			'items'              => array(),
			'preserve_selection' => true,
			'message'            => '',
		);
		if ( empty( $options['token'] ) ) {
			$state['message'] = __( '公開トークンを設定すると、お客様向けAIの一覧を取得できます。', 'fourmix-intelligence' );
			return $state;
		}
		try {
			$state['items']              = ( new \FourmixIntelligence\WordPress\Http\Client() )->catalog( 'customer' );
			$state['preserve_selection'] = ! in_array( $options['agent'] ?? '', wp_list_pluck( $state['items'], 'name' ), true );
			if ( $state['preserve_selection'] && ! empty( $options['agent'] ) ) {
				$state['message'] = __( '保存済みのAIを現在の公開トークンで確認できません。Studioの公開状態と利用範囲を確認してください。現在の選択は保持しています。', 'fourmix-intelligence' );
			}
		} catch ( \Throwable $error ) {
			$state['message'] = match ( (int) $error->getCode() ) {
				401 => __( '公開トークンの認証を確認できません。無効または期限切れの可能性があります。Studioで公開トークンを確認し、必要に応じて上の欄で更新してください。現在の選択は保持しています。', 'fourmix-intelligence' ),
				403 => __( 'この公開トークンではAI一覧を取得できません。Studioの公開状態と利用範囲を確認してください。現在の選択は保持しています。', 'fourmix-intelligence' ),
				default => __( '接続先からAI一覧を取得できませんでした。接続先と通信状態を確認し、時間をおいてページを再読み込みしてください。現在の選択は保持しています。', 'fourmix-intelligence' ),
			};
		}
		return $state;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$options    = (array) get_option( 'fourmix_intelligence_settings', array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		global $wpdb;
		$sync_target = hash( 'sha256', rtrim( (string) Options::get( 'url', 'https://platform.ai.fourmix.co.jp/intelligence' ), '/' ) . "\n" . (string) Options::get( 'dataset' ) );
		$held_sync   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE target_hash <> %s', $wpdb->prefix . 'fourmix_intelligence_outbox', $sync_target ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		?>
		<div class="wrap"><h1 class="fmi-brand-heading"><img src="<?php echo esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ); ?>" alt="" width="36" height="36"><?php esc_html_e( 'Fourmix Intelligence 連携設定', 'fourmix-intelligence' ); ?></h1>
		<p><?php esc_html_e( 'サイトの案内、AI接客、コンテンツ同期を一つの設定で管理します。秘密情報はブラウザーへ公開されません。', 'fourmix-intelligence' ); ?></p>
		<?php if ( $held_sync ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( '送信先が異なる、または確認できない同期記録を保留しています。現在の送信先へ自動転送しません。同期したい公開内容を確認し、再保存してください。', 'fourmix-intelligence' ); ?></p></div>
		<?php endif; ?>
		<form action="options.php" method="post"><?php settings_fields( 'fourmix_intelligence' ); ?>
		<table class="form-table" role="presentation">
		<?php
		$binding = \FourmixIntelligence\WordPress\Support\NativeConnectionBinding::status();
		?>
		<tr><th><?php esc_html_e( '接続状態', 'fourmix-intelligence' ); ?></th><td><strong><?php echo esc_html( $binding['connected'] ? $binding['workspace_name'] : __( '未接続', 'fourmix-intelligence' ) ); ?></strong><p class="description"><?php esc_html_e( '下記の共有キーと実行ユーザーを設定し、Fourmix IntelligenceのワークスペースでこのサイトのURLと共有キーを入力して接続してください。ログイン先と接続情報は自動で同期されます。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><label for="fmi-token"><?php esc_html_e( 'お客様向けAIの公開トークン', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-token" name="fourmix_intelligence_settings[token]" type="password" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( empty( $options['token'] ) ? __( 'お客様向けAIを公開する場合のみ入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"></td></tr>
		<tr><th><label for="fmi-agent"><?php esc_html_e( 'お客様向けAI', 'fourmix-intelligence' ); ?></label></th><td><select id="fmi-agent" name="fourmix_intelligence_settings[agent]"><option value=""><?php esc_html_e( 'Studioで作成したAIを選択', 'fourmix-intelligence' ); ?></option>
		<?php
		$catalog_state = $this->customer_catalog( $options );
		foreach ( $catalog_state['items'] as $agent ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $agent['name'] ), selected( $options['agent'] ?? '', $agent['name'], false ), esc_html( $agent['service_name'] ?? $agent['name'] ) );
		}
		if ( $catalog_state['preserve_selection'] && ! empty( $options['agent'] ) ) {
			echo '<option value="' . esc_attr( $options['agent'] ) . '" selected>' . esc_html__( '現在の選択（保存済み）', 'fourmix-intelligence' ) . '</option>';
		}
		?>
		</select>
		<?php if ( $catalog_state['message'] ) : ?>
		<p class="description" role="status"><?php echo esc_html( $catalog_state['message'] ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'AIはすべてFourmix IntelligenceのStudioで作成します。ここでは公開するAIだけを選びます。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( '社内向けAI', 'fourmix-intelligence' ); ?></th><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=fourmix-intelligence-personal-settings' ) ); ?>"><?php esc_html_e( '社内向けAIの設定を開く', 'fourmix-intelligence' ); ?></a><p class="description"><?php esc_html_e( 'Fourmix Intelligence にログイン後、ワークスペースの社内向けAIから選択します。', 'fourmix-intelligence' ); ?></p></td></tr>
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
		<tr><th><label for="fmi-bridge-secret"><?php esc_html_e( '業務接続の共有キー', 'fourmix-intelligence' ); ?></label></th><td><input class="regular-text" id="fmi-bridge-secret" name="fourmix_intelligence_settings[bridge_secret]" type="password" minlength="32" autocomplete="new-password" placeholder="<?php echo esc_attr( empty( $options['bridge_secret'] ) ? __( '32文字以上の共有キーを入力', 'fourmix-intelligence' ) : __( '設定済み（変更する場合のみ入力）', 'fourmix-intelligence' ) ); ?>"><p class="description"><?php esc_html_e( 'Fourmix Intelligenceのワークスペース接続にも同じ共有キーを設定します。別の環境へ接続し直す場合は、新しい共有キーを保存してください。接続情報だけが解除され、業務データと会話履歴は削除されません。', 'fourmix-intelligence' ); ?></p></td></tr>
		<tr><th><?php esc_html_e( '接続に許可する業務', 'fourmix-intelligence' ); ?></th><td>
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
