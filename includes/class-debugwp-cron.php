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
        $this->maybe_send_cron_alert_email();
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

    /**
     * Send a cron-alert email if notifications are enabled and there are
     * overdue events or recent cron errors. Uses a transient to ensure
     * at most one email per hour.
     */
    private function maybe_send_cron_alert_email() {
        $settings = $this->core->get_settings();
        if ( empty( $settings['cron_email_enabled'] ) ) {
            return;
        }

        // Throttle: skip if we already sent within the last hour.
        if ( get_transient( 'debugwp_cron_email_sent' ) ) {
            return;
        }

        $sections = [];

        // 1) Overdue cron events.
        $overdue = DebugWP_Cron_UI::get_overdue_events();
        if ( ! empty( $overdue ) ) {
            $lines = [];
            foreach ( $overdue as $ev ) {
                $ago     = human_time_diff( $ev['timestamp'], time() );
                $lines[] = sprintf( '  • %s — overdue by %s', $ev['hook'], $ago );
            }
            $sections[] = "Overdue Cron Events (" . count( $overdue ) . "):\n" . implode( "\n", $lines );
        }

        // 2) Recent cron errors logged in the last hour.
        global $wpdb;
        $table  = $wpdb->prefix . 'debugwp_logs';
        $errors = $wpdb->get_results( $wpdb->prepare(
            "SELECT plugin_slug, message, created_at FROM {$table}
             WHERE log_type = 'cron' AND severity = 'error' AND created_at >= %s
             ORDER BY created_at DESC LIMIT 20",
            gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( ! empty( $errors ) ) {
            $lines = [];
            foreach ( $errors as $err ) {
                $lines[] = sprintf( '  • [%s] %s — %s', $err->plugin_slug, $err->created_at, $err->message );
            }
            $sections[] = "Recent Cron Errors (" . count( $errors ) . "):\n" . implode( "\n", $lines );
        }

        // Nothing to report.
        if ( empty( $sections ) ) {
            return;
        }

        $to      = ! empty( $settings['cron_email_address'] ) ? $settings['cron_email_address'] : get_option( 'admin_email' );
        $site    = get_bloginfo( 'name' );
        $subject = sprintf( '[DebugWP] Cron Alert — %s', $site );
        $body    = "DebugWP has detected cron issues on {$site} (" . home_url() . "):\n\n"
                 . implode( "\n\n", $sections )
                 . "\n\nView details: " . admin_url( 'admin.php?page=debugwp-cron' )
                 . "\n\nThis email is sent at most once per hour while issues persist.";

        /**
         * Filter the cron alert email before it is sent.
         *
         * @param array $email {
         *     Email arguments.
         *
         *     @type string $to      Recipient email address.
         *     @type string $subject Email subject line.
         *     @type string $body    Plain-text email body.
         *     @type string $headers Optional headers (default empty).
         * }
         * @param array $context {
         *     Raw data that produced the email.
         *
         *     @type array $overdue  Overdue cron events (from get_overdue_events).
         *     @type array $errors   Recent cron error rows from the log table.
         *     @type array $settings Current DebugWP settings.
         * }
         */
        $email = apply_filters( 'debugwp_cron_alert_email', [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'headers' => '',
        ], [
            'overdue'  => $overdue,
            'errors'   => $errors ?? [],
            'settings' => $settings,
        ] );

        // Allow the filter to suppress the email by returning a falsy value.
        if ( empty( $email ) ) {
            return;
        }

        wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );

        set_transient( 'debugwp_cron_email_sent', 1, HOUR_IN_SECONDS );
    }
}
