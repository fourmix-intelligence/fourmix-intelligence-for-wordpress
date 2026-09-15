<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

/** FinCubeへ、管理者が選んだWordPress業務だけを署名付きで公開します。 */
final class NativeBridgeController {
	private const GROUPS = array( 'content', 'media', 'users', 'products', 'orders', 'coupons' );

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) ); }
	public function routes(): void {
		register_rest_route(
			'fourmix-intelligence/v1',
			'/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'manifest' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);
		register_rest_route(
			'fourmix-intelligence/v1',
			'/actions/(?P<operation>[a-z][a-z0-9_.-]{2,127})',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'authenticate' ),
			)
		);
	}

	public function authenticate( WP_REST_Request $request ): bool|\WP_Error {
		$secret     = (string) Options::get( 'bridge_secret', '' );
		$timestamp  = (string) $request->get_header( 'x-fourmix-timestamp' );
		$nonce      = (string) $request->get_header( 'x-fourmix-nonce' );
		$workspace  = (string) $request->get_header( 'x-fourmix-workspace' );
		$connection = (string) $request->get_header( 'x-fourmix-connection' );
		$signature  = (string) $request->get_header( 'x-fourmix-signature' );
		$uuid       = '/^[0-9a-f-]{36}$/i';
		if ( strlen( $secret ) < 32 || ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 || ! preg_match( $uuid, $nonce ) || ! preg_match( $uuid, $workspace ) || ! preg_match( $uuid, $connection ) ) {
			return new \WP_Error( 'forbidden', __( '署名を確認できませんでした。', 'fourmix-intelligence' ), array( 'status' => 403 ) );
		}
		$key = 'fmi_nonce_' . hash( 'sha256', $nonce );
		if ( get_transient( $key ) ) {
			return new \WP_Error( 'replay', __( '同じ要求は再利用できません。', 'fourmix-intelligence' ), array( 'status' => 403 ) );
		}
		$canonical = implode( "\n", array( $timestamp, $nonce, $request->get_method(), '/wp-json' . $request->get_route(), $workspace, $connection, hash( 'sha256', (string) $request->get_body() ) ) );
		if ( ! hash_equals( 'v1=' . hash_hmac( 'sha256', $canonical, $secret ), $signature ) ) {
			return new \WP_Error( 'forbidden', __( '署名を確認できませんでした。', 'fourmix-intelligence' ), array( 'status' => 403 ) );
		}
		$bound = (array) get_option( 'fourmix_intelligence_bridge_binding', array() );
		if ( empty( $bound ) ) {
			update_option(
				'fourmix_intelligence_bridge_binding',
				array(
					'workspace'  => $workspace,
					'connection' => $connection,
				),
				false
			);
		} elseif ( ! hash_equals( (string) ( $bound['workspace'] ?? '' ), $workspace ) || ! hash_equals( (string) ( $bound['connection'] ?? '' ), $connection ) ) {
			return new \WP_Error( 'bound', __( 'このサイトは別のワークスペースへ接続済みです。', 'fourmix-intelligence' ), array( 'status' => 403 ) );
		}
		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public function manifest(): WP_REST_Response {
		$id = (string) get_option( 'fourmix_intelligence_application_id', '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			$id = wp_generate_uuid4();
			update_option( 'fourmix_intelligence_application_id', $id, false ); }
		return new WP_REST_Response(
			array(
				'protocol'     => 'fourmix-wordpress/1.0',
				'application'  => array(
					'id'      => $id,
					'version' => FOURMIX_INTELLIGENCE_VERSION,
				),
				'revision'     => 1,
				'capabilities' => $this->capabilities(),
			)
		);
	}

	private function definitions(): array {
		$obj  = static fn( array $properties = array(), array $required = array() ) => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => $required,
		);
		$id   = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$text = array(
			'type'      => 'string',
			'maxLength' => 50000,
		);
		return array(
			'content.list'         => array(
				'content',
				true,
				false,
				'投稿・固定ページを検索',
				$obj(
					array(
						'post_type' => array( 'type' => 'string' ),
						'query'     => array( 'type' => 'string' ),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'content.get'          => array( 'content', true, false, '投稿・固定ページの内容を確認', $obj( array( 'id' => $id ), array( 'id' ) ) ),
			'content.create'       => array(
				'content',
				false,
				false,
				'投稿・固定ページを作成',
				$obj(
					array(
						'post_type' => array( 'type' => 'string' ),
						'title'     => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'content'   => $text,
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish', 'pending' ),
						),
					),
					array( 'post_type', 'title' )
				),
			),
			'content.update'       => array(
				'content',
				false,
				false,
				'投稿・固定ページを更新',
				$obj(
					array(
						'id'      => $id,
						'title'   => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'content' => $text,
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish', 'pending' ),
						),
					),
					array( 'id' )
				),
			),
			'content.delete'       => array( 'content', false, true, '投稿・固定ページをゴミ箱へ移動', $obj( array( 'id' => $id ), array( 'id' ) ) ),
			'media.list'           => array(
				'media',
				true,
				false,
				'メディアを検索',
				$obj(
					array(
						'query' => array( 'type' => 'string' ),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'users.list'           => array(
				'users',
				true,
				false,
				'サイト利用者を確認',
				$obj(
					array(
						'query' => array( 'type' => 'string' ),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'products.list'        => array(
				'products',
				true,
				false,
				'WooCommerce商品を検索',
				$obj(
					array(
						'query' => array( 'type' => 'string' ),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'products.get'         => array( 'products', true, false, 'WooCommerce商品を確認', $obj( array( 'id' => $id ), array( 'id' ) ) ),
			'products.save'        => array(
				'products',
				false,
				false,
				'WooCommerce商品を作成・更新',
				$obj(
					array(
						'id'             => $id,
						'name'           => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'description'    => $text,
						'regular_price'  => array( 'type' => 'string' ),
						'stock_quantity' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'status'         => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish' ),
						),
					),
					array( 'name' )
				),
			),
			'orders.list'          => array(
				'orders',
				true,
				false,
				'WooCommerce注文を検索',
				$obj(
					array(
						'status' => array( 'type' => 'string' ),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'orders.get'           => array( 'orders', true, false, 'WooCommerce注文を確認', $obj( array( 'id' => $id ), array( 'id' ) ) ),
			'orders.update_status' => array(
				'orders',
				false,
				false,
				'WooCommerce注文状態を更新',
				$obj(
					array(
						'id'     => $id,
						'status' => array( 'type' => 'string' ),
						'note'   => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
					),
					array( 'id', 'status' )
				),
			),
			'coupons.list'         => array(
				'coupons',
				true,
				false,
				'WooCommerceクーポンを確認',
				$obj(
					array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					)
				),
			),
			'coupons.create'       => array(
				'coupons',
				false,
				false,
				'WooCommerceクーポンを作成',
				$obj(
					array(
						'code'          => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'amount'        => array( 'type' => 'string' ),
						'discount_type' => array(
							'type' => 'string',
							'enum' => array( 'fixed_cart', 'percent' ),
						),
					),
					array( 'code', 'amount' )
				),
			),
		);
	}

	private function capabilities(): array {
		$enabled = array_intersect( (array) Options::get( 'bridge_groups', array() ), self::GROUPS );
		$result  = array();
		foreach ( $this->definitions() as $name => $definition ) {
			if ( in_array( $definition[0], $enabled, true ) && ( ! in_array( $definition[0], array( 'products', 'orders', 'coupons' ), true ) || class_exists( 'WooCommerce' ) ) ) {
				$result[] = array(
					'name'         => $name,
					'domain'       => $definition[0],
					'description'  => $definition[3],
					'read_only'    => $definition[1],
					'destructive'  => $definition[2],
					'input_schema' => $definition[4],
					'keywords'     => array( $definition[0] ),
				);
			}
		}
		return $result;
	}

	// Messages in this operation pipeline are serialized as REST data, not rendered as HTML.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
	public function execute( WP_REST_Request $request ): WP_REST_Response {
		$name        = (string) $request['operation'];
		$definitions = $this->definitions();
		$allowed     = wp_list_pluck( $this->capabilities(), 'name' );
		if ( ! isset( $definitions[ $name ] ) || ! in_array( $name, $allowed, true ) ) {
			return new WP_REST_Response( array( 'error' => __( 'この機能は停止されています。', 'fourmix-intelligence' ) ), 403 );
		}
		$payload = $request->get_json_params();
		$args    = is_array( $payload['arguments'] ?? null ) ? $payload['arguments'] : array();
		try {
			return new WP_REST_Response( array( 'data' => $this->perform( $name, $args ) ) ); } catch ( \Throwable $error ) {
			return new WP_REST_Response( array( 'error' => sanitize_text_field( $error->getMessage() ) ), 422 ); }
	}

	private function perform( string $name, array $a ): mixed {
		$this->validate_arguments( $this->definitions()[ $name ][4], $a );
		if ( 'content.list' === $name ) {
			$type = $this->allowed_post_type( (string) ( $a['post_type'] ?? 'post' ) );
			return array_map(
				fn( $p ) => array(
					'id'     => $p->ID,
					'type'   => $p->post_type,
					'title'  => get_the_title( $p ),
					'status' => $p->post_status,
					'url'    => get_permalink( $p ),
				),
				get_posts(
					array(
						'post_type'   => $type,
						's'           => sanitize_text_field( $a['query'] ?? '' ),
						'numberposts' => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
						'post_status' => array( 'publish', 'draft', 'pending' ),
					)
				)
			); }
		if ( 'content.get' === $name ) {
			$p = get_post( absint( $a['id'] ?? 0 ) );
			if ( ! $p || ! in_array( $p->post_type, $this->allowed_post_types(), true ) ) {
				throw new \RuntimeException( __( '投稿が見つかりません。', 'fourmix-intelligence' ) );
			} return array(
				'id'      => $p->ID,
				'type'    => $p->post_type,
				'title'   => $p->post_title,
				'content' => $p->post_content,
				'status'  => $p->post_status,
			); }
		if ( 'content.create' === $name || 'content.update' === $name ) {
			$values = array();
			if ( 'content.update' === $name ) {
				$values['ID'] = absint( $a['id'] );
				$existing     = get_post( $values['ID'] );
				if ( ! $existing || ! in_array( $existing->post_type, $this->allowed_post_types(), true ) ) {
					throw new \RuntimeException( __( '投稿が見つかりません。', 'fourmix-intelligence' ) );
				}
			} if ( isset( $a['post_type'] ) || 'content.create' === $name ) {
				$values['post_type'] = $this->allowed_post_type( (string) ( $a['post_type'] ?? 'post' ) );
			} if ( array_key_exists( 'title', $a ) ) {
				$values['post_title'] = sanitize_text_field( $a['title'] );
			} if ( array_key_exists( 'content', $a ) ) {
				$values['post_content'] = wp_kses_post( $a['content'] );
			} if ( array_key_exists( 'status', $a ) || 'content.create' === $name ) {
				$values['post_status'] = sanitize_key( $a['status'] ?? 'draft' );
			} $id = wp_insert_post( $values, true );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( $id->get_error_message() );
			} return array(
				'id'     => $id,
				'status' => get_post_status( $id ),
			); }
		if ( 'content.delete' === $name ) {
			$p = get_post( absint( $a['id'] ?? 0 ) );
			if ( ! $p || ! in_array( $p->post_type, $this->allowed_post_types(), true ) ) {
				throw new \RuntimeException( __( '投稿が見つかりません。', 'fourmix-intelligence' ) );
			} return array( 'trashed' => (bool) wp_trash_post( $p->ID ) ); }
		if ( 'media.list' === $name ) {
			return array_map(
				fn( $p ) => array(
					'id'        => $p->ID,
					'title'     => $p->post_title,
					'url'       => wp_get_attachment_url( $p->ID ),
					'mime_type' => $p->post_mime_type,
				),
				get_posts(
					array(
						'post_type'   => 'attachment',
						'post_status' => 'inherit',
						's'           => sanitize_text_field( $a['query'] ?? '' ),
						'numberposts' => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
					)
				)
			);
		}
		if ( 'users.list' === $name ) {
			return array_map(
				fn( $u ) => array(
					'id'    => $u->ID,
					'name'  => $u->display_name,
					'roles' => $u->roles,
				),
				get_users(
					array(
						'search' => '*' . sanitize_text_field( $a['query'] ?? '' ) . '*',
						'number' => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
						'fields' => 'all_with_meta',
					)
				)
			);
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			throw new \RuntimeException( __( 'WooCommerceが利用できません。', 'fourmix-intelligence' ) );
		}
		if ( 'products.list' === $name ) {
			return array_map(
				fn( $id ) => $this->product( wc_get_product( $id ) ),
				wc_get_products(
					array(
						's'      => sanitize_text_field( $a['query'] ?? '' ),
						'limit'  => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
						'return' => 'ids',
					)
				)
			);
		}
		if ( 'products.get' === $name ) {
			return $this->product( wc_get_product( absint( $a['id'] ?? 0 ) ) );
		}
		if ( 'products.save' === $name ) {
			$p = ! empty( $a['id'] ) ? wc_get_product( absint( $a['id'] ) ) : new \WC_Product_Simple();
			if ( ! $p ) {
				throw new \RuntimeException( __( '商品が見つかりません。', 'fourmix-intelligence' ) );
			} foreach ( array( 'name', 'description', 'regular_price', 'stock_quantity', 'status' ) as $key ) {
				if ( array_key_exists( $key, $a ) ) {
					$method = 'set_' . $key;
					$p->$method( 'stock_quantity' === $key ? max( 0, (int) $a[ $key ] ) : sanitize_text_field( (string) $a[ $key ] ) );
				}
			} return array( 'id' => $p->save() ); }
		if ( 'orders.list' === $name ) {
			return array_map(
				fn( $o ) => array(
					'id'         => $o->get_id(),
					'status'     => $o->get_status(),
					'total'      => $o->get_total(),
					'created_at' => $o->get_date_created()?->date( DATE_ATOM ),
				),
				wc_get_orders(
					array(
						'status' => sanitize_key( $a['status'] ?? 'any' ),
						'limit'  => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
					)
				)
			);
		}
		if ( 'orders.get' === $name ) {
			$o = wc_get_order( absint( $a['id'] ?? 0 ) );
			if ( ! $o ) {
				throw new \RuntimeException( __( '注文が見つかりません。', 'fourmix-intelligence' ) );
			} return array(
				'id'     => $o->get_id(),
				'status' => $o->get_status(),
				'total'  => $o->get_total(),
				'items'  => array_map(
					fn( $i ) => array(
						'name'     => $i->get_name(),
						'quantity' => $i->get_quantity(),
					),
					$o->get_items()
				),
			); }
		if ( 'orders.update_status' === $name ) {
			$o = wc_get_order( absint( $a['id'] ?? 0 ) );
			if ( ! $o ) {
				throw new \RuntimeException( __( '注文が見つかりません。', 'fourmix-intelligence' ) );
			} $o->update_status( sanitize_key( $a['status'] ), sanitize_textarea_field( $a['note'] ?? '' ), false );
			return array(
				'id'     => $o->get_id(),
				'status' => $o->get_status(),
			); }
		if ( 'coupons.list' === $name ) {
			return array_map(
				fn( $p ) => array(
					'id'   => $p->ID,
					'code' => $p->post_title,
				),
				get_posts(
					array(
						'post_type'   => 'shop_coupon',
						'numberposts' => min( 50, max( 1, (int) ( $a['limit'] ?? 20 ) ) ),
					)
				)
			);
		}
		if ( 'coupons.create' === $name ) {
			$c = new \WC_Coupon();
			$c->set_code( sanitize_text_field( $a['code'] ) );
			$c->set_amount( sanitize_text_field( $a['amount'] ) );
			$c->set_discount_type( sanitize_key( $a['discount_type'] ?? 'fixed_cart' ) );
			return array(
				'id'   => $c->save(),
				'code' => $c->get_code(),
			); }
		throw new \RuntimeException( __( '操作が見つかりません。', 'fourmix-intelligence' ) );
	}

	/** @param array<string,mixed> $schema @param array<string,mixed> $arguments */
	private function validate_arguments( array $schema, array $arguments ): void {
		$properties = (array) ( $schema['properties'] ?? array() );
		if ( array_diff( array_keys( $arguments ), array_keys( $properties ) ) ) {
			throw new \RuntimeException( __( '定義されていない入力項目が含まれています。', 'fourmix-intelligence' ) );
		}
		foreach ( (array) ( $schema['required'] ?? array() ) as $key ) {
			if ( ! array_key_exists( $key, $arguments ) ) {
				throw new \RuntimeException( sprintf( __( '%s は必須です。', 'fourmix-intelligence' ), $key ) );
			}
		}
		foreach ( $arguments as $key => $value ) {
			$rule  = (array) $properties[ $key ];
			$type  = $rule['type'] ?? 'string';
			$valid = ( 'string' === $type && is_string( $value ) ) || ( 'integer' === $type && is_int( $value ) ) || ( 'boolean' === $type && is_bool( $value ) ) || ( 'array' === $type && is_array( $value ) ) || ( 'object' === $type && is_array( $value ) );
			if ( ! $valid ) {
				throw new \RuntimeException( sprintf( __( '%s の形式を確認してください。', 'fourmix-intelligence' ), $key ) );
			}
			if ( isset( $rule['enum'] ) && ! in_array( $value, $rule['enum'], true ) ) {
				throw new \RuntimeException( sprintf( __( '%s の選択値を確認してください。', 'fourmix-intelligence' ), $key ) );
			}
			if ( is_string( $value ) && isset( $rule['maxLength'] ) && mb_strlen( $value ) > $rule['maxLength'] ) {
				throw new \RuntimeException( sprintf( __( '%s が長すぎます。', 'fourmix-intelligence' ), $key ) );
			}
			if ( is_int( $value ) && isset( $rule['minimum'] ) && $value < $rule['minimum'] ) {
				throw new \RuntimeException( sprintf( __( '%s が範囲外です。', 'fourmix-intelligence' ), $key ) );
			}
			if ( is_int( $value ) && isset( $rule['maximum'] ) && $value > $rule['maximum'] ) {
				throw new \RuntimeException( sprintf( __( '%s が範囲外です。', 'fourmix-intelligence' ), $key ) );
			}
		}
	}

	/** @return list<string> */
	private function allowed_post_types(): array {
		$selected = (array) Options::get( 'sync_post_types', array( 'post', 'page' ) );
		return array_values( array_intersect( array_map( 'sanitize_key', $selected ), get_post_types( array( 'public' => true ) ) ) );
	}

	private function allowed_post_type( string $type ): string {
		$type = sanitize_key( $type );
		if ( ! in_array( $type, $this->allowed_post_types(), true ) ) {
			throw new \RuntimeException( __( 'この投稿種類は管理画面で許可されていません。', 'fourmix-intelligence' ) );
		}
		return $type;
	}
	private function product( mixed $p ): array {
		if ( ! $p ) {
			throw new \RuntimeException( __( '商品が見つかりません。', 'fourmix-intelligence' ) );
		} return array(
			'id'             => $p->get_id(),
			'name'           => $p->get_name(),
			'status'         => $p->get_status(),
			'price'          => $p->get_price(),
			'stock_quantity' => $p->get_stock_quantity(),
			'in_stock'       => $p->is_in_stock(),
			'url'            => $p->get_permalink(),
		); }
	// phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
