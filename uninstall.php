<?php
/**
 * DebugWP — Uninstall
 *
 * Drops the custom log table, deletes all plugin options, and clears cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom table.
$table = $wpdb->prefix . 'debugwp_logs';
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete all debugwp_* options.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'debugwp\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete transients.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%debugwp_auto_disabled%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'debugwp_cleanup' );
