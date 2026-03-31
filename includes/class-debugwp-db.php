<?php
/**
 * Database table creation and migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_DB {

    const DB_VERSION     = '1.0';
    const DB_VERSION_KEY = 'debugwp_db_version';

    /**
     * Runs on plugin activation.
     */
    public static function activate() {
        self::create_table();
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );

        // Schedule cron if not already scheduled.
        if ( ! wp_next_scheduled( 'debugwp_cleanup' ) ) {
            wp_schedule_event( time(), 'hourly', 'debugwp_cleanup' );
        }
    }

    private static function create_table() {
        global $wpdb;

        $table   = $wpdb->prefix . 'debugwp_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            plugin_slug varchar(100) NOT NULL DEFAULT '',
            log_type varchar(50) NOT NULL DEFAULT '',
            severity varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY plugin_slug (plugin_slug),
            KEY created_at (created_at),
            KEY severity (severity)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
