<?php
/**
 * WP-CLI integration for DebugWP.
 *
 * Commands:
 *   wp debugwp status             — Show debug mode status for all plugins.
 *   wp debugwp toggle <slug>      — Toggle debug mode for a plugin.
 *   wp debugwp logs [--severity=] [--plugin=] [--type=] [--limit=] — List recent log entries.
 *   wp debugwp cron               — List supported-plugin cron events.
 *   wp debugwp flush              — Flush all captured logs.
 *   wp debugwp env                — Show environment information.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_CLI {

    /**
     * Show debug mode status for all supported plugins.
     *
     * ## EXAMPLES
     *     wp debugwp status
     *
     * @subcommand status
     */
    public function status( $args, $assoc_args ) {
        $core    = DebugWP::get_instance();
        $plugins = $core->get_supported_plugins();
        $rows    = [];

        foreach ( $plugins as $slug => $info ) {
            $enabled    = $core->is_debug_enabled( $slug );
            $enabled_at = get_option( "debugwp_{$slug}_enabled_at", 0 );
            $remaining  = '';

            if ( $enabled && $enabled_at ) {
                $expires = $enabled_at + ( 48 * HOUR_IN_SECONDS );
                $left    = $expires - time();
                $remaining = $left > 0 ? human_time_diff( time(), $expires ) : 'expired';
            }

            $rows[] = [
                'Plugin'    => $info['label'],
                'Slug'      => $slug,
                'Debug'     => $enabled ? 'ON' : 'off',
                'Remaining' => $remaining,
            ];
        }

        WP_CLI\Utils\format_items( 'table', $rows, [ 'Plugin', 'Slug', 'Debug', 'Remaining' ] );
    }

    /**
     * Toggle debug mode for a supported plugin.
     *
     * ## OPTIONS
     * <slug>
     * : The plugin slug (e.g., profilepress, cyclesave).
     *
     * [--on]
     * : Force debug on.
     *
     * [--off]
     * : Force debug off.
     *
     * ## EXAMPLES
     *     wp debugwp toggle profilepress
     *     wp debugwp toggle cyclesave --on
     *
     * @subcommand toggle
     */
    public function toggle( $args, $assoc_args ) {
        $core    = DebugWP::get_instance();
        $slug    = sanitize_key( $args[0] );
        $plugins = $core->get_supported_plugins();

        if ( ! isset( $plugins[ $slug ] ) ) {
            WP_CLI::error( "Unknown plugin slug: {$slug}. Available: " . implode( ', ', array_keys( $plugins ) ) );
        }

        $current = $core->is_debug_enabled( $slug );

        if ( isset( $assoc_args['on'] ) ) {
            $new_state = true;
        } elseif ( isset( $assoc_args['off'] ) ) {
            $new_state = false;
        } else {
            $new_state = ! $current;
        }

        update_option( "debugwp_{$slug}_enabled", $new_state ? 1 : 0 );
        if ( $new_state ) {
            update_option( "debugwp_{$slug}_enabled_at", time() );
        }

        $label = $plugins[ $slug ]['label'];
        WP_CLI::success( sprintf( 'Debug mode for %s is now %s.', $label, $new_state ? 'ON' : 'OFF' ) );
    }

    /**
     * List recent log entries.
     *
     * ## OPTIONS
     * [--severity=<severity>]
     * : Filter by severity (error, warning, info, debug).
     *
     * [--plugin=<slug>]
     * : Filter by plugin slug.
     *
     * [--type=<type>]
     * : Filter by log type (http_request, php_error, email, cron, etc.).
     *
     * [--limit=<number>]
     * : Number of entries to show. Default 20.
     *
     * [--format=<format>]
     * : Output format (table, csv, json, yaml). Default table.
     *
     * ## EXAMPLES
     *     wp debugwp logs
     *     wp debugwp logs --severity=error --limit=50
     *     wp debugwp logs --plugin=profilepress --format=json
     *
     * @subcommand logs
     */
    public function logs( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        $limit  = absint( $assoc_args['limit'] ?? 20 );
        $format = $assoc_args['format'] ?? 'table';
        $where  = [];
        $params = [];

        if ( ! empty( $assoc_args['severity'] ) ) {
            $where[]  = 'severity = %s';
            $params[] = sanitize_key( $assoc_args['severity'] );
        }
        if ( ! empty( $assoc_args['plugin'] ) ) {
            $where[]  = 'plugin_slug = %s';
            $params[] = sanitize_key( $assoc_args['plugin'] );
        }
        if ( ! empty( $assoc_args['type'] ) ) {
            $where[]  = 'log_type = %s';
            $params[] = sanitize_key( $assoc_args['type'] );
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql       = "SELECT id, created_at, plugin_slug, log_type, severity, hit_count, message FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d";
        $params[]  = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        if ( empty( $rows ) ) {
            WP_CLI::log( 'No log entries found.' );
            return;
        }

        // Truncate message for table format.
        if ( $format === 'table' ) {
            foreach ( $rows as &$row ) {
                $row['message'] = mb_substr( $row['message'], 0, 100 );
            }
        }

        WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'created_at', 'plugin_slug', 'log_type', 'severity', 'hit_count', 'message' ] );
    }

    /**
     * List supported-plugin cron events.
     *
     * ## EXAMPLES
     *     wp debugwp cron
     *
     * @subcommand cron
     */
    public function cron( $args, $assoc_args ) {
        $core    = DebugWP::get_instance();
        $crons   = _get_cron_array();
        $patterns = $core->get_all_cron_hook_patterns();
        $plugins = $core->get_supported_plugins();
        $plugins['debugwp'] = [ 'label' => 'DebugWP' ];
        $now     = time();
        $rows    = [];

        if ( empty( $crons ) ) {
            WP_CLI::log( 'No cron events scheduled.' );
            return;
        }

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $instances ) {
                // Check if hook matches any supported pattern.
                $matched_slug = null;
                foreach ( $patterns as $slug => $prefixes ) {
                    foreach ( $prefixes as $prefix ) {
                        if ( strpos( $hook, $prefix ) !== false ) {
                            $matched_slug = $slug;
                            break 2;
                        }
                    }
                }
                if ( ! $matched_slug ) {
                    continue;
                }

                foreach ( $instances as $data ) {
                    $is_overdue = ( $timestamp <= 10 ) ? false : $timestamp < ( $now - 600 );
                    $status     = $timestamp <= 10 ? 'Queued (manual)' : ( $is_overdue ? 'OVERDUE' : 'OK' );
                    $schedule   = $data['schedule'] ?: 'One-off';

                    $rows[] = [
                        'Hook'     => $hook,
                        'Plugin'   => $plugins[ $matched_slug ]['label'] ?? $matched_slug,
                        'Next Run' => $timestamp <= 10 ? 'Immediate' : gmdate( 'Y-m-d H:i:s', $timestamp ),
                        'Schedule' => $schedule,
                        'Status'   => $status,
                    ];
                }
            }
        }

        if ( empty( $rows ) ) {
            WP_CLI::log( 'No supported-plugin cron events found.' );
            return;
        }

        WP_CLI\Utils\format_items( 'table', $rows, [ 'Hook', 'Plugin', 'Next Run', 'Schedule', 'Status' ] );
    }

    /**
     * Flush all captured log entries.
     *
     * ## OPTIONS
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *     wp debugwp flush
     *     wp debugwp flush --yes
     *
     * @subcommand flush
     */
    public function flush( $args, $assoc_args ) {
        WP_CLI::confirm( 'This will delete ALL captured log entries. Continue?', $assoc_args );

        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';
        $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        WP_CLI::success( 'All log entries deleted.' );
    }

    /**
     * Show environment information.
     *
     * ## EXAMPLES
     *     wp debugwp env
     *
     * @subcommand env
     */
    public function env( $args, $assoc_args ) {
        $info = DebugWP_Environment::get_info();
        $rows = [];

        foreach ( $info as $section => $items ) {
            foreach ( $items as $key => $value ) {
                $rows[] = [
                    'Section' => $section,
                    'Item'    => $key,
                    'Value'   => is_array( $value ) ? implode( ', ', $value ) : (string) $value,
                ];
            }
        }

        WP_CLI\Utils\format_items( 'table', $rows, [ 'Section', 'Item', 'Value' ] );
    }
}
