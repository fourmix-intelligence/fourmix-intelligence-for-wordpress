<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\ChatSession;
use FourmixIntelligence\WordPress\Support\Options;
use FourmixIntelligence\WordPress\Support\PublicRequestGuard;
use WP_REST_Request;
use WP_REST_Response;

final class ConversationController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) ); }
	public function routes(): void {
		foreach ( array( 'session', 'run_status', 'new_conversation' ) as $action ) {
			register_rest_route(
				'fourmix-intelligence/v1',
				'/public/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => '__return_true',
				)
			);
		}
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
			'/chat_stream',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat_stream' ),
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

	public function chat_stream( WP_REST_Request $request ): WP_REST_Response {
		return \FourmixIntelligence\WordPress\Support\StreamResponse::create( fn( $emit ) => $this->chat( $request, $emit ) );
	}

	public function chat( WP_REST_Request $request, ?callable $emit = null ): WP_REST_Response {
		$denied = $this->access_error( $request );
		if ( null !== $denied ) {
			return $denied;
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
		try {
			$this->require_customer_agent();
			$scope = ChatSession::visitor();
			$key   = ChatSession::key( (string) $request->get_param( 'request_id' ) );
			$this->continue_conversation( $request, $body, $scope );
			$ids = (array) $request->get_param( 'attachment_ids' );
			if ( $ids ) {
				$body['messages'][0]['attachment_ids'] = AttachmentController::validate_ids( $ids, $this->attachment_access( $request ) );
			}
			$response = ChatSession::run(
				$scope,
				$key,
				array(
					'operation' => 'customer.chat',
					'body'      => $body,
				),
				function () use ( $body, $scope, $emit ) {
					$path        = '/api/v3/ai/plugins/' . Options::public_agent() . '/runs';
					$public_emit = $emit ? static function ( $event ) use ( $emit ) {
						if ( in_array( $event['type'] ?? '', array( 'run.created', 'assistant.delta', 'assistant.message', 'run.status', 'status' ), true ) ) {
							$emit( $event );
						}
					} : null;
					$response    = $public_emit ? ( new Client() )->stream( $path . '/stream', $body, null, $public_emit ) : ( new Client() )->post( $path, $body );
					$id          = (string) ( $response['conversation_id'] ?? '' );
					$secret      = (string) ( $response['customer_token'] ?? '' );
					if ( preg_match( '/^[a-f0-9-]{36}$/iD', $id ) && preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) {
						$history = 'history' === Options::get( 'conversation_mode', 'history' );
						$ttl     = $history ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS;
						set_transient( 'fmi_customer_' . hash( 'sha256', $scope . ':' . $id ), $secret, $ttl );
					}
					unset( $response['customer_token'], $response['conversation_token'] );
					return $response;
				}
			);
			$kind     = $body['options']['page']['kind'];
			if ( in_array( $kind, array( 'product-recommendation', 'frequently-bought-together', 'cart-assistant' ), true ) ) {
				$this->hydrate_products( $response );
			} else {
				$this->hydrate_content( $response );
			}
			return $this->reply( $response );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'message' => 'ただいまご案内を準備できません。時間をおいてお試しください。' ), 502 ); }
	}

	public function session( WP_REST_Request $request ): WP_REST_Response {
		$denied = $this->access_error( $request );
		if ( $denied ) {
			return $denied;
		}
		try {
			$manifest = $this->require_customer_agent();
			return $this->reply(
				array(
					'scope'       => ChatSession::visitor(),
					'agent'       => Options::public_agent(),
					'attachments' => \FourmixIntelligence\WordPress\Support\Attachments::policy( (array) ( $manifest['attachments'] ?? array() ) ),
				)
			);
		} catch ( \Throwable $error ) {
			return $this->reply( array( 'message' => __( '現在、このAI案内は準備中です。', 'fourmix-intelligence' ) ), 403 );
		}
	}

	public function run_status( WP_REST_Request $request ): WP_REST_Response {
		$denied = $this->access_error( $request );
		if ( $denied ) {
			return $denied;
		}
		try {
			$this->require_customer_agent();
			$response = ChatSession::status( ChatSession::visitor(), (string) $request->get_param( 'request_id' ) );
			if ( isset( $response['response'] ) ) {
				$this->hydrate( $response['response'] );
			}
			return $this->reply( $response );
		} catch ( \Throwable $error ) {
			return $this->reply( array( 'message' => __( '送信結果を確認できませんでした。', 'fourmix-intelligence' ) ), 403 );
		}
	}

	private function reply( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( \FourmixIntelligence\WordPress\Support\StreamResponse::clean( $data ), $status, array( 'Cache-Control' => 'private, no-store' ) );
	}
	public function new_conversation( WP_REST_Request $request ): WP_REST_Response {
		$denied = $this->access_error( $request );
		if ( $denied ) {
			return $denied; }
		try {
			$this->require_customer_agent();
			delete_transient( 'fmi_customer_prepare_' . ChatSession::visitor() );
			return $this->reply( array( 'fresh' => true ) );
		} catch ( \Throwable $error ) {
			return $this->reply( array( 'message' => __( '新しい相談を準備できませんでした。', 'fourmix-intelligence' ) ), 403 );
		}
	}

	public function history( WP_REST_Request $request ): WP_REST_Response {
		if ( 'history' !== Options::get( 'conversation_mode', 'history' ) ) {
			return new WP_REST_Response( array(), 403 );
		}
		$denied = $this->access_error( $request );
		if ( null !== $denied ) {
			return $denied;
		}
		$body = array();
		if ( $request->get_param( 'before_id' ) ) {
			$body['before_id'] = absint( $request->get_param( 'before_id' ) );
		}
		try {
			$this->require_customer_agent();
			$this->continue_conversation( $request, $body, ChatSession::visitor() );
			if ( empty( $body['conversation_id'] ) ) {
				throw new \RuntimeException( 'missing_conversation' );
			}
			$response = ( new Client() )->post( '/api/v3/ai/plugins/' . Options::public_agent() . '/customer-history', $body );
			foreach ( (array) ( $response['messages'] ?? array() ) as $index => $message ) {
				$payload = array( 'result' => array( 'data' => $message['data'] ?? array() ) );
				$this->hydrate( $payload );
				$response['messages'][ $index ]['data'] = $payload['result']['data'];
			}
			return $this->reply( $response ); } catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'message' => '会話履歴を読み込めませんでした。' ), 502 ); }
	}

	private function hydrate( array &$response ): void {
		$items = (array) ( $response['result']['data']['items'] ?? array() );
		if ( isset( $items[0]['product_id'] ) || isset( $items[0]['sku'] ) ) {
			$this->hydrate_products( $response );
		} else {
			$this->hydrate_content( $response );
		}
	}

	private function require_customer_agent(): array {
		$manifest = ( new Client() )->request( 'GET', '/api/v3/ai/plugins/' . rawurlencode( Options::public_agent() ) . '/metadata' );
		if ( 'customer' !== ( $manifest['audience'] ?? '' ) ) {
			throw new \RuntimeException( esc_html__( 'お客様向けAIを選択してください。', 'fourmix-intelligence' ) );
		}
		return $manifest;
	}
	public function attachment_access( WP_REST_Request $request, bool $create = false ): array {
		if ( $this->access_error( $request ) ) {
			throw new \RuntimeException( 'attachment_origin' );
		}
		$manifest = $this->require_customer_agent();
		$policy   = \FourmixIntelligence\WordPress\Support\Attachments::policy( (array) ( $manifest['attachments'] ?? array() ) );
		$scope    = ChatSession::visitor();
		$body     = array();
		$this->continue_conversation( $request, $body, $scope );
		$id     = (string) ( $body['conversation_id'] ?? '' );
		$secret = (string) ( $body['customer_token'] ?? '' );
		if ( $create && ! $id && $policy['enabled'] ) {
			$prepared = (array) get_transient( 'fmi_customer_prepare_' . $scope );
			if ( ! empty( $prepared['id'] ) ) {
				$id     = $prepared['id'];
				$secret = (string) get_transient( 'fmi_customer_' . hash( 'sha256', $scope . ':' . $id ) );
			}
			if ( ! $secret ) {
				$value  = ( new Client() )->post( '/api/v3/ai/plugins/' . rawurlencode( Options::public_agent() ) . '/customer-conversation', array() );
				$id     = (string) ( $value['conversation_id'] ?? '' );
				$secret = (string) ( $value['customer_token'] ?? '' );
				if ( ! \FourmixIntelligence\WordPress\Support\Attachments::uuid( $id ) || ! preg_match( '/^[a-f0-9]{64}$/D', $secret ) ) {
					throw new \RuntimeException( 'attachment_conversation' );
				}
				set_transient( 'fmi_customer_' . hash( 'sha256', $scope . ':' . $id ), $secret, DAY_IN_SECONDS );
				set_transient( 'fmi_customer_prepare_' . $scope, array( 'id' => $id ), 15 * MINUTE_IN_SECONDS );
			}
		}
		return array(
			'token'           => (string) Options::get( 'token', '' ),
			'agent'           => Options::public_agent(),
			'scope'           => $scope,
			'thread_id'       => '',
			'conversation_id' => $id,
			'policy'          => $policy,
			'headers'         => $secret ? array( 'X-Fourmix-Customer-Token' => $secret ) : array(),
		);
	}

	private function access_error( WP_REST_Request $request ): ?WP_REST_Response {
		if ( ! PublicRequestGuard::same_origin( (string) $request->get_header( 'origin' ), home_url() ) ) {
			do_action( 'fourmix_intelligence_public_request_denied', 'origin_mismatch' );
			return new WP_REST_Response( array( 'message' => __( 'このサイトからアクセスしてください。', 'fourmix-intelligence' ) ), 403 );
		}
		$proxies = defined( 'FOURMIX_INTELLIGENCE_TRUSTED_PROXIES' ) ? constant( 'FOURMIX_INTELLIGENCE_TRUSTED_PROXIES' ) : array();
		$address = PublicRequestGuard::client_ip(
			sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			(string) $request->get_header( 'x-forwarded-for' ),
			is_array( $proxies ) ? $proxies : array()
		);
		if ( null === $address ) {
			do_action( 'fourmix_intelligence_public_request_denied', 'invalid_client_ip' );
			return new WP_REST_Response( array( 'message' => __( 'アクセス元を確認できませんでした。', 'fourmix-intelligence' ) ), 403 );
		}
		if ( ! $this->rate_limit( $address ) ) {
			do_action( 'fourmix_intelligence_public_request_denied', 'rate_limited' );
			return new WP_REST_Response( array( 'message' => __( 'しばらく待ってから、もう一度お試しください。', 'fourmix-intelligence' ) ), 429 );
		}
		return null;
	}
	private function rate_limit( string $address ): bool {
		$key   = 'fmi_rate_' . hash_hmac( 'sha256', (string) inet_pton( $address ), wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
	/** @param array<string, mixed> $body */
	private function continue_conversation( WP_REST_Request $request, array &$body, string $scope ): void {
		$id = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );
		if ( ! $id ) {
			return;
		}
		$token = get_transient( 'fmi_customer_' . hash( 'sha256', $scope . ':' . $id ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/iD', $id ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			throw new \RuntimeException( esc_html__( 'この訪問者の会話を確認できません。新しい相談を始めてください。', 'fourmix-intelligence' ) );
		}
		if ( $id ) {
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
		if ( ! function_exists( 'wc_get_product' ) ) {
			if ( isset( $response['result']['data']['items'] ) ) {
				$response['result']['data']['items'] = array();
			}
			return;
		}
		if ( ! isset( $response['result']['data']['items'] ) || ! is_array( $response['result']['data']['items'] ) ) {
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
			if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() || post_password_required( $product->get_id() ) ) {
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

	private function hydrate_content( array &$response ): void {
		if ( ! isset( $response['result']['data']['items'] ) || ! is_array( $response['result']['data']['items'] ) ) {
			return;
		}
		$verified = array();
		foreach ( array_slice( $response['result']['data']['items'], 0, 12 ) as $item ) {
			$post = is_array( $item ) ? get_post( absint( $item['post_id'] ?? $item['id'] ?? 0 ) ) : null;
			if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) || ! is_post_type_viewable( $post->post_type ) ) {
				continue;
			}
			$verified[] = array(
				'post_id'     => $post->ID,
				'name'        => get_the_title( $post ),
				'product_url' => get_permalink( $post ),
				'reason'      => sanitize_text_field( (string) ( $item['reason'] ?? '' ) ),
			);
		}
		$response['result']['data']['items'] = $verified;
	}
}
