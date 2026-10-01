<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\ExecutionJournal;
use FourmixIntelligence\WordPress\Support\Options;
use WP_REST_Request;
use WP_REST_Response;

/** 本人の短期接続とWordPress権限を使う管理画面専用の入口。 */
final class StaffController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		foreach ( array( 'connect', 'catalog', 'chat', 'preview', 'confirm' ) as $action ) {
			register_rest_route(
				'fourmix-intelligence/v1',
				'/staff/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'authorize' ),
				)
			);
		}
	}

	public function authorize( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_posts' ) && (bool) wp_verify_nonce( $request->get_header( 'x-wp-nonce' ), 'wp_rest' );
	}

	private function session_key(): string {
		return 'fmi_staff_' . hash_hmac( 'sha256', get_current_blog_id() . ':' . get_current_user_id() . ':' . wp_get_session_token(), wp_salt( 'auth' ) );
	}

	private function token(): string {
		$value = (array) get_transient( $this->session_key() );
		$token = isset( $value['encrypted'], $value['iv'], $value['tag'] ) ? openssl_decrypt( $value['encrypted'], 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), 0, $value['iv'], $value['tag'] ) : false;
		if ( ! $token ) {
			throw new \RuntimeException( esc_html__( '本人の接続が必要です。15分を過ぎた場合は接続し直してください。', 'fourmix-intelligence' ) );
		}
		return $token;
	}

	public function connect( WP_REST_Request $request ): WP_REST_Response {
		try {
			$token = (string) $request->get_param( 'token' );
			if ( strlen( $token ) < 16 || strlen( $token ) > 4096 ) {
				throw new \RuntimeException( esc_html__( '本人のアクセストークンを確認してください。', 'fourmix-intelligence' ) );
			}
			$agents = ( new Client() )->catalog( 'internal', $token );
			if ( ! $agents ) {
				throw new \RuntimeException( esc_html__( '利用できる社内向けStudio AIがありません。', 'fourmix-intelligence' ) );
			}
			$iv        = random_bytes( 12 );
			$tag       = '';
			$encrypted = openssl_encrypt( $token, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), 0, $iv, $tag );
			if ( false === $encrypted ) {
				throw new \RuntimeException( esc_html__( '本人の接続を保存できませんでした。', 'fourmix-intelligence' ) );
			}
			set_transient(
				$this->session_key(),
				array(
					'encrypted' => $encrypted,
					'iv'        => $iv,
					'tag'       => $tag,
				),
				15 * MINUTE_IN_SECONDS
			);
			return $this->reply( array( 'agents' => $this->safe_agents( $agents ) ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	private function safe_agents( array $agents ): array {
		return array_map(
			static fn( $agent ) => array(
				'name'  => $agent['name'],
				'label' => $agent['service_name'] ?? $agent['name'],
			),
			$agents
		);
	}

	public function catalog(): WP_REST_Response {
		return $this->reply(
			array(
				'operations'   => ( new NativeBridgeController() )->capabilities(),
				'woocommerce'  => class_exists( 'WooCommerce' ),
				'appointments' => class_exists( 'WC_Bookings' ) ? 'detected_not_enabled' : 'not_detected',
			)
		);
	}

	public function chat( WP_REST_Request $request ): WP_REST_Response {
		try {
			$token = $this->token();
			$agent = sanitize_key( (string) $request->get_param( 'agent' ) );
			$list  = ( new Client() )->catalog( 'internal', $token );
			if ( ! in_array( $agent, wp_list_pluck( $list, 'name' ), true ) ) {
				throw new \RuntimeException( esc_html__( '利用できる社内向けAIを選択してください。', 'fourmix-intelligence' ) );
			}
			update_user_meta( get_current_user_id(), 'fourmix_intelligence_internal_agent', $agent );
			$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
			if ( '' === trim( $message ) || mb_strlen( $message ) > 5000 ) {
				throw new \RuntimeException( esc_html__( '依頼を入力してください。', 'fourmix-intelligence' ) );
			}
			$context = array(
				'site'    => home_url(),
				'channel' => 'wordpress-admin',
				'actor'   => get_current_user_id(),
			);
			$post_id = absint( $request->get_param( 'post_id' ) );
			if ( $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					throw new \RuntimeException( esc_html__( 'この投稿を参照できません。', 'fourmix-intelligence' ) );
				}
				$post              = get_post( $post_id );
				$context['record'] = array(
					'id'     => $post->ID,
					'type'   => $post->post_type,
					'title'  => $post->post_title,
					'status' => $post->post_status,
				);
			}
			$body             = array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => $message . "\n現在の業務画面（参考情報）:\n" . wp_json_encode( $context ),
					),
				),
				'options'  => array( 'native_context' => $context ),
			);
			$conversation_key = $this->session_key() . '_' . hash( 'sha256', $token . ':' . $agent );
			$conversation_id  = get_transient( $conversation_key );
			if ( is_string( $conversation_id ) && preg_match( '/^[0-9a-f-]{36}$/i', $conversation_id ) ) {
				$body['conversation_id'] = $conversation_id;
			}
			$response = ( new Client() )->request( 'POST', '/api/v3/ai/plugins/' . rawurlencode( $agent ) . '/runs', $body, $token );
			if ( ! empty( $response['conversation_id'] ) ) {
				set_transient( $conversation_key, $response['conversation_id'], 15 * MINUTE_IN_SECONDS );
			}
			return $this->reply( $response );
		} catch ( \Throwable $error ) {
			return $this->error( $error, 502 );
		}
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response {
		try {
			$name   = (string) $request->get_param( 'operation' );
			$args   = (array) $request->get_param( 'arguments' );
			$bridge = new NativeBridgeController();
			$bridge->validate_operation( $name, $args );
			$definition = array_values( array_filter( $bridge->capabilities(), static fn( $item ) => $item['name'] === $name ) )[0];
			if ( $definition['read_only'] ) {
				return $this->reply(
					array(
						'state' => 'succeeded',
						'data'  => $bridge->perform( $name, $args ),
					)
				);
			}
			$id     = wp_generate_uuid4();
			$intent = array(
				'operation'  => $name,
				'arguments'  => $args,
				'actor'      => get_current_user_id(),
				'snapshot'   => $this->snapshot( $name, $args ),
				'expires_at' => time() + 600,
			);
			add_option( 'fmi_preview_' . hash( 'sha256', $this->session_key() . $id ), $intent, '', false );
			return $this->reply(
				array(
					'state'           => 'confirmation_required',
					'confirmation_id' => $id,
					'description'     => $definition['description'],
					'arguments'       => $args,
					'expires_at'      => $intent['expires_at'],
				)
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	private function snapshot( string $name, array $args ): string {
		if ( ! empty( $args['id'] ) && str_starts_with( $name, 'content.' ) ) {
			$post = get_post( $args['id'] );
			return hash( 'sha256', wp_json_encode( array( $post->post_title, $post->post_content, $post->post_status, $post->post_modified_gmt ) ) );
		}
		return '';
	}

	public function confirm( WP_REST_Request $request ): WP_REST_Response {
		try {
			$id     = (string) $request->get_param( 'confirmation_id' );
			$intent = get_option( 'fmi_preview_' . hash( 'sha256', $this->session_key() . $id ), array() );
			if ( true !== $request->get_param( 'approved' ) || ! $intent || get_current_user_id() !== $intent['actor'] || time() > $intent['expires_at'] ) {
				throw new \RuntimeException( esc_html__( '本人による有効な確認が必要です。', 'fourmix-intelligence' ) );
			}
			$bridge = new NativeBridgeController();
			$bridge->validate_operation( $intent['operation'], $intent['arguments'] );
			$journal  = new ExecutionJournal();
			$existing = $journal->find( $this->session_key(), $id, $intent );
			if ( null !== $existing ) {
				return $this->reply( $existing, 'succeeded' === $existing['state'] ? 200 : 409 );
			}
			if ( ! hash_equals( $intent['snapshot'], $this->snapshot( $intent['operation'], $intent['arguments'] ) ) ) {
				throw new \RuntimeException( esc_html__( '対象が変更されています。もう一度プレビューしてください。', 'fourmix-intelligence' ) );
			}
			$result = ( new ExecutionJournal() )->run(
				$this->session_key(),
				$id,
				$intent,
				function () use ( $bridge, $intent ) {
					if ( ! hash_equals( $intent['snapshot'], $this->snapshot( $intent['operation'], $intent['arguments'] ) ) ) {
						throw new \RuntimeException( esc_html__( '対象が変更されています。もう一度プレビューしてください。', 'fourmix-intelligence' ) );
					}
					return $bridge->perform( $intent['operation'], $intent['arguments'] );
				}
			);
			return $this->reply( $result, 'succeeded' === $result['state'] ? 200 : 409 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	private function reply( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status, array( 'Cache-Control' => 'private, no-store' ) );
	}

	private function error( \Throwable $error, int $status = 422 ): WP_REST_Response {
		return $this->reply(
			array(
				'state'   => 'failed',
				'message' => $error->getMessage(),
			),
			$status
		);
	}
}
