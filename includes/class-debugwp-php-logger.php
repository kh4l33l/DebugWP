<?php
/**
 * PHP Error Logger — captures PHP errors from enabled plugin directories.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_PHP_Logger {

    /** @var DebugWP */
    private $core;

    /** @var callable|null Previous error handler. */
    private $previous_handler;

    /** @var array Enabled plugin directory paths (resolved once). */
    private $watched_dirs = [];

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        $this->build_watched_dirs();

        if ( empty( $this->watched_dirs ) ) {
            // Plugin directories could not be resolved — handler not registered.
            // This usually means the plugin paths in register_supported_plugins() don't match
            // the actual directories on disk. Check WP_PLUGIN_DIR and the path fragments.
            error_log( '[DebugWP] Warning: No watched plugin directories found. PHP error handler not registered.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            return;
        }

        $this->previous_handler = set_error_handler( [ $this, 'handle_error' ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
    }

    private function build_watched_dirs() {
        // Watch ALL supported plugin dirs (not just enabled ones) so the handler is always
        // registered. The is_debug_enabled() check in handle_error() controls whether errors
        // are actually written to the DB.
        foreach ( $this->core->get_supported_plugins() as $slug => $info ) {
            foreach ( $info['paths'] as $rel ) {
                $abs = WP_PLUGIN_DIR . '/' . trim( str_replace( 'plugins/', '', $rel ), '/' ) . '/';
                if ( is_dir( $abs ) ) {
                    $this->watched_dirs[ $abs ] = $slug;
                }
            }
        }
    }

    /**
     * Custom error handler. Only logs errors originating from watched plugin dirs.
     *
     * @return bool Always false — lets WordPress / PHP handle the error normally.
     */
    public function handle_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
        // Check if this error comes from a watched plugin.
        $slug = null;
        foreach ( $this->watched_dirs as $dir => $dir_slug ) {
            if ( strpos( $errfile, $dir ) === 0 ) {
                $slug = $dir_slug;
                break;
            }
        }

        if ( $slug ) {
            // Log the error if debug is enabled for this plugin.
            // Even when debug is disabled, the error is still passed to the previous handler
            // below so WordPress / PHP can handle it normally.
            if ( $this->core->is_debug_enabled( $slug ) ) {
                $severity = $this->map_severity( $errno );
                $relative = str_replace( WP_PLUGIN_DIR . '/', '', $errfile );
                $message  = sprintf( '[%s] %s in %s:%d', $this->error_label( $errno ), $errstr, $relative, $errline );

                $this->core->insert_log( $slug, 'php_error', $severity, $message, [
                    'errno'   => $errno,
                    'file'    => $relative,
                    'line'    => $errline,
                    'errstr'  => $errstr,
                ] );
            }
        }

        // Call previous handler if it exists, otherwise return false to let PHP handle it.
        if ( is_callable( $this->previous_handler ) ) {
            return call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
        }

        return false;
    }

    private function map_severity( $errno ) {
        switch ( $errno ) {
            // Note: E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE are true PHP fatal errors.
            // They terminate the script immediately and never reach set_error_handler().
            // They are captured instead by the register_shutdown_function() in debugwp.php.
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                return 'error';
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'warning';
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_STRICT:
                return 'info';
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'debug';
            default:
                return 'info';
        }
    }

    private function error_label( $errno ) {
        $map = [
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_NOTICE            => 'E_NOTICE',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            E_STRICT            => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        ];
        return $map[ $errno ] ?? "E_UNKNOWN({$errno})";
    }
}
