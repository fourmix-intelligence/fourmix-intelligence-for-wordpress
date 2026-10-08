<?php

namespace FourmixIntelligence\WordPress;

final class Installer {
	public static function maybe_upgrade(): void {
		if ( '2' !== get_option( 'fourmix_intelligence_sync_schema_version' ) ) {
			self::activate();
		}
	}
	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'fourmix_intelligence_outbox';
		$sql   = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(32) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			operation varchar(16) NOT NULL,
			version bigint(20) unsigned NOT NULL,
			target_hash varchar(64) NOT NULL DEFAULT '',
			attempts smallint unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_version (object_type, object_id, version),
			KEY available_at (available_at)
		) {$wpdb->get_charset_collate()};";
		dbDelta( $sql );
		update_option( 'fourmix_intelligence_sync_schema_version', '2', false );
		update_option( 'fourmix_intelligence_db_version', FOURMIX_INTELLIGENCE_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'fourmix_intelligence_process_sync' );
	}
}
