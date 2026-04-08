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
        // Use the same hook patterns as the Cron UI for consistency.
        $patterns = [
            'mailoptin'    => [ 'mailoptin', 'mo_' ],
            'cyclesave'    => [ 'cyclesave' ],
            'profilepress' => [ 'profilepress', 'ppress' ],
            'fusewp'       => [ 'fusewp' ],
            'debugwp'      => [ 'debugwp' ],
        ];

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
}
