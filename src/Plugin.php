<?php

namespace FourmixIntelligence\WordPress;

use FourmixIntelligence\WordPress\Abilities\AbilityIntegration;
use FourmixIntelligence\WordPress\Admin\SettingsPage;
use FourmixIntelligence\WordPress\Blocks\BlockRegistry;
use FourmixIntelligence\WordPress\Knowledge\Synchronizer;
use FourmixIntelligence\WordPress\Privacy\PrivacyIntegration;
use FourmixIntelligence\WordPress\Rest\ConversationController;
use FourmixIntelligence\WordPress\Rest\NativeBridgeController;
final class Plugin {
	private static ?self $instance = null;
	public static function instance(): self {
		return self::$instance ??= new self(); }

	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		$this->ensure_sync_schedule();
		( new SettingsPage() )->register();
		( new BlockRegistry() )->register();
		( new ConversationController() )->register();
		( new NativeBridgeController() )->register();
		( new Synchronizer() )->register();
		( new PrivacyIntegration() )->register();
		( new AbilityIntegration() )->register();
	}

	private function ensure_sync_schedule(): void {
		if ( ! wp_next_scheduled( 'fourmix_intelligence_process_sync' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'five_minutes', 'fourmix_intelligence_process_sync' );
		}
	}

	public function cron_schedules( array $schedules ): array {
		$schedules['five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( '5分ごと', 'fourmix-intelligence' ),
		);
		return $schedules;
	}
}
