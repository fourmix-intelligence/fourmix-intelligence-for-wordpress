<?php

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'fourmix_intelligence_remove_data_on_uninstall', false ) ) {
	return;
}

delete_option( 'fourmix_intelligence_settings' );
delete_option( 'fourmix_intelligence_remove_data_on_uninstall' );
delete_option( 'fourmix_intelligence_db_version' );
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fourmix_intelligence_outbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
