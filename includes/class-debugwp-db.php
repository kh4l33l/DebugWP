<?php
/**
 * Database table creation and migration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_DB {

    const DB_VERSION     = '1.1';
    const DB_VERSION_KEY = 'debugwp_db_version';

    /**
     * Runs on plugin activation.
     */
    public static function activate() {
        self::create_table();
        self::maybe_upgrade();
        update_option( self::DB_VERSION_KEY, self::DB_VERSION );

        // Schedule cron if not already scheduled.
        if ( ! wp_next_scheduled( 'debugwp_cleanup' ) ) {
            wp_schedule_event( time(), 'hourly', 'debugwp_cleanup' );
        }
    }

    /**
     * Run on admin_init to catch upgrades without reactivation.
     */
    public static function maybe_upgrade() {
        $current = get_option( self::DB_VERSION_KEY, '1.0' );

        if ( version_compare( $current, '1.1', '<' ) ) {
            self::upgrade_to_1_1();
        }
    }

    private static function upgrade_to_1_1() {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        // Add hit_count and last_seen columns if they don't exist.
        $row = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'hit_count'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( ! $row ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN hit_count int unsigned NOT NULL DEFAULT 1 AFTER context" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00' AFTER hit_count" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            // Backfill last_seen from created_at for existing rows.
            $wpdb->query( "UPDATE {$table} SET last_seen = created_at WHERE last_seen = '0000-00-00 00:00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        update_option( self::DB_VERSION_KEY, '1.1' );
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
            hit_count int unsigned NOT NULL DEFAULT 1,
            last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
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
