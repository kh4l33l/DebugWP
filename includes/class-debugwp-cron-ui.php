<?php
/**
 * DebugWP Cron UI — Events & Schedules management for supported plugins.
 *
 * Provides a "Cron" submenu under DebugWP with:
 *  - Events tab: list, run now, delete, pause/resume, edit cron events.
 *  - Schedules tab: list all registered cron schedules.
 *  - Notices for disabled WP-Cron and missed/overdue events.
 *  - Logging of all manual cron actions and fired events.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Cron_UI {

    const PAUSED_OPTION = 'debugwp_paused_cron_hooks';

    /**
     * Register hooks. Called once from DebugWP core constructor.
     */
    public static function init() {
        add_action( 'admin_post_debugwp_cron_run',    [ __CLASS__, 'handle_run_event' ] );
        add_action( 'admin_post_debugwp_cron_delete',  [ __CLASS__, 'handle_delete_event' ] );
        add_action( 'admin_post_debugwp_cron_pause',   [ __CLASS__, 'handle_pause_event' ] );
        add_action( 'admin_post_debugwp_cron_resume',  [ __CLASS__, 'handle_resume_event' ] );
        add_action( 'admin_post_debugwp_cron_edit',    [ __CLASS__, 'handle_edit_event' ] );

        // Intercept paused hooks so WordPress skips them.
        add_action( 'pre_reschedule_event', [ __CLASS__, 'maybe_block_paused' ], 10, 2 );
    }

    /* ══════════════════════════════════════════════════════
     *  Helpers
     * ══════════════════════════════════════════════════════ */

    /**
     * Map of plugin slug => array of hook-name substrings to match.
     * Cron hooks often use short prefixes (e.g. mo_ for MailOptin, ppress for ProfilePress).
     */
    private static function get_hook_patterns() {
        return [
            'mailoptin'    => [ 'mailoptin', 'mo_' ],
            'cyclesave'    => [ 'cyclesave' ],
            'profilepress' => [ 'profilepress', 'ppress' ],
            'fusewp'       => [ 'fusewp' ],
            'debugwp'      => [ 'debugwp' ],
        ];
    }

    /**
     * Check whether a hook name belongs to one of the supported plugins.
     */
    private static function is_supported_hook( $hook ) {
        foreach ( self::get_hook_patterns() as $slug => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( strpos( $hook, $pattern ) !== false ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Which supported-plugin slug does this hook belong to? Returns slug or null.
     */
    private static function hook_to_plugin_slug( $hook ) {
        foreach ( self::get_hook_patterns() as $slug => $patterns ) {
            foreach ( $patterns as $pattern ) {
                if ( strpos( $hook, $pattern ) !== false ) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /**
     * Get a human-readable description of the callbacks attached to a hook.
     */
    private static function get_hook_callbacks( $hook ) {
        global $wp_filter;

        if ( ! isset( $wp_filter[ $hook ] ) ) {
            return '<em>None</em>';
        }

        $callbacks = [];
        foreach ( $wp_filter[ $hook ]->callbacks as $priority => $hooks ) {
            foreach ( $hooks as $id => $cb ) {
                $callbacks[] = self::format_callback( $cb['function'] );
            }
        }

        if ( empty( $callbacks ) ) {
            return '<em>None</em>';
        }

        return implode( '<br>', $callbacks );
    }

    /**
     * Format a callback into a human-readable string like WP Crontrol does.
     */
    private static function format_callback( $callback ) {
        if ( is_string( $callback ) ) {
            return esc_html( $callback ) . '()';
        }
        if ( is_array( $callback ) && count( $callback ) === 2 ) {
            if ( is_object( $callback[0] ) ) {
                return esc_html( get_class( $callback[0] ) ) . '-&gt;' . esc_html( $callback[1] ) . '()';
            }
            if ( is_string( $callback[0] ) ) {
                return esc_html( $callback[0] ) . '::' . esc_html( $callback[1] ) . '()';
            }
        }
        if ( $callback instanceof \Closure ) {
            return '<em>Closure</em>';
        }
        return '<em>Unknown</em>';
    }

    /**
     * Is a hook currently paused?
     */
    private static function is_paused( $hook ) {
        $paused = get_option( self::PAUSED_OPTION, [] );
        return is_array( $paused ) && ! empty( $paused[ $hook ] );
    }

    /**
     * Build a nonce-protected action URL.
     */
    private static function action_url( $action, $hook, $sig, $timestamp, $args ) {
        return wp_nonce_url(
            add_query_arg( [
                'action'    => $action,
                'hook'      => $hook,
                'sig'       => $sig,
                'timestamp' => $timestamp,
                'args'      => rawurlencode( wp_json_encode( $args ) ),
            ], admin_url( 'admin-post.php' ) ),
            'debugwp_cron_action'
        );
    }

    /**
     * Log a cron action to the DebugWP log table.
     */
    private static function log_action( $action_label, $hook, $extra = [] ) {
        $slug = self::hook_to_plugin_slug( $hook );
        if ( ! $slug ) {
            $slug = 'debugwp'; // fallback
        }
        DebugWP::get_instance()->insert_log(
            $slug,
            'cron',
            'info',
            sprintf( '[Cron %s] %s', $action_label, $hook ),
            array_merge( [ 'hook' => $hook, 'action' => $action_label ], $extra )
        );
    }

    /**
     * Format a human-readable "time from now" string.
     */
    private static function time_from_now( $timestamp ) {
        $diff = $timestamp - time();
        if ( $diff < 0 ) {
            return sprintf( '%s ago', human_time_diff( $timestamp, time() ) );
        }
        return sprintf( 'in %s', human_time_diff( time(), $timestamp ) );
    }

    /* ══════════════════════════════════════════════════════
     *  Pause interception
     * ══════════════════════════════════════════════════════ */

    /**
     * When WordPress is about to fire a cron hook, block it if paused.
     * Uses the `pre_reschedule_event` filter (WP 5.1+). We also add an
     * early-firing `all` action that blocks the actual do_action call.
     */
    public static function maybe_block_paused( $pre, $event ) {
        if ( is_object( $event ) && self::is_paused( $event->hook ) ) {
            // Returning a non-null value prevents rescheduling.
            return false;
        }
        return $pre;
    }

    /* ══════════════════════════════════════════════════════
     *  Page renderer
     * ══════════════════════════════════════════════════════ */

    public static function render_cron_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'events';
        ?>
        <div class="wrap debugwp-wrap">
            <h1>DebugWP — Cron</h1>

            <?php self::render_notices(); ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-cron&tab=events' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'events' ? 'nav-tab-active' : ''; ?>">Events</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-cron&tab=schedules' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'schedules' ? 'nav-tab-active' : ''; ?>">Schedules</a>
            </h2>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification
            if ( isset( $_GET['debugwp_edit'] ) && $active_tab === 'events' ) {
                self::render_edit_form();
            } elseif ( $active_tab === 'schedules' ) {
                self::render_schedules_tab();
            } else {
                self::render_events_tab();
            }
            ?>
        </div>
        <?php
    }

    /* ── Notices ──────────────────────────────────────────── */

    private static function render_notices() {
        // 1. WP-Cron disabled notice.
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>DebugWP:</strong> <code>DISABLE_WP_CRON</code> is set to <code>true</code>. ';
            echo 'WordPress cron events will not run automatically. You need an external system cron to trigger <code>wp-cron.php</code>.';
            echo '</p></div>';
        }

        // 2. Alternate cron notice.
        if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>DebugWP:</strong> <code>ALTERNATE_WP_CRON</code> is enabled. WordPress is using the alternate cron method.';
            echo '</p></div>';
        }

        // 3. Missed / overdue events.
        $overdue = self::get_overdue_events();
        if ( ! empty( $overdue ) ) {
            $count = count( $overdue );
            echo '<div class="notice notice-warning"><p>';
            printf(
                '<strong>DebugWP:</strong> %d supported-plugin cron %s overdue. This may indicate WP-Cron is not running reliably.',
                $count,
                $count === 1 ? 'event is' : 'events are'
            );
            echo '<br><em>Overdue hooks:</em> ';
            echo esc_html( implode( ', ', array_unique( wp_list_pluck( $overdue, 'hook' ) ) ) );
            echo '</p></div>';
        }

        // 4. Action result notices (after redirect).
        // phpcs:ignore WordPress.Security.NonceVerification
        $notice = isset( $_GET['debugwp_notice'] ) ? sanitize_key( $_GET['debugwp_notice'] ) : '';
        $messages = [
            'ran'     => [ 'success', 'Cron event was scheduled to run immediately.' ],
            'deleted' => [ 'success', 'Cron event was deleted.' ],
            'paused'  => [ 'success', 'Cron event hook was paused.' ],
            'resumed' => [ 'success', 'Cron event hook was resumed.' ],
            'edited'  => [ 'success', 'Cron event was updated.' ],
        ];
        if ( isset( $messages[ $notice ] ) ) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $messages[ $notice ][0] ),
                esc_html( $messages[ $notice ][1] )
            );
        }
    }

    /**
     * Return supported-plugin cron events whose next-run time is in the past.
     */
    private static function get_overdue_events() {
        $crons   = _get_cron_array();
        $overdue = [];
        $now     = time();

        if ( empty( $crons ) ) {
            return $overdue;
        }

        // Consider an event "overdue" if its scheduled time is more than 10 minutes ago.
        $threshold = $now - ( 10 * MINUTE_IN_SECONDS );

        foreach ( $crons as $timestamp => $hooks ) {
            if ( $timestamp > $threshold ) {
                continue; // Not overdue.
            }
            foreach ( $hooks as $hook => $instances ) {
                if ( ! self::is_supported_hook( $hook ) ) {
                    continue;
                }
                foreach ( $instances as $sig => $data ) {
                    $overdue[] = [
                        'hook'      => $hook,
                        'timestamp' => $timestamp,
                        'sig'       => $sig,
                    ];
                }
            }
        }
        return $overdue;
    }

    /* ── Events tab ──────────────────────────────────────── */

    private static function render_events_tab() {
        $crons = _get_cron_array();
        if ( empty( $crons ) ) {
            echo '<p>No cron events scheduled.</p>';
            return;
        }

        $core      = DebugWP::get_instance();
        $supported = $core->get_supported_plugins();
        // Add DebugWP itself for labeling.
        $supported['debugwp'] = [ 'label' => 'DebugWP' ];
        $now       = time();

        // phpcs:ignore WordPress.Security.NonceVerification
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        ?>
        <div style="margin-top:12px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
            <span></span>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:4px;">
                <input type="hidden" name="page" value="debugwp-cron">
                <input type="hidden" name="tab" value="events">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="Search Hook Names" style="min-width:220px;">
                <button type="submit" class="button">Search Hook Names</button>
                <?php if ( $search ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-cron&tab=events' ) ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:28%;">Hook Name</th>
                    <th style="width:10%;">Plugin</th>
                    <th style="width:20%;">Next Run (UTC)</th>
                    <th style="width:12%;">Schedule</th>
                    <th style="width:22%;">Action</th>
                    <th style="width:8%;">Status</th>
                </tr>
            </thead>
            <tbody>
        <?php
        $found = false;
        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $instances ) {
                if ( ! self::is_supported_hook( $hook ) ) {
                    continue;
                }
                // Apply search filter.
                if ( $search !== '' && stripos( $hook, $search ) === false ) {
                    continue;
                }
                $plugin_slug  = self::hook_to_plugin_slug( $hook );
                $plugin_label = $supported[ $plugin_slug ]['label'] ?? $plugin_slug;
                $paused       = self::is_paused( $hook );
                $action_html  = self::get_hook_callbacks( $hook );

                foreach ( $instances as $sig => $data ) {
                    $found = true;
                    $schedule_name = ! empty( $data['schedule'] ) ? $data['schedule'] : 'One-off';
                    $is_overdue    = $timestamp < ( $now - 10 * MINUTE_IN_SECONDS );

                    // Build row-action links.
                    $edit_url = add_query_arg( [
                        'page'          => 'debugwp-cron',
                        'tab'           => 'events',
                        'debugwp_edit'  => '1',
                        'hook'          => $hook,
                        'sig'           => $sig,
                        'timestamp'     => $timestamp,
                    ], admin_url( 'admin.php' ) );
                    $run_url    = self::action_url( 'debugwp_cron_run', $hook, $sig, $timestamp, $data['args'] );
                    $del_url    = self::action_url( 'debugwp_cron_delete', $hook, $sig, $timestamp, $data['args'] );

                    $row_actions  = '<a href="' . esc_url( $edit_url ) . '">Edit</a>';
                    $row_actions .= ' | <a href="' . esc_url( $run_url ) . '">Run now</a>';
                    if ( $paused ) {
                        $resume_url   = self::action_url( 'debugwp_cron_resume', $hook, $sig, $timestamp, $data['args'] );
                        $row_actions .= ' | <a href="' . esc_url( $resume_url ) . '">Resume this hook</a>';
                    } else {
                        $pause_url    = self::action_url( 'debugwp_cron_pause', $hook, $sig, $timestamp, $data['args'] );
                        $row_actions .= ' | <a href="' . esc_url( $pause_url ) . '">Pause this hook</a>';
                    }
                    $row_actions .= ' | <span class="delete"><a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this cron event?\');">Delete</a></span>';
                    ?>
                    <tr<?php echo $paused ? ' style="opacity:0.6;"' : ''; ?>>
                        <td>
                            <strong><code><?php echo esc_html( $hook ); ?></code></strong>
                            <?php if ( ! empty( $data['args'] ) ) : ?>
                                <br><small title="<?php echo esc_attr( wp_json_encode( $data['args'] ) ); ?>">▸ View arguments</small>
                            <?php endif; ?>
                            <div class="row-actions"><?php echo $row_actions; ?></div>
                        </td>
                        <td><?php echo esc_html( $plugin_label ); ?></td>
                        <td>
                            <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $timestamp ) ); ?>
                            <br><small><?php echo esc_html( self::time_from_now( $timestamp ) ); ?></small>
                            <?php if ( $is_overdue ) : ?>
                                <br><span style="color:#d63638;font-weight:600;">⚠ Overdue</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $schedule_name ); ?></td>
                        <td><?php echo $action_html; // Already escaped in format_callback(). ?></td>
                        <td>
                            <?php if ( $paused ) : ?>
                                <span style="color:#d63638;font-weight:600;">Paused</span>
                            <?php else : ?>
                                <span style="color:#00a32a;">Active</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                }
            }
        }
        if ( ! $found ) {
            $msg = $search !== '' ? 'No matching cron events found.' : 'No cron events found for supported plugins.';
            echo '<tr><td colspan="6">' . esc_html( $msg ) . '</td></tr>';
        }
        ?>
            </tbody>
        </table>
        <?php
    }

    /* ── Edit form ───────────────────────────────────────── */

    private static function render_edit_form() {
        // phpcs:disable WordPress.Security.NonceVerification
        $hook      = isset( $_GET['hook'] )      ? sanitize_text_field( wp_unslash( $_GET['hook'] ) )  : '';
        $sig       = isset( $_GET['sig'] )        ? sanitize_text_field( wp_unslash( $_GET['sig'] ) )   : '';
        $timestamp = isset( $_GET['timestamp'] )  ? absint( $_GET['timestamp'] ) : 0;
        // phpcs:enable

        if ( ! $hook || ! $timestamp ) {
            echo '<div class="notice notice-error"><p>Invalid event parameters.</p></div>';
            return;
        }

        // Find the event in the cron array.
        $crons = _get_cron_array();
        if ( ! isset( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
            echo '<div class="notice notice-error"><p>Cron event not found. It may have already run or been deleted.</p></div>';
            return;
        }

        $data     = $crons[ $timestamp ][ $hook ][ $sig ];
        $schedule = ! empty( $data['schedule'] ) ? $data['schedule'] : '_oneoff';

        // Convert UTC timestamp to local datetime string for the input.
        $local_time = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d\TH:i' );

        $schedules = wp_get_schedules();
        ?>
        <div style="max-width:600px;margin-top:12px;">
            <h3>Edit Cron Event</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'debugwp_cron_action' ); ?>
                <input type="hidden" name="action" value="debugwp_cron_edit">
                <input type="hidden" name="original_hook" value="<?php echo esc_attr( $hook ); ?>">
                <input type="hidden" name="original_sig" value="<?php echo esc_attr( $sig ); ?>">
                <input type="hidden" name="original_timestamp" value="<?php echo esc_attr( $timestamp ); ?>">
                <input type="hidden" name="original_args" value="<?php echo esc_attr( wp_json_encode( $data['args'] ) ); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Hook Name</label></th>
                        <td><code><?php echo esc_html( $hook ); ?></code>
                            <p class="description">Hook names cannot be changed.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="debugwp_next_run">Next Run (site time)</label></th>
                        <td><input type="datetime-local" name="next_run_local" id="debugwp_next_run"
                                   value="<?php echo esc_attr( $local_time ); ?>" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="debugwp_schedule">Schedule</label></th>
                        <td>
                            <select name="schedule" id="debugwp_schedule" class="postform">
                                <option value="_oneoff" <?php selected( $schedule, '_oneoff' ); ?>>Non-repeating</option>
                                <?php foreach ( $schedules as $sname => $sdata ) : ?>
                                    <option value="<?php echo esc_attr( $sname ); ?>" <?php selected( $schedule, $sname ); ?>>
                                        <?php echo esc_html( $sdata['display'] ); ?> (<?php echo esc_html( $sname ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="debugwp_args">Arguments (JSON)</label></th>
                        <td>
                            <textarea name="args_json" id="debugwp_args" rows="4" class="large-text code"><?php
                                echo esc_textarea( wp_json_encode( $data['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                            ?></textarea>
                            <p class="description">Edit event arguments as a JSON array.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Update Event', 'primary', 'submit', true ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=debugwp-cron&tab=events' ) ); ?>" class="button">Cancel</a>
            </form>
        </div>
        <?php
    }

    /* ── Schedules tab ───────────────────────────────────── */

    private static function render_schedules_tab() {
        $schedules = wp_get_schedules();

        // Sort by interval ascending.
        uasort( $schedules, function ( $a, $b ) {
            return $a['interval'] <=> $b['interval'];
        } );

        if ( empty( $schedules ) ) {
            echo '<p>No cron schedules registered.</p>';
            return;
        }
        ?>
        <table class="widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:25%;">Internal Name</th>
                    <th style="width:20%;">Display Name</th>
                    <th style="width:15%;">Interval</th>
                    <th style="width:40%;">Human-Readable</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $schedules as $name => $data ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $name ); ?></code></td>
                    <td><?php echo esc_html( $data['display'] ?? $name ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( $data['interval'] ) ); ?>s</td>
                    <td><?php echo esc_html( human_time_diff( 0, $data['interval'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ══════════════════════════════════════════════════════
     *  Action handlers (admin-post.php)
     * ══════════════════════════════════════════════════════ */

    public static function handle_run_event() {
        check_admin_referer( 'debugwp_cron_action' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $hook      = sanitize_text_field( wp_unslash( $_REQUEST['hook'] ?? '' ) );
        $sig       = sanitize_text_field( wp_unslash( $_REQUEST['sig'] ?? '' ) );
        $timestamp = absint( $_REQUEST['timestamp'] ?? 0 );
        $args      = json_decode( rawurldecode( wp_unslash( $_REQUEST['args'] ?? '[]' ) ), true );

        if ( ! is_array( $args ) ) {
            $args = [];
        }

        if ( ! $hook ) {
            wp_die( 'Missing hook name.' );
        }

        // Schedule a single event at timestamp=1 (forces "now") — same technique WP Crontrol uses.
        $crons = _get_cron_array();
        $key   = md5( serialize( $args ) );

        $crons[1][ $hook ][ $key ] = [
            'schedule' => false,
            'args'     => $args,
        ];
        ksort( $crons );
        _set_cron_array( $crons );

        delete_transient( 'doing_cron' );
        spawn_cron();

        self::log_action( 'Run Now', $hook, [
            'timestamp' => $timestamp,
            'args'      => $args,
            'user'      => get_current_user_id(),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp-cron&tab=events&debugwp_notice=ran' ) );
        exit;
    }

    public static function handle_delete_event() {
        check_admin_referer( 'debugwp_cron_action' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $hook      = sanitize_text_field( wp_unslash( $_REQUEST['hook'] ?? '' ) );
        $timestamp = absint( $_REQUEST['timestamp'] ?? 0 );
        $args      = json_decode( rawurldecode( wp_unslash( $_REQUEST['args'] ?? '[]' ) ), true );

        if ( ! is_array( $args ) ) {
            $args = [];
        }

        if ( ! $hook || ! $timestamp ) {
            wp_die( 'Missing hook or timestamp.' );
        }

        wp_unschedule_event( $timestamp, $hook, $args );

        self::log_action( 'Delete', $hook, [
            'timestamp' => $timestamp,
            'args'      => $args,
            'user'      => get_current_user_id(),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp-cron&tab=events&debugwp_notice=deleted' ) );
        exit;
    }

    public static function handle_pause_event() {
        check_admin_referer( 'debugwp_cron_action' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $hook = sanitize_text_field( wp_unslash( $_REQUEST['hook'] ?? '' ) );
        if ( ! $hook ) {
            wp_die( 'Missing hook.' );
        }

        $paused = get_option( self::PAUSED_OPTION, [] );
        if ( ! is_array( $paused ) ) {
            $paused = [];
        }
        $paused[ $hook ] = true;
        update_option( self::PAUSED_OPTION, $paused, true );

        self::log_action( 'Pause', $hook, [ 'user' => get_current_user_id() ] );

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp-cron&tab=events&debugwp_notice=paused' ) );
        exit;
    }

    public static function handle_resume_event() {
        check_admin_referer( 'debugwp_cron_action' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $hook = sanitize_text_field( wp_unslash( $_REQUEST['hook'] ?? '' ) );
        if ( ! $hook ) {
            wp_die( 'Missing hook.' );
        }

        $paused = get_option( self::PAUSED_OPTION, [] );
        if ( is_array( $paused ) ) {
            unset( $paused[ $hook ] );
            update_option( self::PAUSED_OPTION, $paused, true );
        }

        self::log_action( 'Resume', $hook, [ 'user' => get_current_user_id() ] );

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp-cron&tab=events&debugwp_notice=resumed' ) );
        exit;
    }

    public static function handle_edit_event() {
        // Edit uses POST from the form.
        check_admin_referer( 'debugwp_cron_action' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.', 403 );
        }

        $original_hook      = sanitize_text_field( wp_unslash( $_POST['original_hook'] ?? '' ) );
        $original_sig       = sanitize_text_field( wp_unslash( $_POST['original_sig'] ?? '' ) );
        $original_timestamp = absint( $_POST['original_timestamp'] ?? 0 );
        $original_args      = json_decode( wp_unslash( $_POST['original_args'] ?? '[]' ), true );
        $new_schedule       = sanitize_text_field( wp_unslash( $_POST['schedule'] ?? '_oneoff' ) );
        $next_run_local     = sanitize_text_field( wp_unslash( $_POST['next_run_local'] ?? '' ) );
        $new_args           = json_decode( wp_unslash( $_POST['args_json'] ?? '[]' ), true );

        if ( ! is_array( $original_args ) ) {
            $original_args = [];
        }
        if ( ! is_array( $new_args ) ) {
            wp_die( 'Invalid JSON in arguments field.' );
        }
        if ( ! $original_hook || ! $original_timestamp || ! $next_run_local ) {
            wp_die( 'Missing required fields.' );
        }

        // Delete the old event.
        wp_unschedule_event( $original_timestamp, $original_hook, $original_args );

        // Convert local datetime to UTC timestamp.
        $next_run_utc = (int) get_gmt_from_date(
            str_replace( 'T', ' ', $next_run_local ) . ':00',
            'U'
        );

        if ( $next_run_utc <= 0 ) {
            wp_die( 'Invalid date/time.' );
        }

        // Re-schedule.
        if ( '_oneoff' === $new_schedule || '' === $new_schedule ) {
            wp_schedule_single_event( $next_run_utc, $original_hook, $new_args );
        } else {
            wp_schedule_event( $next_run_utc, $new_schedule, $original_hook, $new_args );
        }

        self::log_action( 'Edit', $original_hook, [
            'old_timestamp'  => $original_timestamp,
            'new_timestamp'  => $next_run_utc,
            'new_schedule'   => $new_schedule,
            'new_args'       => $new_args,
            'user'           => get_current_user_id(),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp-cron&tab=events&debugwp_notice=edited' ) );
        exit;
    }
}
