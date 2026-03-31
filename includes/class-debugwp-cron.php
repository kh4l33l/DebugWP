<?php
/**
 * Cron — auto-disable debug modes after 48 hours, cleanup old logs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Cron {

    const HOOK    = 'debugwp_cleanup';
    const TIMEOUT = 48 * HOUR_IN_SECONDS; // 48 hours.

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_action( self::HOOK, [ $this, 'run' ] );
    }

    /**
     * Deactivation hook — clear cron.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Hourly cron job.
     */
    public function run() {
        $this->auto_disable_expired();
        $this->cleanup_old_logs();
        $this->trim_to_max_entries();
    }

    /**
     * Disable debug modes that have been active longer than 48 hours.
     */
    private function auto_disable_expired() {
        $now = time();

        foreach ( array_keys( $this->core->get_supported_plugins() ) as $slug ) {
            if ( ! $this->core->is_debug_enabled( $slug ) ) {
                continue;
            }

            $enabled_at = (int) get_option( "debugwp_{$slug}_enabled_at", 0 );
            if ( $enabled_at && ( $now - $enabled_at ) > self::TIMEOUT ) {
                delete_option( "debugwp_{$slug}_enabled" );
                delete_option( "debugwp_{$slug}_enabled_at" );
                set_transient( "debugwp_auto_disabled_{$slug}", 1, DAY_IN_SECONDS );
            }
        }
    }

    /**
     * Delete logs older than configured retention period.
     */
    private function cleanup_old_logs() {
        global $wpdb;

        $settings  = $this->core->get_settings();
        $days      = max( 1, (int) ( $settings['retention_days'] ?? 7 ) );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}debugwp_logs WHERE created_at < %s",
            $threshold
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Trim logs to max entries setting (keep newest).
     */
    private function trim_to_max_entries() {
        global $wpdb;

        $settings    = $this->core->get_settings();
        $max         = max( 100, (int) ( $settings['max_entries'] ?? 5000 ) );
        $table       = $wpdb->prefix . 'debugwp_logs';

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $count <= $max ) {
            return;
        }

        $excess = $count - $max;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} ORDER BY created_at ASC LIMIT %d",
            $excess
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
