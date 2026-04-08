<?php
/**
 * DebugWP Cron Logger — logs every time a supported-plugin cron event fires.
 *
 * Hooks into each supported plugin's known cron hooks and records them
 * in the DebugWP log table with log_type = 'cron'.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Cron_Logger {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;

        // Only register per-hook listeners during actual cron execution.
        if ( wp_doing_cron() ) {
            $this->register_listeners();
        }
    }

    /**
     * For every cron event in the WordPress cron array that belongs to a
     * supported plugin, register a low-priority action listener that logs
     * the event when it fires.
     */
    private function register_listeners() {
        $crons = _get_cron_array();
        if ( empty( $crons ) ) {
            return;
        }

        $registered = [];
        // Build hook patterns dynamically from registered providers.
        $patterns = $this->core->get_all_cron_hook_patterns();

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $instances ) {
                if ( isset( $registered[ $hook ] ) ) {
                    continue;
                }
                foreach ( $patterns as $slug => $prefixes ) {
                    $matched = false;
                    foreach ( $prefixes as $prefix ) {
                        if ( strpos( $hook, $prefix ) !== false ) {
                            $registered[ $hook ] = true;
                            $this->make_error_catcher( $hook, $slug );
                            add_action( $hook, $this->make_logger( $hook, $slug ), 9999, 10 );
                            $matched = true;
                            break;
                        }
                    }
                    if ( $matched ) {
                        break;
                    }
                }
            }
        }
    }

    /**
     * Return a closure that logs the cron event when it fires.
     * Wraps the event in a try/catch so exceptions are captured and logged.
     */
    private function make_logger( $hook, $slug ) {
        $core = $this->core;
        return function () use ( $hook, $slug, $core ) {
            $args = func_get_args();

            // Check if the hook is paused — if so, log that it was blocked.
            $paused = get_option( DebugWP_Cron_UI::PAUSED_OPTION, [] );
            if ( is_array( $paused ) && ! empty( $paused[ $hook ] ) ) {
                $core->insert_log(
                    $slug,
                    'cron',
                    'warning',
                    sprintf( '[Cron Blocked] %s — hook is paused', $hook ),
                    [ 'hook' => $hook, 'args' => $args ]
                );
                return;
            }

            $core->insert_log(
                $slug,
                'cron',
                'info',
                sprintf( '[Cron Fired] %s', $hook ),
                [ 'hook' => $hook, 'args' => $args ]
            );
        };
    }

    /**
     * Register an early-priority wrapper that catches exceptions thrown by
     * any callback attached to a supported cron hook.
     */
    private function make_error_catcher( $hook, $slug ) {
        $core = $this->core;
        // Priority 0 fires before most callbacks; the actual error catching
        // is done via set_error_handler around the entire hook execution.
        add_action( $hook, function () use ( $hook, $slug, $core ) {
            // Install a temporary error handler for this cron hook execution.
            set_error_handler( function ( $errno, $errstr, $errfile, $errline ) use ( $hook, $slug, $core ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
                $fatal_types = [ E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
                $severity    = in_array( $errno, $fatal_types, true ) ? 'error' : 'warning';
                $core->insert_log(
                    $slug,
                    'cron',
                    $severity,
                    sprintf( '[Cron Error] %s — %s in %s:%d', $hook, $errstr, $errfile, $errline ),
                    [ 'hook' => $hook, 'errno' => $errno, 'file' => $errfile, 'line' => $errline ]
                );
                return false; // Let PHP handle it normally too.
            } );

            // Restore the error handler after all callbacks for this hook have run.
            // We use shutdown as a safety net in case the hook triggers a fatal.
            register_shutdown_function( function () {
                restore_error_handler();
            } );
        }, -9999, 0 );

        // Also register a very-late listener to restore the error handler normally
        // (before shutdown, so it doesn't bleed into other hooks).
        add_action( $hook, function () {
            restore_error_handler();
        }, 99999, 0 );
    }
}
