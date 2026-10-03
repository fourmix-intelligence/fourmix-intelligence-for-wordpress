<?php

namespace FourmixIntelligence\WordPress\Abilities;

use FourmixIntelligence\WordPress\Rest\StaffController;

/** WordPress 6.9 以降の Abilities API へ安全な読取能力を登録します。 */
final class AbilityIntegration {
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'abilities' ) );
	}

	public function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'fourmix-intelligence',
			array(
				'label'       => __( 'Fourmix Intelligence', 'fourmix-intelligence' ),
				'description' => __( 'Fourmix Intelligence の企業向けAIを WordPress から利用します。', 'fourmix-intelligence' ),
			)
		);
	}

	public function abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'fourmix-intelligence/ask-agent',
			array(
				'label'               => __( 'Fourmix Intelligence に質問', 'fourmix-intelligence' ),
				'description'         => __( '設定済みの企業向けAIへ質問し、回答と構造化された成果を取得します。公開中のサイト接客ではなく、編集権限を持つ担当者の業務支援に使用します。', 'fourmix-intelligence' ),
				'category'            => 'fourmix-intelligence',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'message' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 5000,
						),
					),
					'required'             => array( 'message' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'answer' => array( 'type' => 'string' ),
						'data'   => array( 'type' => 'object' ),
					),
					'required'             => array( 'answer', 'data' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'ask' ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/** @param array{message:string} $input @return array{answer:string, data:array<string, mixed>}|\WP_Error */
	public function ask( array $input ): array|\WP_Error {
		try {
			if ( ! current_user_can( 'edit_posts' ) ) {
				throw new \RuntimeException( esc_html__( '編集担当者の権限が必要です。', 'fourmix-intelligence' ) );
			}
			$request = new \WP_REST_Request( 'POST' );
			$staff   = new StaffController();
			$request->set_param( 'agent', $staff->selected_agent() );
			$session = $staff->session( $request );
			if ( 200 !== $session->get_status() ) {
				return $this->failure( $session );
			}
			$request->set_param( 'thread_id', $session->get_data()['thread_id'] );
			// 独立した execute は新しい依頼です。この呼び出しの回復には同じ ID を使います。
			$request_id = (string) (int) floor( microtime( true ) * 1000 ) . ':' . wp_generate_uuid4();
			$request->set_param( 'request_id', $request_id );
			$request->set_param( 'message', $input['message'] );
			$result = $staff->chat( $request );
			if ( 200 !== $result->get_status() ) {
				return $this->failure( $result );
			}
			$response = $result->get_data();
			if ( 'unknown_effect' === ( $response['state'] ?? '' ) ) {
				return new \WP_Error(
					'fourmix_intelligence_unknown_result',
					__( '結果を確認できません。同じ依頼を再送せず、送信結果を確認してください。', 'fourmix-intelligence' ),
					array(
						'status'     => 409,
						'state'      => 'unknown_effect',
						'request_id' => $request_id,
						'thread_id'  => $request->get_param( 'thread_id' ),
					)
				);
			}
			if ( ! is_string( $response['result']['answer'] ?? null ) || ! is_array( $response['result']['data'] ?? null ) ) {
				return new \WP_Error(
					'fourmix_intelligence_invalid_result',
					__( '回答の形式を確認できません。送信結果を確認してください。', 'fourmix-intelligence' ),
					array(
						'status'     => 502,
						'request_id' => $request_id,
					)
				);
			}
			return array(
				'answer' => $response['result']['answer'],
				'data'   => $response['result']['data'],
			);
		} catch ( \Throwable $error ) {
			$status = in_array( $error->getCode(), array( 401, 403 ), true ) ? $error->getCode() : 502;
			return new \WP_Error(
				'fourmix_intelligence_request_failed',
				$error->getMessage(),
				array(
					'status' => $status,
					'state'  => 401 === $status ? 'login_required' : 'failed',
				)
			);
		}
	}

	private function failure( \WP_REST_Response $response ): \WP_Error {
		$data = $response->get_data();
		return new \WP_Error(
			'fourmix_intelligence_request_failed',
			$data['message'] ?? __( 'Fourmix Intelligence から回答を取得できませんでした。', 'fourmix-intelligence' ),
			array(
				'status' => $response->get_status(),
				'state'  => $data['state'] ?? 'failed',
			)
		);
	}
}
