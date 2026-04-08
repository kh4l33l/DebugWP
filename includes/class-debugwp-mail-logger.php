<?php
/**
 * Mail Logger — captures outgoing wp_mail() calls from supported plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Mail_Logger {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_filter( 'wp_mail', [ $this, 'on_wp_mail' ], 999 );
        add_action( 'wp_mail_failed', [ $this, 'on_wp_mail_failed' ] );
    }

    /**
     * Capture outgoing emails. We use wp_mail filter so we can inspect the
     * data before it's sent. We return $args unmodified — this is read-only.
     *
     * @param array $args { to, subject, message, headers, attachments }
     * @return array Unmodified.
     */
    public function on_wp_mail( $args ) {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $slug  = $this->resolve_slug_from_trace( $trace );

        if ( ! $slug || ! $this->core->is_debug_enabled( $slug ) ) {
            return $args;
        }

        $to = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];

        $this->core->insert_log(
            $slug,
            'email',
            'info',
            sprintf( '[Email] To: %s — Subject: %s', $to, $args['subject'] ?? '(no subject)' ),
            [
                'to'          => $to,
                'subject'     => $args['subject'] ?? '',
                'headers'     => $args['headers'] ?? '',
                'attachments' => ! empty( $args['attachments'] ) ? count( $args['attachments'] ) . ' file(s)' : 'none',
                'backtrace'   => $this->compact_trace( $trace, $slug ),
            ]
        );

        return $args;
    }

    /**
     * Log email delivery failures.
     *
     * @param WP_Error $error
     */
    public function on_wp_mail_failed( $error ) {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $slug  = $this->resolve_slug_from_trace( $trace );

        if ( ! $slug || ! $this->core->is_debug_enabled( $slug ) ) {
            return;
        }

        $data = $error->get_error_data();

        $this->core->insert_log(
            $slug,
            'email',
            'error',
            sprintf( '[Email Failed] %s — To: %s', $error->get_error_message(), $data['to'][0] ?? 'unknown' ),
            [
                'error_code' => $error->get_error_code(),
                'to'         => $data['to'] ?? [],
                'subject'    => $data['subject'] ?? '',
                'backtrace'  => $this->compact_trace( $trace, $slug ),
            ]
        );
    }

    private function resolve_slug_from_trace( array $trace ) {
        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }
            $slug = $this->core->resolve_plugin_slug( $frame['file'] );
            if ( $slug ) {
                return $slug;
            }
        }
        return null;
    }

    private function compact_trace( array $trace, $slug ) {
        $compact = [];
        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }
            if ( $this->core->resolve_plugin_slug( $frame['file'] ) === $slug ) {
                $compact[] = str_replace( WP_PLUGIN_DIR . '/', '', $frame['file'] ) . ':' . ( $frame['line'] ?? '?' );
            }
            if ( count( $compact ) >= 8 ) {
                break;
            }
        }
        return $compact;
    }
}
