<?php
/**
 * Plugin Name: DebugWP
 * Description: Centralized error logging and troubleshooting for MailOptin, CycleSave, FuseWP and ProfilePress. Captures HTTP requests, PHP errors, and native plugin logs in one unified viewer.
 * Version:     1.0.0
 * Author:      Ibrahim Nasir
 * License:     GPLv2 or later
 * Text Domain: debugwp
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DEBUGWP_VERSION', '1.0.0' );
define( 'DEBUGWP_FILE', __FILE__ );
define( 'DEBUGWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEBUGWP_URL', plugin_dir_url( __FILE__ ) );
define( 'DEBUGWP_BASENAME', plugin_basename( __FILE__ ) );

/* ── Autoloader ─────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    $map = [
        'DebugWP'              => 'includes/class-debugwp.php',
        'DebugWP_DB'           => 'includes/class-debugwp-db.php',
        'DebugWP_Settings'     => 'includes/class-debugwp-settings.php',
        'DebugWP_WP_Config'    => 'includes/class-debugwp-wp-config.php',
        'DebugWP_HTTP_Logger'  => 'includes/class-debugwp-http-logger.php',
        'DebugWP_PHP_Logger'   => 'includes/class-debugwp-php-logger.php',
        'DebugWP_Log_Viewer'   => 'includes/class-debugwp-log-viewer.php',
        'DebugWP_Ajax'         => 'includes/class-debugwp-ajax.php',
        'DebugWP_Cron'         => 'includes/class-debugwp-cron.php',
        'DebugWP_Reader_MailOptin'    => 'includes/readers/class-debugwp-reader-mailoptin.php',
        'DebugWP_Reader_CycleSave'    => 'includes/readers/class-debugwp-reader-cyclesave.php',
        'DebugWP_Reader_ProfilePress' => 'includes/readers/class-debugwp-reader-profilepress.php',
        'DebugWP_Reader_Debug_Log'    => 'includes/readers/class-debugwp-reader-debug-log.php',
        'DebugWP_Reader_FuseWP'       => 'includes/readers/class-debugwp-reader-fusewp.php',
        'DebugWP_ProfilePress_Logger' => 'includes/class-debugwp-profilepress-logger.php',
        'DebugWP_Stripe_HTTP_Client'  => 'includes/class-debugwp-stripe-http-client.php',
        'DebugWP_FuseWP_Logger'       => 'includes/class-debugwp-fusewp-logger.php',
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

    // Map file path to a supported plugin slug.
    $plugin_slug  = null;
    $plugin_paths = [
        'profilepress' => [ 'plugins/wp-user-avatar/', 'plugins/profilepress-pro/' ],
        'cyclesave'    => [ 'plugins/cyclesave/', 'plugins/cyclesave-pro/' ],
        'mailoptin'    => [ 'plugins/mailoptin/' ],
        'fusewp'       => [ 'plugins/fusewp/', 'plugins/fusewp-pro/' ],
    ];
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
} );
