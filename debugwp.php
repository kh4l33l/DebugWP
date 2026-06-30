<?php
/**
 * Plugin Name: DebugWP
 * Description: Extensible centralized error logging and troubleshooting for WordPress plugins. Ships with built-in support for MailOptin, CycleSave, FuseWP and ProfilePress. Third-party plugins can register their own providers via the debugwp_register_providers action.
 * Version:     1.0.3
 * Author:      Ibrahim Nasir
 * License:     GPLv2 or later
 * Text Domain: debugwp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DEBUGWP_VERSION', '1.0.3' );
define( 'DEBUGWP_FILE', __FILE__ );
define( 'DEBUGWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEBUGWP_URL', plugin_dir_url( __FILE__ ) );
define( 'DEBUGWP_BASENAME', plugin_basename( __FILE__ ) );

/* ── Autoloader ─────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    $map = [
        // Contracts.
        'DebugWP_Plugin_Provider'      => 'includes/contracts/interface-debugwp-plugin-provider.php',
        'DebugWP_Plugin_Provider_Base' => 'includes/contracts/class-debugwp-plugin-provider-base.php',

        // Core.
        'DebugWP'                   => 'includes/class-debugwp.php',
        'DebugWP_DB'                => 'includes/class-debugwp-db.php',
        'DebugWP_Settings'          => 'includes/class-debugwp-settings.php',
        'DebugWP_WP_Config'         => 'includes/class-debugwp-wp-config.php',
        'DebugWP_HTTP_Logger'       => 'includes/class-debugwp-http-logger.php',
        'DebugWP_Mail_Logger'       => 'includes/class-debugwp-mail-logger.php',
        'DebugWP_Webhook_Logger'    => 'includes/class-debugwp-webhook-logger.php',
        'DebugWP_PHP_Logger'        => 'includes/class-debugwp-php-logger.php',
        'DebugWP_Log_Viewer'        => 'includes/class-debugwp-log-viewer.php',
        'DebugWP_Ajax'              => 'includes/class-debugwp-ajax.php',
        'DebugWP_Cron'              => 'includes/class-debugwp-cron.php',
        'DebugWP_Cron_UI'           => 'includes/class-debugwp-cron-ui.php',
        'DebugWP_Cron_Logger'       => 'includes/class-debugwp-cron-logger.php',
        'DebugWP_Dashboard_Widget'  => 'includes/class-debugwp-dashboard-widget.php',
        'DebugWP_Site_Health'       => 'includes/class-debugwp-site-health.php',
        'DebugWP_Environment'       => 'includes/class-debugwp-environment.php',
        'DebugWP_CLI'               => 'includes/class-debugwp-cli.php',

        // Core reader (reads WP debug.log for all providers).
        'DebugWP_Reader_Debug_Log' => 'includes/readers/class-debugwp-reader-debug-log.php',

        // Built-in providers (auto-discovered, but listed here for autoloading).
        'DebugWP_Provider_MailOptin'    => 'includes/providers/mailoptin/class-mailoptin-provider.php',
        'DebugWP_Provider_CycleSave'    => 'includes/providers/cyclesave/class-cyclesave-provider.php',
        'DebugWP_Provider_ProfilePress' => 'includes/providers/profilepress/class-profilepress-provider.php',
        'DebugWP_Provider_FuseWP'       => 'includes/providers/fusewp/class-fusewp-provider.php',
        'DebugWP_Provider_CrawlWP'      => 'includes/providers/crawlwp/class-crawlwp-provider.php',

        // Provider readers.
        'DebugWP_Reader_MailOptin'    => 'includes/providers/mailoptin/class-reader.php',
        'DebugWP_Reader_CycleSave'    => 'includes/providers/cyclesave/class-reader.php',
        'DebugWP_Reader_ProfilePress' => 'includes/providers/profilepress/class-reader.php',
        'DebugWP_Reader_FuseWP'       => 'includes/providers/fusewp/class-reader.php',
        'DebugWP_Reader_CrawlWP'      => 'includes/providers/crawlwp/class-reader.php',

        // Provider loggers.
        'DebugWP_ProfilePress_Logger' => 'includes/providers/profilepress/class-logger.php',
        'DebugWP_Stripe_HTTP_Client'  => 'includes/providers/profilepress/class-stripe-http-client.php',
        'DebugWP_FuseWP_Logger'       => 'includes/providers/fusewp/class-logger.php',
        'DebugWP_CrawlWP_Logger'      => 'includes/providers/crawlwp/class-logger.php',
    ];

    if ( isset( $map[ $class ] ) ) {
        require_once DEBUGWP_DIR . $map[ $class ];
    }
} );

/* ── Activation / Deactivation ──────────────────────────── */
register_activation_hook( __FILE__, [ 'DebugWP_DB', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'DebugWP_Cron', 'deactivate' ] );

/* ── Fatal Error Shutdown Handler ───────────────────────── */
// Must be registered here (not inside plugins_loaded) so it catches fatals from any plugin
// at any load phase. set_error_handler() cannot catch true PHP fatals; this is the only way.
register_shutdown_function( function () {
    $error = error_get_last();
    if ( ! $error ) {
        return;
    }

    $fatal_types = [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR ];
    if ( ! in_array( $error['type'], $fatal_types, true ) ) {
        return;
    }

    // Map file path to a supported plugin slug via provider registry.
    $plugin_slug  = null;
    $plugin_paths = class_exists( 'DebugWP' ) ? DebugWP::get_all_paths() : [];
    foreach ( $plugin_paths as $slug => $paths ) {
        foreach ( $paths as $path ) {
            if ( strpos( $error['file'], $path ) !== false ) {
                $plugin_slug = $slug;
                break 2;
            }
        }
    }

    // Always write to the PHP error log as a fallback (DB may not be available at shutdown).
    error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        '[DebugWP] Fatal error%s: %s in %s on line %d',
        $plugin_slug ? " ({$plugin_slug})" : '',
        $error['message'],
        $error['file'],
        $error['line']
    ) );

    // Attempt a DB insert if WordPress is sufficiently loaded and the error belongs to a watched plugin.
    if ( $plugin_slug && function_exists( 'get_option' ) && class_exists( 'DebugWP' ) ) {
        DebugWP::get_instance()->insert_log(
            $plugin_slug,
            'php_error',
            'error',
            sprintf( '[E_FATAL] %s in %s:%d', $error['message'], $error['file'], $error['line'] ),
            [ 'errno' => $error['type'], 'file' => $error['file'], 'line' => $error['line'] ]
        );
    }
} );

/* ── Boot ────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    DebugWP::get_instance();

    // Run DB migrations if needed (without requiring reactivation).
    DebugWP_DB::maybe_upgrade();
} );

/* ── WP-CLI ─────────────────────────────────────────────── */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'debugwp', 'DebugWP_CLI' );
}
