<?php

namespace FourmixIntelligence\WordPress\Blocks;

use FourmixIntelligence\WordPress\Support\Options;

final class BlockRegistry {
	private const BLOCKS = array( 'ai-concierge', 'site-search', 'related-content', 'product-recommendation', 'frequently-bought-together', 'cart-assistant' );
	public function register(): void {
		add_action( 'init', array( $this, 'blocks' ) ); }
	public function blocks(): void {
		add_shortcode( 'fourmix_intelligence_chat', array( $this, 'shortcode' ) );
		\FourmixIntelligence\WordPress\Support\ChatAssets::register();
		wp_set_script_translations( 'fourmix-intelligence-chat', 'fourmix-intelligence' );
		wp_register_style( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.css', array(), FOURMIX_INTELLIGENCE_VERSION );
		wp_register_script( 'fourmix-intelligence-editor', FOURMIX_INTELLIGENCE_URL . 'assets/editor.js', array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_localize_script( 'fourmix-intelligence-editor', 'FourmixIntelligenceBrand', array( 'icon' => FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ) );
		wp_register_script( 'fourmix-intelligence-view', FOURMIX_INTELLIGENCE_URL . 'assets/view.js', array( 'fourmix-intelligence-chat' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-view', 'fourmix-intelligence' );
		wp_register_style( 'fourmix-intelligence-blocks', FOURMIX_INTELLIGENCE_URL . 'assets/blocks.css', array( 'fourmix-intelligence-attachments' ), FOURMIX_INTELLIGENCE_VERSION );
		wp_localize_script(
			'fourmix-intelligence-view',
			'FourmixIntelligenceSettings',
			array(
				'endpoint'           => esc_url_raw( rest_url( 'fourmix-intelligence/v1/chat' ) ),
				'streamEndpoint'     => esc_url_raw( rest_url( 'fourmix-intelligence/v1/chat_stream' ) ),
				'historyEndpoint'    => esc_url_raw( rest_url( 'fourmix-intelligence/v1/history' ) ),
				'sessionEndpoint'    => esc_url_raw( rest_url( 'fourmix-intelligence/v1/public/session' ) ),
				'statusEndpoint'     => esc_url_raw( rest_url( 'fourmix-intelligence/v1/public/run_status' ) ),
				'attachmentEndpoint' => esc_url_raw( rest_url( 'fourmix-intelligence/v1/public/' ) ),
				'conversationMode'   => Options::get( 'conversation_mode', 'history' ),
				'enabled'            => Options::enabled(),
				'agent'              => Options::public_agent(),
				'addToCartEndpoint'  => class_exists( 'WooCommerce' ) ? esc_url_raw( \WC_AJAX::get_endpoint( 'add_to_cart' ) ) : '',
			)
		);
		foreach ( self::BLOCKS as $block ) {
			register_block_type(
				FOURMIX_INTELLIGENCE_DIR . 'blocks/' . $block,
				array(
					'editor_script'   => 'fourmix-intelligence-editor',
					'view_script'     => 'fourmix-intelligence-view',
					'style'           => 'fourmix-intelligence-blocks',
					'render_callback' => array( $this, 'render' ),
				)
			);
		}
	}

	public function shortcode( array $attributes = array() ): string {
		wp_enqueue_script( 'fourmix-intelligence-view' );
		wp_enqueue_style( 'fourmix-intelligence-blocks' );
		$attributes = shortcode_atts( array( 'title' => __( 'AIに相談', 'fourmix-intelligence' ) ), $attributes, 'fourmix_intelligence_chat' );
		return sprintf( '<section class="fmi-block fmi-block--ai-concierge" data-fmi-kind="ai-concierge" data-fmi-auto="0"><div class="fmi-block__header">%1$s<h2>%2$s</h2></div><div class="fmi-block__body"></div></section>', $this->brand_icon(), esc_html( $attributes['title'] ) );
	}

	public function render( array $attributes, string $content, \WP_Block $block ): string {
		$kind = substr( $block->name, strrpos( $block->name, '/' ) + 1 );
		if ( in_array( $kind, array( 'product-recommendation', 'frequently-bought-together', 'cart-assistant' ), true ) && ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		$title   = $attributes['title'] ?? $this->title( $kind );
		$auto    = isset( $attributes['automatic'] ) ? (bool) $attributes['automatic'] : in_array( $kind, array( 'related-content', 'frequently-bought-together', 'cart-assistant' ), true );
		$wrapper = get_block_wrapper_attributes(
			array(
				'class'         => 'fmi-block fmi-block--' . sanitize_html_class( $kind ),
				'data-fmi-kind' => $kind,
				'data-fmi-auto' => $auto ? '1' : '0',
			)
		);
		return sprintf( '<section %1$s><div class="fmi-block__header">%2$s<h2>%3$s</h2></div><div class="fmi-block__body" aria-live="polite"></div></section>', $wrapper, $this->brand_icon(), esc_html( $title ) );
	}
	private function brand_icon(): string {
		return '<img class="fmi-brand-icon" src="' . esc_url( FOURMIX_INTELLIGENCE_URL . 'assets/brand/fourmix-intelligence-icon.png' ) . '" alt="Fourmix Intelligence" width="32" height="32">';
	}
	private function title( string $kind ): string {
		return array(
			'ai-concierge'               => 'AIに相談',
			'site-search'                => '知りたいことをAIに質問',
			'related-content'            => 'あわせて読みたい情報',
			'product-recommendation'     => 'あなたに合う商品をご案内',
			'frequently-bought-together' => '一緒に選ばれている商品',
			'cart-assistant'             => '購入前の最終チェック',
		)[ $kind ] ?? 'AIに相談';
	}
}
