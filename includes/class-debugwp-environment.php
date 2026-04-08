<?php
/**
 * Environment Info — gathers server/PHP/WP/plugin diagnostics.
 * Used by both the admin page and WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Environment {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
    }

    /**
     * Get all environment info as a structured array.
     *
     * @return array section => [ label => value ]
     */
    public static function get_info() {
        global $wpdb;

        $info = [];

        // WordPress.
        $info['WordPress'] = [
            'Version'         => get_bloginfo( 'version' ),
            'Site URL'        => site_url(),
            'Home URL'        => home_url(),
            'Multisite'       => is_multisite() ? 'Yes' : 'No',
            'Language'        => get_locale(),
            'Permalink Struct' => get_option( 'permalink_structure' ) ?: 'Plain',
            'ABSPATH'         => ABSPATH,
            'WP_DEBUG'        => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'On' : 'Off',
            'WP_DEBUG_LOG'    => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'On' : 'Off',
            'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? 'On' : 'Off',
            'SCRIPT_DEBUG'    => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? 'On' : 'Off',
            'WP_CRON'         => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ? 'Disabled' : 'Enabled',
            'ALTERNATE_WP_CRON' => ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) ? 'Yes' : 'No',
        ];

        // PHP.
        $info['PHP'] = [
            'Version'          => phpversion(),
            'SAPI'             => php_sapi_name(),
            'Memory Limit'     => ini_get( 'memory_limit' ),
            'Max Execution'    => ini_get( 'max_execution_time' ) . 's',
            'Upload Max'       => ini_get( 'upload_max_filesize' ),
            'Post Max'         => ini_get( 'post_max_size' ),
            'Display Errors'   => ini_get( 'display_errors' ) ? 'On' : 'Off',
            'Error Reporting'  => self::error_reporting_label(),
            'Loaded Extensions' => implode( ', ', get_loaded_extensions() ),
        ];

        // Server.
        $info['Server'] = [
            'Software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'OS'         => PHP_OS . ' ' . php_uname( 'r' ),
            'Hostname'   => gethostname() ?: 'Unknown',
        ];

        // Database.
        $info['Database'] = [
            'Server'        => $wpdb->db_server_info(),
            'Client'        => $wpdb->db_version(),
            'Table Prefix'  => $wpdb->prefix,
            'DebugWP Logs'  => self::get_log_table_stats(),
        ];

        // Mail.
        $info['Mail'] = [
            'Transport' => self::get_mail_transport(),
            'From Email' => apply_filters( 'wp_mail_from', get_option( 'admin_email' ) ),
        ];

        // Active Plugins.
        $info['Active Plugins'] = self::get_active_plugins();

        // Active Theme.
        $theme = wp_get_theme();
        $info['Theme'] = [
            'Name'    => $theme->get( 'Name' ),
            'Version' => $theme->get( 'Version' ),
            'Author'  => $theme->get( 'Author' ),
            'Parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : 'None',
        ];

        return $info;
    }

    private static function error_reporting_label() {
        $level = error_reporting();
        if ( $level === E_ALL ) {
            return 'E_ALL';
        }
        if ( $level === ( E_ALL & ~E_DEPRECATED & ~E_STRICT ) ) {
            return 'E_ALL & ~E_DEPRECATED & ~E_STRICT';
        }
        return (string) $level;
    }

    private static function get_log_table_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return number_format_i18n( $count ) . ' entries';
    }

    private static function get_mail_transport() {
        if ( defined( 'WPMS_MAILER' ) ) {
            return 'WP Mail SMTP (' . WPMS_MAILER . ')';
        }
        if ( class_exists( 'FluentMail\\App\\Services\\Mailer\\Mailer' ) ) {
            return 'FluentSMTP';
        }
        // Check if PHPMailer is overridden.
        global $phpmailer;
        if ( $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer && $phpmailer->Mailer !== 'mail' ) {
            return 'PHPMailer (' . $phpmailer->Mailer . ')';
        }
        return 'PHP mail()';
    }

    private static function get_active_plugins() {
        $active  = get_option( 'active_plugins', [] );
        $plugins = [];
        foreach ( $active as $plugin_file ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
            $name = $data['Name'] ?: basename( dirname( $plugin_file ) );
            $plugins[ $name ] = $data['Version'] ?: 'Unknown';
        }
        ksort( $plugins );
        return $plugins;
    }

    /**
     * Render the environment info as an admin page.
     */
    public function render_page() {
        $info = self::get_info();
        ?>
        <div class="wrap debugwp-wrap">
            <h1>DebugWP — Environment</h1>
            <p>System information useful for debugging. Click "Copy to Clipboard" to share with support.</p>

            <p>
                <button type="button" class="button" id="debugwp-copy-env">Copy to Clipboard</button>
            </p>

            <div id="debugwp-env-data">
            <?php foreach ( $info as $section => $items ) : ?>
                <div class="debugwp-card">
                    <h2><?php echo esc_html( $section ); ?></h2>
                    <table class="widefat striped">
                        <tbody>
                        <?php foreach ( $items as $label => $value ) : ?>
                            <tr>
                                <td style="width:200px;font-weight:600;"><?php echo esc_html( $label ); ?></td>
                                <td><?php echo esc_html( is_array( $value ) ? implode( ', ', $value ) : $value ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
            </div>

            <textarea id="debugwp-env-text" style="display:none;" readonly><?php
                foreach ( $info as $section => $items ) {
                    echo "### {$section}\n";
                    foreach ( $items as $label => $val ) {
                        $val_str = is_array( $val ) ? implode( ', ', $val ) : $val;
                        echo "{$label}: {$val_str}\n";
                    }
                    echo "\n";
                }
            ?></textarea>

            <script>
            document.getElementById('debugwp-copy-env')?.addEventListener('click', function() {
                var text = document.getElementById('debugwp-env-text');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text.value).then(function() {
                        alert('Environment info copied to clipboard!');
                    });
                } else {
                    text.style.display = 'block';
                    text.select();
                    document.execCommand('copy');
                    text.style.display = 'none';
                    alert('Environment info copied to clipboard!');
                }
            });
            </script>
        </div>
        <?php
    }
}
