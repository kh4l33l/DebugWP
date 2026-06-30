<?php
/**
 * Settings page — WP Config toggles, plugin debug toggles, log settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Settings {

    /** @var DebugWP */
    private $core;

    /** @var DebugWP_Environment */
    private $environment;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'set_screen_option_debugwp_logs_per_page', [ $this, 'save_screen_option' ], 10, 3 );
    }

    /* ── Menu ────────────────────────────────────────────── */

    public function register_menu() {
        add_menu_page(
            'DebugWP',
            'DebugWP',
            'manage_options',
            'debugwp',
            [ $this, 'render_settings_page' ],
            'dashicons-admin-tools',
            2
        );

        add_submenu_page(
            'debugwp',
            'Settings — DebugWP',
            'Settings',
            'manage_options',
            'debugwp',
            [ $this, 'render_settings_page' ]
        );

        $logs_hook = add_submenu_page(
            'debugwp',
            'Log Viewer — DebugWP',
            'Log Viewer',
            'manage_options',
            'debugwp-logs',
            [ $this, 'render_log_viewer_page' ]
        );

        add_action( "load-{$logs_hook}", [ $this, 'register_screen_options' ] );

        // Add Cron submenu
        add_submenu_page(
            'debugwp',
            'Cron — DebugWP',
            'Cron',
            'manage_options',
            'debugwp-cron',
            [ 'DebugWP_Cron_UI', 'render_cron_page' ]
        );

        // Add Environment submenu
        $this->environment = new DebugWP_Environment( $this->core );
        add_submenu_page(
            'debugwp',
            'Environment — DebugWP',
            'Environment',
            'manage_options',
            'debugwp-env',
            [ $this->environment, 'render_page' ]
        );
    }

    public function register_screen_options() {
        add_screen_option( 'per_page', [
            'label'   => 'Entries per page',
            'default' => 20,
            'option'  => 'debugwp_logs_per_page',
        ] );
    }

    public function save_screen_option( $status, $option, $value ) {
        return absint( $value );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'debugwp' ) === false ) {
            return;
        }
        wp_enqueue_style( 'debugwp-admin', DEBUGWP_URL . 'assets/css/debugwp-admin.css', [], DEBUGWP_VERSION );
        wp_enqueue_script( 'debugwp-admin', DEBUGWP_URL . 'assets/js/debugwp-admin.js', [ 'jquery' ], DEBUGWP_VERSION, true );
        wp_localize_script( 'debugwp-admin', 'debugwp', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'debugwp_nonce' ),
        ] );
    }

    /* ── Settings page ───────────────────────────────────── */

    public function render_settings_page() {
        $wp_config   = DebugWP_WP_Config::read();
        $plugins     = $this->core->get_supported_plugins();
        $settings    = $this->core->get_settings();
        ?>
        <div class="wrap debugwp-wrap">
            <h1>DebugWP — Settings</h1>

            <?php if ( isset( $_GET['debugwp-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'debugwp_save_settings', 'debugwp_nonce' ); ?>

                <!-- WP Config Management -->
                <div class="debugwp-card">
                    <h2>WordPress Debug Constants</h2>
                    <p class="description">Toggle debug constants in <code>wp-config.php</code>. A backup is created automatically before any changes.</p>

                    <?php if ( DebugWP_WP_Config::has_backup() ) : ?>
                        <p class="debugwp-notice debugwp-notice-info">
                            Backup exists: <code>wp-config.php.debugwp-backup</code>
                            — <button type="submit" name="debugwp_restore_backup" value="1" class="button button-link-delete" onclick="return confirm('Restore wp-config.php from backup? Current config will be overwritten.');">Restore Backup</button>
                        </p>
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="wp_debug">WP_DEBUG</label></th>
                            <td>
                                <label class="debugwp-toggle">
                                    <input type="hidden" name="wp_config[WP_DEBUG]" value="0">
                                    <input type="checkbox" name="wp_config[WP_DEBUG]" id="wp_debug" value="1" <?php checked( ! empty( $wp_config['WP_DEBUG'] ) ); ?>>
                                    <span>Enable WordPress debug mode</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wp_debug_log">WP_DEBUG_LOG</label></th>
                            <td>
                                <label class="debugwp-toggle">
                                    <input type="hidden" name="wp_config[WP_DEBUG_LOG]" value="0">
                                    <input type="checkbox" name="wp_config[WP_DEBUG_LOG]" id="wp_debug_log" value="1" <?php checked( ! empty( $wp_config['WP_DEBUG_LOG'] ) ); ?>>
                                    <span>Log errors to <code>wp-content/debug.log</code></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wp_debug_display">WP_DEBUG_DISPLAY</label></th>
                            <td>
                                <label class="debugwp-toggle debugwp-toggle-danger">
                                    <input type="hidden" name="wp_config[WP_DEBUG_DISPLAY]" value="0">
                                    <input type="checkbox" name="wp_config[WP_DEBUG_DISPLAY]" id="wp_debug_display" value="1" <?php checked( ! empty( $wp_config['WP_DEBUG_DISPLAY'] ) ); ?>>
                                    <span>Display errors on screen</span>
                                </label>
                                <p class="description debugwp-warning">⚠️ <strong>Never enable on production.</strong> Errors will be visible to all site visitors.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="script_debug">SCRIPT_DEBUG</label></th>
                            <td>
                                <label class="debugwp-toggle">
                                    <input type="hidden" name="wp_config[SCRIPT_DEBUG]" value="0">
                                    <input type="checkbox" name="wp_config[SCRIPT_DEBUG]" id="script_debug" value="1" <?php checked( ! empty( $wp_config['SCRIPT_DEBUG'] ) ); ?>>
                                    <span>Use unminified core JS/CSS files</span>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Plugin Debug Toggles -->
                <div class="debugwp-card">
                    <h2>Plugin Debug Mode</h2>
                    <p class="description">Enable debug logging for specific plugins. Captures HTTP requests, PHP errors, and Plugin native logs. Auto-disables after <strong>48 hours</strong> to protect live sites.</p>

                    <table class="form-table">
                        <?php foreach ( $plugins as $slug => $info ) :
                            $enabled    = $this->core->is_debug_enabled( $slug );
                            $enabled_at = get_option( "debugwp_{$slug}_enabled_at", 0 );
                            $remaining  = $enabled && $enabled_at ? max( 0, 48 - ( ( time() - $enabled_at ) / 3600 ) ) : 0;
                        ?>
                        <tr>
                            <th scope="row"><label for="plugin_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $info['label'] ); ?></label></th>
                            <td>
                                <label class="debugwp-toggle">
                                    <input type="checkbox" name="plugins_debug[<?php echo esc_attr( $slug ); ?>]" id="plugin_<?php echo esc_attr( $slug ); ?>" value="1" <?php checked( $enabled ); ?>>
                                    <span>Enable debug logging</span>
                                </label>
                                <?php if ( $enabled && $enabled_at ) : ?>
                                    <p class="description">
                                        Enabled since <strong><?php echo esc_html( wp_date( 'M j, Y g:i A', $enabled_at ) ); ?></strong>
                                        — auto-disables in <strong><?php echo esc_html( round( $remaining, 1 ) ); ?> hours</strong>
                                    </p>
                                    <p style="margin-top:6px;">
                                        <button type="submit" name="debugwp_reset_timer" value="<?php echo esc_attr( $slug ); ?>" class="button button-small">Reset Timer (48h)</button>
                                        <button type="submit" name="debugwp_extend_timer" value="<?php echo esc_attr( $slug ); ?>" class="button button-small">Extend +24h</button>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- Log Settings -->
                <div class="debugwp-card">
                    <h2>Log Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="max_entries">Maximum log entries</label></th>
                            <td>
                                <input type="number" name="settings[max_entries]" id="max_entries" value="<?php echo esc_attr( $settings['max_entries'] ); ?>" min="100" max="50000" class="small-text">
                                <p class="description">Oldest entries are pruned automatically when limit is reached.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="retention_days">Retention (days)</label></th>
                            <td>
                                <input type="number" name="settings[retention_days]" id="retention_days" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" min="1" max="90" class="small-text">
                                <p class="description">Logs older than this are deleted by the hourly cleanup.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Email Notifications -->
                <div class="debugwp-card">
                    <h2>Email Notifications</h2>
                    <p class="description">Receive email alerts when cron events are overdue or fail. Checked during the hourly DebugWP cleanup cron.</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="cron_email_enabled">Enable notifications</label></th>
                            <td>
                                <label class="debugwp-toggle">
                                    <input type="checkbox" name="settings[cron_email_enabled]" id="cron_email_enabled" value="1" <?php checked( ! empty( $settings['cron_email_enabled'] ) ); ?>>
                                    <span>Send email when cron events are overdue or fail</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cron_email_address">Notification email</label></th>
                            <td>
                                <input type="email" name="settings[cron_email_address]" id="cron_email_address" value="<?php echo esc_attr( $settings['cron_email_address'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                <p class="description">Leave blank to use the site admin email (<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>). Sent at most once per hour to avoid flooding.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }

    /* ── Log Viewer page (delegates to WP_List_Table) ──── */

    public function render_log_viewer_page() {
        $viewer = new DebugWP_Log_Viewer( $this->core );

        // Only prepare captured-logs items when NOT viewing a native tab.
        // WP_List_Table::set_pagination_args() redirects to paged=1 when the
        // requested page exceeds the captured-logs total — which breaks native
        // log pagination whose page count is independent.
        if ( empty( $_GET['native'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $viewer->prepare_items();
        }
        ?>
        <div class="wrap debugwp-wrap debugwp-wrap-full">
            <h1>DebugWP — Log Viewer</h1>
            <?php $viewer->render(); ?>
        </div>
        <?php
    }

    /* ── Save handler ────────────────────────────────────── */

    public function handle_save() {
        if ( ! isset( $_POST['debugwp_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['debugwp_nonce'] ) ), 'debugwp_save_settings' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        // Reset timer — restart 48h countdown.
        if ( ! empty( $_POST['debugwp_reset_timer'] ) ) {
            $reset_slug = sanitize_key( wp_unslash( $_POST['debugwp_reset_timer'] ) );
            if ( $this->core->is_debug_enabled( $reset_slug ) ) {
                update_option( "debugwp_{$reset_slug}_enabled_at", time() );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=debugwp&debugwp-updated=1' ) );
            exit;
        }

        // Extend timer — add 24 hours to the current enabled_at.
        if ( ! empty( $_POST['debugwp_extend_timer'] ) ) {
            $extend_slug = sanitize_key( wp_unslash( $_POST['debugwp_extend_timer'] ) );
            if ( $this->core->is_debug_enabled( $extend_slug ) ) {
                $current_at = (int) get_option( "debugwp_{$extend_slug}_enabled_at", time() );
                // Push enabled_at forward by 24h so the 48h window starts later.
                update_option( "debugwp_{$extend_slug}_enabled_at", $current_at + ( 24 * HOUR_IN_SECONDS ) );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=debugwp&debugwp-updated=1' ) );
            exit;
        }

        // Restore backup.
        if ( ! empty( $_POST['debugwp_restore_backup'] ) ) {
            $result = DebugWP_WP_Config::restore_backup();
            if ( is_wp_error( $result ) ) {
                add_settings_error( 'debugwp', 'restore_failed', $result->get_error_message(), 'error' );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=debugwp&debugwp-updated=1' ) );
            exit;
        }

        // Save WP Config constants.
        if ( isset( $_POST['wp_config'] ) && is_array( $_POST['wp_config'] ) ) {
            $posted_config = array_map( 'absint', wp_unslash( $_POST['wp_config'] ) );
        } else {
            $posted_config = [];
        }

        $config_constants = [ 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG' ];
        foreach ( $config_constants as $const ) {
            $new_val     = ! empty( $posted_config[ $const ] );
            $current_val = defined( $const ) ? constant( $const ) : false;

            if ( $new_val !== (bool) $current_val ) {
                $result = DebugWP_WP_Config::update( $const, $new_val );
                if ( is_wp_error( $result ) ) {
                    add_settings_error( 'debugwp', 'config_error', $const . ': ' . $result->get_error_message(), 'error' );
                }
            }
        }

        // Save plugin debug toggles.
        $posted_plugins = isset( $_POST['plugins_debug'] ) && is_array( $_POST['plugins_debug'] )
            ? array_map( 'absint', wp_unslash( $_POST['plugins_debug'] ) )
            : [];

        foreach ( array_keys( $this->core->get_supported_plugins() ) as $slug ) {
            $was_enabled = $this->core->is_debug_enabled( $slug );
            $now_enabled = ! empty( $posted_plugins[ $slug ] );

            if ( $now_enabled && ! $was_enabled ) {
                update_option( "debugwp_{$slug}_enabled", 1 );
                update_option( "debugwp_{$slug}_enabled_at", time() );
            } elseif ( ! $now_enabled && $was_enabled ) {
                delete_option( "debugwp_{$slug}_enabled" );
                delete_option( "debugwp_{$slug}_enabled_at" );
            }
        }

        // Save log settings.
        if ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) {
            $raw = wp_unslash( $_POST['settings'] );
            $settings = [
                'max_entries'        => max( 100, min( 50000, absint( $raw['max_entries'] ?? 5000 ) ) ),
                'retention_days'     => max( 1, min( 90, absint( $raw['retention_days'] ?? 7 ) ) ),
                'cron_email_enabled' => ! empty( $raw['cron_email_enabled'] ) ? 1 : 0,
                'cron_email_address' => isset( $raw['cron_email_address'] ) ? sanitize_email( $raw['cron_email_address'] ) : '',
            ];
            update_option( 'debugwp_settings', $settings );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=debugwp&debugwp-updated=1' ) );
        exit;
    }

    /* ── Admin notices ───────────────────────────────────── */

    public function admin_notices() {
        // Show auto-disabled notices.
        foreach ( array_keys( $this->core->get_supported_plugins() ) as $slug ) {
            if ( get_transient( "debugwp_auto_disabled_{$slug}" ) ) {
                $plugins = $this->core->get_supported_plugins();
                $label   = $plugins[ $slug ]['label'] ?? $slug;
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo '<strong>DebugWP:</strong> Debug mode for <strong>' . esc_html( $label ) . '</strong> was automatically disabled after 48 hours.';
                echo '</p></div>';
                delete_transient( "debugwp_auto_disabled_{$slug}" );
            }
        }

        // Show active debug warnings.
        $enabled = $this->core->get_enabled_slugs();
        if ( ! empty( $enabled ) ) {
            $plugins = $this->core->get_supported_plugins();
            $labels  = array_map( function ( $s ) use ( $plugins ) {
                return $plugins[ $s ]['label'] ?? $s;
            }, $enabled );

            echo '<div class="notice notice-info is-dismissible"><p>';
            echo '<strong>DebugWP:</strong> Debug logging is active for: <strong>' . esc_html( implode( ', ', $labels ) ) . '</strong>';
            echo ' — <a href="' . esc_url( admin_url( 'admin.php?page=debugwp-logs' ) ) . '">View Logs</a>';
            echo '</p></div>';
        }

        // Show overdue cron warnings on all DebugWP pages.
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'debugwp' ) !== false ) {
            $overdue = DebugWP_Cron_UI::get_overdue_events();
            if ( ! empty( $overdue ) ) {
                $count = count( $overdue );
                echo '<div class="notice notice-warning"><p>';
                printf(
                    '<strong>DebugWP:</strong> %d cron %s overdue (scheduled time passed without firing). This may indicate WP-Cron is not running reliably.',
                    $count,
                    $count === 1 ? 'event is' : 'events are'
                );
                echo ' <a href="' . esc_url( admin_url( 'admin.php?page=debugwp-cron' ) ) . '">View Cron Events</a>';
                echo '</p></div>';
            }
        }
    }
}
