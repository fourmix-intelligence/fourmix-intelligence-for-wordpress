<?php

namespace FourmixIntelligence\WordPress\Support;

final class ChatAssets {
	/** WordPressの登録済み依存順を使い、通常の業務画面では開くまで読み込みません。 */
	public static function lazy_scripts(): array {
		$items = array();
		$visit = static function ( string $handle ) use ( &$visit, &$items ): void {
			if ( isset( $items[ $handle ] ) || ! str_starts_with( $handle, 'fourmix-intelligence-' ) ) {
				return;
			}
			$script = wp_scripts()->registered[ $handle ];
			foreach ( $script->deps as $dependency ) {
				$visit( $dependency );
			}
			$items[ $handle ] = array(
				'handle'       => $handle,
				'url'          => add_query_arg( 'ver', $script->ver, $script->src ),
				'dependencies' => array_values( array_filter( $script->deps, static fn( $value ) => str_starts_with( $value, 'fourmix-intelligence-' ) ) ),
			);
		};
		$visit( 'fourmix-intelligence-chat' );
		return array_values( $items );
	}
	public static function register(): void {
		wp_register_script( 'fourmix-intelligence-markdown', FOURMIX_INTELLIGENCE_URL . 'assets/vendor/markdown-it.min.js', array(), '14.3.2', true );
		wp_register_script( 'fourmix-intelligence-highlight', FOURMIX_INTELLIGENCE_URL . 'assets/vendor/highlight.min.js', array(), '11.12.0', true );
		wp_register_script( 'fourmix-intelligence-answer', FOURMIX_INTELLIGENCE_URL . 'assets/answer.js', array( 'wp-i18n', 'fourmix-intelligence-markdown', 'fourmix-intelligence-highlight' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_localize_script( 'fourmix-intelligence-answer', 'FourmixIntelligenceAnswer', array( 'mermaidUrl' => FOURMIX_INTELLIGENCE_URL . 'assets/vendor/mermaid.min.js' ) );
		wp_set_script_translations( 'fourmix-intelligence-answer', 'fourmix-intelligence' );
		wp_register_script( 'fourmix-intelligence-attachments', FOURMIX_INTELLIGENCE_URL . 'assets/attachments.js', array( 'wp-i18n' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-attachments', 'fourmix-intelligence' );
		wp_register_script( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.js', array( 'fourmix-intelligence-answer', 'fourmix-intelligence-attachments' ), FOURMIX_INTELLIGENCE_VERSION, true );
		wp_set_script_translations( 'fourmix-intelligence-chat', 'fourmix-intelligence' );
		wp_register_style( 'fourmix-intelligence-chat', FOURMIX_INTELLIGENCE_URL . 'assets/chat.css', array(), FOURMIX_INTELLIGENCE_VERSION );
		wp_register_style( 'fourmix-intelligence-answer', FOURMIX_INTELLIGENCE_URL . 'assets/answer.css', array( 'fourmix-intelligence-chat' ), FOURMIX_INTELLIGENCE_VERSION );
		wp_register_style( 'fourmix-intelligence-attachments', FOURMIX_INTELLIGENCE_URL . 'assets/attachments.css', array( 'fourmix-intelligence-answer' ), FOURMIX_INTELLIGENCE_VERSION );
	}
}
