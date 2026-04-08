<?php
/**
 * WP Dashboard Widget — at-a-glance DebugWP status.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Dashboard_Widget {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_action( 'wp_dashboard_setup', [ $this, 'register' ] );
    }

    public function register() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'debugwp_dashboard',
            'DebugWP — Status',
            [ $this, 'render' ]
        );
    }

    public function render() {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        // Error count in last 24 hours.
        $since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $errors = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE severity = 'error' AND created_at >= %s",
            $since
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $warnings = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE severity = 'warning' AND created_at >= %s",
            $since
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            $since
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        // Active debug modes.
        $enabled = $this->core->get_enabled_slugs();
        $plugins = $this->core->get_supported_plugins();

        // Overdue crons.
        $overdue = class_exists( 'DebugWP_Cron_UI' ) ? DebugWP_Cron_UI::get_overdue_events() : [];

        ?>
        <div class="debugwp-dashboard-widget">
            <h4>Last 24 Hours</h4>
            <ul>
                <li>
                    <span class="debugwp-dw-count <?php echo $errors ? 'debugwp-dw-bad' : 'debugwp-dw-ok'; ?>">
                        <?php echo esc_html( $errors ); ?>
                    </span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-logs&severity=error' ) ); ?>">Errors</a>
                </li>
                <li>
                    <span class="debugwp-dw-count <?php echo $warnings ? 'debugwp-dw-warn' : 'debugwp-dw-ok'; ?>">
                        <?php echo esc_html( $warnings ); ?>
                    </span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-logs&severity=warning' ) ); ?>">Warnings</a>
                </li>
                <li>
                    <span class="debugwp-dw-count"><?php echo esc_html( $total ); ?></span>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-logs' ) ); ?>">Total log entries</a>
                </li>
            </ul>

            <?php if ( ! empty( $enabled ) ) : ?>
                <h4>Active Debug Modes</h4>
                <p>
                    <?php
                    $labels = array_map( function ( $s ) use ( $plugins ) {
                        return '<strong>' . esc_html( $plugins[ $s ]['label'] ?? $s ) . '</strong>';
                    }, $enabled );
                    echo wp_kses( implode( ', ', $labels ), [ 'strong' => [] ] );
                    ?>
                    — <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp' ) ); ?>">Manage</a>
                </p>
            <?php else : ?>
                <p><em>No debug modes active.</em> <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp' ) ); ?>">Settings</a></p>
            <?php endif; ?>

            <?php if ( ! empty( $overdue ) ) : ?>
                <h4 style="color:#d63638;">⚠ Overdue Cron Events</h4>
                <p>
                    <?php
                    printf(
                        '%d %s overdue.',
                        count( $overdue ),
                        count( $overdue ) === 1 ? 'event' : 'events'
                    );
                    ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-cron' ) ); ?>">View Cron</a>
                </p>
            <?php endif; ?>
        </div>
        <style>
            .debugwp-dashboard-widget ul { margin: 0; padding: 0; list-style: none; }
            .debugwp-dashboard-widget li { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
            .debugwp-dw-count { display: inline-block; min-width: 28px; text-align: center; font-weight: 600; font-size: 14px; padding: 2px 6px; border-radius: 3px; background: #f0f0f1; }
            .debugwp-dw-bad { background: #fcebea; color: #d63638; }
            .debugwp-dw-warn { background: #fef8ee; color: #996800; }
            .debugwp-dw-ok { background: #edfaef; color: #00a32a; }
            .debugwp-dashboard-widget h4 { margin: 12px 0 6px; }
            .debugwp-dashboard-widget h4:first-child { margin-top: 0; }
        </style>
        <?php
    }
}
