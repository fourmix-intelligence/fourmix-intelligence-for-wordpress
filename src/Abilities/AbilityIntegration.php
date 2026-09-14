<?php

namespace FourmixIntelligence\WordPress\Abilities;

use FourmixIntelligence\WordPress\Http\Client;
use FourmixIntelligence\WordPress\Support\Options;

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
				'show_in_rest'        => false,
				'meta'                => array(
					'annotations' => array(
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
			$response = ( new Client() )->post(
				'/api/v3/ai/plugins/' . Options::get( 'agent' ) . '/runs',
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => sanitize_textarea_field( $input['message'] ),
						),
					),
					'options'  => array( 'channel' => 'wordpress-ability' ),
				)
			);
			return array(
				'answer' => (string) ( $response['result']['answer'] ?? '' ),
				'data'   => is_array( $response['result']['data'] ?? null ) ? $response['result']['data'] : array(),
			);
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'fourmix_intelligence_request_failed', __( 'Fourmix Intelligence から回答を取得できませんでした。', 'fourmix-intelligence' ) );
		}
	}
}
