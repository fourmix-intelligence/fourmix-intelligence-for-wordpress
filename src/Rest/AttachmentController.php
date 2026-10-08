<?php

namespace FourmixIntelligence\WordPress\Rest;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\Attachments;
use FourmixIntelligence\WordPress\Support\ChatSession;
use WP_REST_Request;
use WP_REST_Response;

final class AttachmentController {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes(): void {
		foreach ( array( false, true ) as $is_public ) {
			foreach ( array( 'prepare', 'upload', 'status', 'list', 'content', 'remove' ) as $action ) {
				register_rest_route(
					'fourmix-intelligence/v1',
					'/' . ( $is_public ? 'public' : 'staff' ) . '/attachment_' . $action,
					array(
						'methods'             => 'POST',
						'callback'            => fn( $request ) => $this->handle( $request, $is_public, $action ),
						'permission_callback' => $is_public ? '__return_true' : array( new StaffController(), 'authorize' ),
					)
				);
			}
		}
	}
	private function access( WP_REST_Request $request, bool $is_public, bool $create = false ): array {
		return $is_public ? ( new ConversationController() )->attachment_access( $request, $create ) : ( new StaffController() )->attachment_access( $request, $create );
	}
	public static function items( array $access ): array {
		if ( ! Attachments::uuid( $access['conversation_id'] ) ) {
			return array();
		}
		$reply = ( new Client() )->raw( 'GET', self::path( $access ), null, $access['token'], $access['headers'] );
		$data  = json_decode( wp_remote_retrieve_body( $reply ), true );
		return is_array( $data['data'] ?? null ) ? $data['data'] : array();
	}
	public static function validate_ids( array $ids, array $access ): array {
		if ( count( $ids ) > $access['policy']['max_files'] ) {
			throw new \InvalidArgumentException( esc_html__( '添付の件数を確認してください。', 'fourmix-intelligence' ) );
		}
		$owned = array_column( self::items( $access ), null, 'id' );
		$total = 0;
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! Attachments::uuid( $id ) || ! isset( $owned[ $id ] ) ) {
				throw new \InvalidArgumentException( esc_html__( '本人または訪問者の会話に属する添付だけを使用できます。', 'fourmix-intelligence' ) );
			}
			$total += (int) $owned[ $id ]['size'];
		}
		if ( $total > $access['policy']['context_bytes'] ) {
			throw new \InvalidArgumentException( esc_html__( '添付の合計サイズを確認してください。', 'fourmix-intelligence' ) );
		}
		return array_values( array_unique( $ids ) );
	}
	private static function path( array $access ): string {
		return '/api/v3/ai/plugins/' . rawurlencode( $access['agent'] ) . '/conversations/' . rawurlencode( $access['conversation_id'] ) . '/attachments';
	}
	private function handle( WP_REST_Request $request, bool $is_public, string $action ): WP_REST_Response {
		try {
			$access = $this->access( $request, $is_public );
			if ( ! $access['policy']['enabled'] ) {
				throw new \RuntimeException( esc_html__( 'このAIでは添付を利用できません。', 'fourmix-intelligence' ) );
			}
			if ( 'status' === $action ) {
				return $this->reply( ChatSession::status( $access['scope'], (string) $request->get_param( 'request_id' ) ) );
			}
			if ( 'prepare' === $action ) {
				return $this->reply(
					ChatSession::run(
						$access['scope'],
						(string) $request->get_param( 'request_id' ),
						array(
							'operation' => 'attachment.prepare',
							'thread_id' => $access['thread_id'],
							'requested' => (string) $request->get_param( 'conversation_id' ),
						),
						fn() => array(
							'conversation_id' => $this->access( $request, $is_public, true )['conversation_id'],
							'policy'          => $access['policy'],
						)
					)
				);
			}
			if ( ! Attachments::uuid( $access['conversation_id'] ) ) {
				throw new \RuntimeException( esc_html__( '添付する会話を先に確認してください。', 'fourmix-intelligence' ) );
			}
			if ( 'list' === $action ) {
				return $this->reply( array( 'data' => self::items( $access ) ) );
			}
			if ( 'upload' === $action ) {
				$files = $request->get_file_params();
				$file  = Attachments::file( (array) ( $files['file'] ?? array() ), $access['policy'] );
				ignore_user_abort( true );
				$result = ChatSession::run(
					$access['scope'],
					(string) $request->get_param( 'request_id' ),
					array(
						'operation'       => 'attachment.upload',
						'conversation_id' => $access['conversation_id'],
						'thread_id'       => $access['thread_id'],
						'name'            => $file['name'],
						'sha256'          => $file['sha256'],
					),
					static function () use ( $file, $access ) {
						$boundary = 'Fourmix' . bin2hex( random_bytes( 24 ) );
						$body     = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . str_replace( array( '"', "\r", "\n" ), '', $file['name'] ) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $file['body'] . "\r\n--" . $boundary . "--\r\n";
						$reply    = ( new Client() )->raw( 'POST', self::path( $access ), $body, $access['token'], $access['headers'] + array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) );
						$data     = json_decode( wp_remote_retrieve_body( $reply ), true );
						if ( ! is_array( $data ) || ! Attachments::uuid( (string) ( $data['id'] ?? '' ) ) ) {
							throw new \RuntimeException( 'attachment_response' );
						}
						return array(
							'attachment'      => $data,
							'conversation_id' => $access['conversation_id'],
						);
					}
				);
				return $this->reply( $result );
			}
			$id = (string) $request->get_param( 'id' );
			if ( 'remove' === $action ) {
				return $this->reply(
					ChatSession::run(
						$access['scope'],
						(string) $request->get_param( 'request_id' ),
						array(
							'operation'       => 'attachment.remove',
							'conversation_id' => $access['conversation_id'],
							'id'              => $id,
						),
						static function () use ( $access, $id ) {
							self::validate_ids( array( $id ), $access );
							( new Client() )->raw( 'DELETE', self::path( $access ) . '/' . rawurlencode( $id ), null, $access['token'], $access['headers'] );
							return array( 'removed' => true );
						}
					)
				);
			}
			self::validate_ids( array( $id ), $access );
			$reply    = ( new Client() )->raw( 'GET', self::path( $access ) . '/' . rawurlencode( $id ) . '/content', null, $access['token'], $access['headers'] );
			$body     = wp_remote_retrieve_body( $reply );
			$response = new WP_REST_Response(
				null,
				200,
				array(
					'Content-Type'            => 'application/octet-stream',
					'Content-Disposition'     => 'attachment',
					'Cache-Control'           => 'private, no-store',
					'X-Content-Type-Options'  => 'nosniff',
					'Content-Security-Policy' => "sandbox; default-src 'none'",
				)
			);
			$serve    = static function ( $served, $result ) use ( $body, $response ) {
				if ( $result !== $response ) {
					return $served; }
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 検証済みの本人会話のバイナリをattachmentとnosniffで返します。
				return true;
			};
			add_filter( 'rest_pre_serve_request', $serve, 10, 2 );
			return $response;
		} catch ( \InvalidArgumentException $error ) {
			return $this->reply( array( 'message' => $error->getMessage() ), 422 );
		} catch ( \Throwable $error ) {
			return $this->reply(
				array(
					'message'   => 401 === $error->getCode() ? __( 'Fourmix Intelligence に再度ログインして、添付の取得をお試しください。', 'fourmix-intelligence' ) : __( '添付と本人・会話の権限を確認してください。', 'fourmix-intelligence' ),
					'login_url' => 401 === $error->getCode() ? \FourmixIntelligence\WordPress\Support\NativeIdentity::login_url() : null,
				),
				401 === $error->getCode() ? 401 : 403
			);
		}
	}
	private function reply( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( \FourmixIntelligence\WordPress\Support\StreamResponse::clean( $data ), $status, array( 'Cache-Control' => 'private, no-store' ) );
	}
}
