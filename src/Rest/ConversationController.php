<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

final class ConversationController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) ); }
	public function routes(): void {
		register_rest_route(
			'fourmix-intelligence/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'fourmix-intelligence/v1',
			'/history',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function chat( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->same_origin( $request ) || ! $this->rate_limit() ) {
			return new WP_REST_Response( array( 'message' => 'しばらく待ってから、もう一度お試しください。' ), 429 );
		}
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '' === $message || mb_strlen( $message ) > 5000 ) {
			return new WP_REST_Response( array( 'message' => '入力内容を確認してください。' ), 422 );
		}
		$body = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $message,
				),
			),
			'options'  => array(
				'channel' => 'wordpress',
				'page'    => $this->context( $request ),
			),
		);
		$this->continue_conversation( $request, $body );
		try {
			$response = ( new Client() )->post( '/api/v3/ai/plugins/' . Options::get( 'agent' ) . '/runs', $body );
			$this->hydrate_products( $response );
			return new WP_REST_Response( $response, 200 );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'message' => 'ただいまご案内を準備できません。時間をおいてお試しください。' ), 502 ); }
	}

	public function history( WP_REST_Request $request ): WP_REST_Response {
		if ( 'history' !== Options::get( 'conversation_mode', 'history' ) || ! $this->same_origin( $request ) || ! $this->rate_limit() ) {
			return new WP_REST_Response( array(), 403 );
		}
		$body = array(
			'conversation_id' => sanitize_text_field( (string) $request->get_param( 'conversation_id' ) ),
			'customer_token'  => sanitize_text_field( (string) $request->get_param( 'customer_token' ) ),
		);
		if ( $request->get_param( 'before_id' ) ) {
			$body['before_id'] = absint( $request->get_param( 'before_id' ) );
		}
		try {
			return new WP_REST_Response( ( new Client() )->post( '/api/v3/ai/plugins/' . Options::get( 'agent' ) . '/customer-history', $body ), 200 ); } catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'message' => '会話履歴を読み込めませんでした。' ), 502 ); }
	}

	private function same_origin( WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		return $origin && strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	private function rate_limit(): bool {
		$address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$key     = 'fmi_rate_' . hash_hmac( 'sha256', $address, wp_salt( 'nonce' ) );
		$count   = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
	/** @param array<string, mixed> $body */
	private function continue_conversation( WP_REST_Request $request, array &$body ): void {
		$id    = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );
		$token = sanitize_text_field( (string) $request->get_param( 'customer_token' ) );
		if ( $id && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$body['conversation_id'] = $id;
			$body['customer_token']  = $token; }
	}
	/** @return array<string, mixed> */
	private function context( WP_REST_Request $request ): array {
		$context     = (array) $request->get_param( 'context' );
		$product_ids = array_slice( array_map( 'absint', (array) ( $context['product_ids'] ?? array() ) ), 0, 20 );
		if ( function_exists( 'WC' ) && \WC()->cart ) {
			$product_ids = array_values( array_unique( array_merge( $product_ids, array_map( static fn( $item ) => absint( $item['product_id'] ?? 0 ), \WC()->cart->get_cart() ) ) ) );
		}
		return array(
			'url'         => esc_url_raw( (string) ( $context['url'] ?? home_url() ) ),
			'title'       => sanitize_text_field( (string) ( $context['title'] ?? '' ) ),
			'kind'        => sanitize_key( (string) ( $context['kind'] ?? 'concierge' ) ),
			'product_ids' => array_slice( array_filter( $product_ids ), 0, 20 ),
		);
	}

	/** @param array<string, mixed> $response */
	private function hydrate_products( array &$response ): void {
		if ( ! function_exists( 'wc_get_product' ) || ! isset( $response['result']['data']['items'] ) || ! is_array( $response['result']['data']['items'] ) ) {
			return;
		}
		$verified = array();
		foreach ( array_slice( $response['result']['data']['items'], 0, 12 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = absint( $item['product_id'] ?? $item['id'] ?? 0 );
			if ( ! $id && ! empty( $item['sku'] ) ) {
				$id = absint( wc_get_product_id_by_sku( sanitize_text_field( (string) $item['sku'] ) ) );
			}
			$product = $id ? wc_get_product( $id ) : false;
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$verified[] = array_merge(
				$item,
				array(
					'product_id'  => $product->get_id(),
					'name'        => $product->get_name(),
					'product_url' => $product->get_permalink(),
					'price_html'  => $product->get_price_html(),
					'in_stock'    => $product->is_in_stock(),
					'purchasable' => $product->is_purchasable() && $product->is_in_stock(),
				)
			);
		}
		$response['result']['data']['items'] = $verified;
	}
}
