<?php
/**
 * Remove os dados do plugin ao excluí-lo pelo painel do WordPress.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'pn_settings', 'pn_state3', 'pn_sync_state', 'pn_caps', 'pn_db_version', 'pn_bg', 'pn_bg_state', 'pn_bg_log', 'pn_update_repo' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'pn_sync_lock' );
delete_transient( 'pn_bg_lock' );
delete_transient( 'pn_egestor_access' );
delete_site_transient( 'pn_update_release' );
wp_clear_scheduled_hook( 'pn_sync_event' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nuvemshop_pedidos' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'nuvemshop_dados' );
// phpcs:enable
