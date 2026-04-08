<?php
/**
 * Site Health integration — registers diagnostic tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Site_Health {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
    }

    public function register_tests( $tests ) {
        $tests['direct']['debugwp_stale_debug'] = [
            'label' => 'DebugWP — Stale debug modes',
            'test'  => [ $this, 'test_stale_debug_modes' ],
        ];

        $tests['direct']['debugwp_overdue_crons'] = [
            'label' => 'DebugWP — Overdue cron events',
            'test'  => [ $this, 'test_overdue_crons' ],
        ];

        $tests['direct']['debugwp_error_rate'] = [
            'label' => 'DebugWP — Recent error rate',
            'test'  => [ $this, 'test_error_rate' ],
        ];

        $tests['direct']['debugwp_debug_log_size'] = [
            'label' => 'DebugWP — Debug log file size',
            'test'  => [ $this, 'test_debug_log_size' ],
        ];

        return $tests;
    }

    /**
     * Check for debug modes that have been on for a long time.
     */
    public function test_stale_debug_modes() {
        $enabled = $this->core->get_enabled_slugs();
        $plugins = $this->core->get_supported_plugins();
        $stale   = [];

        foreach ( $enabled as $slug ) {
            $enabled_at = get_option( "debugwp_{$slug}_enabled_at", 0 );
            if ( $enabled_at && ( time() - $enabled_at ) > 24 * HOUR_IN_SECONDS ) {
                $stale[] = $plugins[ $slug ]['label'] ?? $slug;
            }
        }

        if ( empty( $stale ) ) {
            return [
                'label'       => 'No stale debug modes',
                'status'      => 'good',
                'badge'       => [ 'label' => 'DebugWP', 'color' => 'blue' ],
                'description' => '<p>No plugin debug modes have been enabled for more than 24 hours.</p>',
                'test'        => 'debugwp_stale_debug',
            ];
        }

        return [
            'label'       => sprintf( '%d stale debug %s detected', count( $stale ), count( $stale ) === 1 ? 'mode' : 'modes' ),
            'status'      => 'recommended',
            'badge'       => [ 'label' => 'DebugWP', 'color' => 'orange' ],
            'description' => '<p>The following plugins have had debug mode enabled for over 24 hours: <strong>' . esc_html( implode( ', ', $stale ) ) . '</strong>. Debug modes increase database usage and should only be on during active troubleshooting.</p>',
            'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=debugwp' ) ) . '">Manage debug modes</a>',
            'test'        => 'debugwp_stale_debug',
        ];
    }

    /**
     * Check for overdue cron events.
     */
    public function test_overdue_crons() {
        $overdue = DebugWP_Cron_UI::get_overdue_events();

        if ( empty( $overdue ) ) {
            return [
                'label'       => 'Cron events running on time',
                'status'      => 'good',
                'badge'       => [ 'label' => 'DebugWP', 'color' => 'blue' ],
                'description' => '<p>All supported-plugin cron events are running on schedule.</p>',
                'test'        => 'debugwp_overdue_crons',
            ];
        }

        $hooks = array_unique( wp_list_pluck( $overdue, 'hook' ) );

        return [
            'label'       => sprintf( '%d overdue cron %s', count( $overdue ), count( $overdue ) === 1 ? 'event' : 'events' ),
            'status'      => 'critical',
            'badge'       => [ 'label' => 'DebugWP', 'color' => 'red' ],
            'description' => '<p>These cron hooks are overdue: <code>' . esc_html( implode( '</code>, <code>', $hooks ) ) . '</code>. This usually means WP-Cron is not running reliably. Consider setting up a real system cron.</p>',
            'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=debugwp-cron' ) ) . '">View cron events</a>',
            'test'        => 'debugwp_overdue_crons',
        ];
    }

    /**
     * Check for high error rate in the last 24 hours.
     */
    public function test_error_rate() {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';
        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE severity = 'error' AND created_at >= %s",
            $since
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $count === 0 ) {
            return [
                'label'       => 'No errors in the last 24 hours',
                'status'      => 'good',
                'badge'       => [ 'label' => 'DebugWP', 'color' => 'blue' ],
                'description' => '<p>No error-level log entries have been recorded in the last 24 hours.</p>',
                'test'        => 'debugwp_error_rate',
            ];
        }

        $status = $count > 50 ? 'critical' : ( $count > 10 ? 'recommended' : 'good' );
        $color  = $count > 50 ? 'red' : ( $count > 10 ? 'orange' : 'blue' );

        return [
            'label'       => sprintf( '%d %s in the last 24 hours', $count, $count === 1 ? 'error' : 'errors' ),
            'status'      => $status,
            'badge'       => [ 'label' => 'DebugWP', 'color' => $color ],
            'description' => '<p>' . sprintf( 'DebugWP has captured %d error-level entries in the last 24 hours.', $count ) . '</p>',
            'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=debugwp-logs&severity=error' ) ) . '">View errors</a>',
            'test'        => 'debugwp_error_rate',
        ];
    }

    /**
     * Check wp-content/debug.log file size.
     */
    public function test_debug_log_size() {
        $log_file = WP_CONTENT_DIR . '/debug.log';

        if ( ! file_exists( $log_file ) ) {
            return [
                'label'       => 'No debug.log file',
                'status'      => 'good',
                'badge'       => [ 'label' => 'DebugWP', 'color' => 'blue' ],
                'description' => '<p>The <code>debug.log</code> file does not exist, which is normal when <code>WP_DEBUG_LOG</code> is off.</p>',
                'test'        => 'debugwp_debug_log_size',
            ];
        }

        $size    = filesize( $log_file );
        $size_mb = round( $size / 1024 / 1024, 1 );

        if ( $size_mb < 10 ) {
            return [
                'label'       => sprintf( 'debug.log is %s MB', $size_mb ),
                'status'      => 'good',
                'badge'       => [ 'label' => 'DebugWP', 'color' => 'blue' ],
                'description' => '<p>The <code>debug.log</code> file is a manageable size.</p>',
                'test'        => 'debugwp_debug_log_size',
            ];
        }

        $status = $size_mb > 100 ? 'critical' : 'recommended';
        $color  = $size_mb > 100 ? 'red' : 'orange';

        return [
            'label'       => sprintf( 'debug.log is %s MB — consider truncating', $size_mb ),
            'status'      => $status,
            'badge'       => [ 'label' => 'DebugWP', 'color' => $color ],
            'description' => '<p>The <code>debug.log</code> file at <code>wp-content/debug.log</code> is ' . esc_html( $size_mb ) . ' MB. Large log files can slow down your server and consume disk space. Consider truncating or rotating the file.</p>',
            'test'        => 'debugwp_debug_log_size',
        ];
    }
}
