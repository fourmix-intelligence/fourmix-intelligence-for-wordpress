<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\ChatSession;
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
		foreach ( array( 'connect', 'catalog', 'select', 'chat', 'chat_stream', 'session', 'run_status', 'new_conversation', 'action', 'confirm_action', 'preview', 'confirm' ) as $action ) {
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
		$iv    = base64_decode( (string) ( $value['iv'] ?? '' ), true );
		$tag   = base64_decode( (string) ( $value['tag'] ?? '' ), true );
		$token = isset( $value['encrypted'] ) && is_string( $iv ) && 12 === strlen( $iv ) && is_string( $tag ) && 16 === strlen( $tag ) ? openssl_decrypt( $value['encrypted'], 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), 0, $iv, $tag ) : false;
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
					'iv'        => base64_encode( $iv ),
					'tag'       => base64_encode( $tag ),
				),
				15 * MINUTE_IN_SECONDS
			);
			return $this->reply( $this->agent_selection( $agents ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	private function safe_agents( array $agents ): array {
		return array_map(
			static fn( $agent ) => array(
				'name'        => $agent['name'],
				'label'       => $agent['service_name'] ?? $agent['name'],
				'attachments' => \FourmixIntelligence\WordPress\Support\Attachments::policy( (array) ( $agent['attachments'] ?? array() ) ),
			),
			$agents
		);
	}

	private function agent_selection( array $agents ): array {
		$selected = (string) get_user_meta( get_current_user_id(), 'fourmix_intelligence_internal_agent', true );
		return array(
			'agents'         => $this->safe_agents( $agents ),
			'selected_agent' => in_array( $selected, wp_list_pluck( $agents, 'name' ), true ) ? $selected : '',
		);
	}

	public function select( WP_REST_Request $request ): WP_REST_Response {
		try {
			$agents = ( new Client() )->catalog( 'internal', $this->token() );
			$agent  = (string) $request->get_param( 'agent' );
			if ( ! in_array( $agent, wp_list_pluck( $agents, 'name' ), true ) ) {
				throw new \RuntimeException( esc_html__( '利用できる社内向けAIを選択してください。', 'fourmix-intelligence' ) );
			}
			update_user_meta( get_current_user_id(), 'fourmix_intelligence_internal_agent', $agent );
			return $this->reply( $this->agent_selection( $agents ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function catalog( WP_REST_Request $request ): WP_REST_Response {
		$selection = array(
			'agents'         => array(),
			'selected_agent' => '',
		);
		try {
			$selection = $this->agent_selection( ( new Client() )->catalog( 'internal', $this->token() ) );
		} catch ( \Throwable $error ) {
			// 期限切れの本人接続は復元せず、業務の権限一覧だけを返します。
		}
		return $this->reply(
			$selection + array(
				'context'      => $this->record_context( absint( $request->get_param( 'post_id' ) ) ),
				'operations'   => ( new NativeBridgeController() )->capabilities(),
				'woocommerce'  => class_exists( 'WooCommerce' ),
				'appointments' => class_exists( 'WC_Bookings' ) ? 'detected_not_enabled' : 'not_detected',
			)
		);
	}

	private function identity( WP_REST_Request $request ): array {
		$token = $this->token();
		$agent = sanitize_key( (string) $request->get_param( 'agent' ) );
		if ( ! in_array( $agent, wp_list_pluck( ( new Client() )->catalog( 'internal', $token ), 'name' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'このAIの利用権限を確認できません。接続と選択を確認してください。', 'fourmix-intelligence' ) );
		}
		return array( $token, $agent, $this->session_key() . '_' . hash( 'sha256', $token . ':' . $agent ) );
	}

	private function record_context( int $id ): ?array {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		return array(
			'id'     => $post->ID,
			'type'   => $post->post_type,
			'title'  => $post->post_title,
			'status' => $post->post_status,
		);
	}
	public function attachment_access( WP_REST_Request $request, bool $create = false ): array {
		list( $token, $agent, $scope ) = $this->identity( $request );
		$state                         = (array) get_transient( $scope );
		if ( empty( $state['thread_id'] ) || ! hash_equals( $state['thread_id'], (string) $request->get_param( 'thread_id' ) ) ) {
			throw new \RuntimeException( 'attachment_thread' );
		}
		$id        = (string) ( $state['conversation_id'] ?? '' );
		$requested = (string) $request->get_param( 'conversation_id' );
		if ( $requested && ! hash_equals( $id, $requested ) ) {
			throw new \RuntimeException( 'attachment_conversation' );
		}
		$manifest = ( new Client() )->request( 'GET', '/api/v3/ai/plugins/' . rawurlencode( $agent ) . '/metadata', null, $token );
		$policy   = \FourmixIntelligence\WordPress\Support\Attachments::policy( (array) ( $manifest['attachments'] ?? array() ) );
		if ( $create && ! $id && $policy['enabled'] ) {
			$conversation = ( new Client() )->request( 'POST', '/api/v3/agent-conversations/' . rawurlencode( $agent ) . '/resolve', array(), $token );
			$id           = (string) ( $conversation['identify'] ?? '' );
			if ( ! \FourmixIntelligence\WordPress\Support\Attachments::uuid( $id ) ) {
				throw new \RuntimeException( 'attachment_conversation' );
			}
			$state['conversation_id'] = $id;
			set_transient( $scope, $state, 15 * MINUTE_IN_SECONDS );
		}
		return array(
			'token'           => $token,
			'agent'           => $agent,
			'scope'           => $scope,
			'conversation_id' => $id,
			'thread_id'       => $state['thread_id'],
			'policy'          => $policy,
			'headers'         => array(),
		);
	}

	public function session( WP_REST_Request $request ): WP_REST_Response {
		try {
			list( , , $scope ) = $this->identity( $request );
			$state             = (array) get_transient( $scope );
			if ( empty( $state['thread_id'] ) ) {
				$state = array( 'thread_id' => wp_generate_uuid4() );
				set_transient( $scope, $state, 15 * MINUTE_IN_SECONDS );
			}
			return $this->reply(
				array(
					'scope'     => hash( 'sha256', $scope ),
					'thread_id' => $state['thread_id'],
				)
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error, 403 );
		}
	}

	public function new_conversation( WP_REST_Request $request ): WP_REST_Response {
		try {
			list( , , $scope ) = $this->identity( $request );
			$state             = array( 'thread_id' => wp_generate_uuid4() );
			set_transient( $scope, $state, 15 * MINUTE_IN_SECONDS );
			return $this->reply( $state );
		} catch ( \Throwable $error ) {
			return $this->error( $error, 403 );
		}
	}

	public function run_status( WP_REST_Request $request ): WP_REST_Response {
		try {
			list( , , $scope ) = $this->identity( $request );
			return $this->reply( ChatSession::status( $scope, (string) $request->get_param( 'request_id' ) ) );
		} catch ( \Throwable $error ) {
			return $this->error( $error, 403 );
		}
	}

	public function chat_stream( WP_REST_Request $request ): WP_REST_Response {
		return \FourmixIntelligence\WordPress\Support\StreamResponse::create( fn( $emit ) => $this->chat( $request, $emit ) );
	}

	public function chat( WP_REST_Request $request, ?callable $emit = null ): WP_REST_Response {
		try {
			list( $token, $agent, $scope ) = $this->identity( $request );
			$key                           = ChatSession::key( (string) $request->get_param( 'request_id' ) );
			$state                         = (array) get_transient( $scope );
			if ( empty( $state['thread_id'] ) || ! hash_equals( $state['thread_id'], (string) $request->get_param( 'thread_id' ) ) ) {
				throw new \RuntimeException( esc_html__( '会話が切り替わっています。画面を開き直してください。', 'fourmix-intelligence' ) );
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
			if ( true === $request->get_param( 'include_context' ) ) {
				// 画面名は参考情報であり、権限や実行対象の根拠にはしません。
				$context['screen'] = array(
					'id'    => substr( sanitize_key( (string) $request->get_param( 'screen' ) ), 0, 80 ),
					'title' => mb_substr( sanitize_text_field( (string) $request->get_param( 'screen_title' ) ), 0, 120 ),
				);
				if ( $post_id ) {
					$record = $this->record_context( $post_id );
					if ( ! $record ) {
						throw new \RuntimeException( esc_html__( 'この投稿を参照できません。', 'fourmix-intelligence' ) );
					}
					$context['record'] = $record;
				}
			}
			$body            = array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => $message . "\n現在の業務画面（参考情報）:\n" . wp_json_encode( $context ),
					),
				),
				'options'  => array( 'native_context' => $context ),
			);
			$conversation_id = $state['conversation_id'] ?? '';
			if ( is_string( $conversation_id ) && preg_match( '/^[0-9a-f-]{36}$/i', $conversation_id ) ) {
				$body['conversation_id'] = $conversation_id;
			}
			$ids = (array) $request->get_param( 'attachment_ids' );
			if ( $ids ) {
				$body['messages'][0]['attachment_ids'] = AttachmentController::validate_ids( $ids, $this->attachment_access( $request ) );
			}
			$response = ChatSession::run(
				$scope,
				$key,
				array(
					'operation'   => 'chat',
					'thread_id'   => $state['thread_id'],
					'message'     => $message,
					'attachments' => $ids,
					'context'     => $context,
				),
				function () use ( $body, $token, $agent, $scope, $state, $emit ) {
					$path     = '/api/v3/ai/plugins/' . rawurlencode( $agent ) . '/runs';
					$response = $emit ? ( new Client() )->stream( $path . '/stream', $body, $token, $emit ) : ( new Client() )->request( 'POST', $path, $body, $token );
					$current  = (array) get_transient( $scope );
					if ( ( $current['thread_id'] ?? '' ) === $state['thread_id'] ) {
						$current['conversation_id'] = $response['conversation_id'] ?? ( $current['conversation_id'] ?? '' );
						$current['actions']         = array_values( array_unique( array_merge( (array) ( $current['actions'] ?? array() ), ChatSession::actions( $response ) ) ) );
						set_transient( $scope, $current, 15 * MINUTE_IN_SECONDS );
					}
					return $response;
				}
			);
			return $this->reply( $response );
		} catch ( \Throwable $error ) {
			return $this->error( $error, 502 );
		}
	}

	private function require_action( WP_REST_Request $request ): array {
		list( $token, , $scope ) = $this->identity( $request );
		$state                   = (array) get_transient( $scope );
		$id                      = (string) $request->get_param( 'id' );
		if ( ! in_array( $id, (array) ( $state['actions'] ?? array() ), true ) || ( $state['thread_id'] ?? '' ) !== $request->get_param( 'thread_id' ) ) {
			throw new \RuntimeException( esc_html__( 'この会話で確認できる操作ではありません。', 'fourmix-intelligence' ) );
		}
		return array( $token, $scope, $id );
	}

	public function action( WP_REST_Request $request ): WP_REST_Response {
		try {
			list( $token, $scope, $id ) = $this->require_action( $request );
			$response                   = ( new Client() )->request( 'GET', '/api/v3/connection-actions/' . rawurlencode( $id ), null, $token );
			$response['can_confirm']    = false;
			if ( 'confirmation_required' === ( $response['status'] ?? '' ) ) {
				try {
					$this->validate_native_action( $response );
					$response['can_confirm'] = true;
				} catch ( \Throwable $error ) {
					$response['message'] = $error->getMessage();
				}
			}
			$receipt = ( new ExecutionJournal() )->find(
				$scope,
				$id,
				array(
					'operation' => 'studio.confirm',
					'id'        => $id,
				)
			);
			if ( $receipt && 'unknown_effect' === $receipt['state'] && 'confirmation_required' === ( $response['status'] ?? '' ) ) {
				$response['status'] = 'unknown_effect';
			}
			return $this->reply( $response );
		} catch ( \Throwable $error ) {
			return $this->error( $error, 403 );
		}
	}

	private function validate_native_action( array $preview ): void {
		$name = (string) ( $preview['operation_id'] ?? '' );
		$args = (array) ( $preview['arguments'] ?? array() );
		unset( $args['idempotency_key'] );
		( new NativeBridgeController() )->validate_operation( $name, $args );
	}

	public function confirm_action( WP_REST_Request $request ): WP_REST_Response {
		try {
			list( $token, $scope, $id ) = $this->require_action( $request );
			if ( true !== $request->get_param( 'approved' ) ) {
				throw new \RuntimeException( esc_html__( '操作内容を確認して承認してください。', 'fourmix-intelligence' ) );
			}
			$intent   = array(
				'operation' => 'studio.confirm',
				'id'        => $id,
			);
			$existing = ( new ExecutionJournal() )->find( $scope, $id, $intent );
			if ( null !== $existing ) {
				return $this->reply(
					'succeeded' === $existing['state'] ? $existing['data'] : array(
						'status' => 'unknown_effect',
						'id'     => $id,
					)
				);
			}
			$preview = ( new Client() )->request( 'GET', '/api/v3/connection-actions/' . rawurlencode( $id ), null, $token );
			if ( 'confirmation_required' !== ( $preview['status'] ?? '' ) ) {
				return $this->reply( $preview );
			}
			$this->validate_native_action( $preview );
			$result = ( new ExecutionJournal() )->run( $scope, $id, $intent, static fn() => ( new Client() )->request( 'POST', '/api/v3/connection-actions/' . rawurlencode( $id ) . '/confirm', array(), $token ) );
			return $this->reply(
				'succeeded' === $result['state'] ? $result['data'] : array(
					'status' => 'unknown_effect',
					'id'     => $id,
				)
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error, 403 );
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
		if ( ! empty( $args['id'] ) && function_exists( 'wc_get_product' ) ) {
			$record = str_starts_with( $name, 'products.' ) ? wc_get_product( $args['id'] ) : ( str_starts_with( $name, 'orders.' ) ? wc_get_order( $args['id'] ) : null );
			if ( $record ) {
				return hash( 'sha256', wp_json_encode( $record->get_data() ) );
			}
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
		return new WP_REST_Response( \FourmixIntelligence\WordPress\Support\StreamResponse::clean( $data ), $status, array( 'Cache-Control' => 'private, no-store' ) );
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
