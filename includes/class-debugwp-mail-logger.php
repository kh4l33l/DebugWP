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

        $to         = $this->format_recipients( $args['to'] ?? '' );
        $email_body = $this->normalize_email_body( $args['message'] ?? '' );

        $this->core->insert_log(
            $slug,
            'email',
            'info',
            sprintf( '[Email] To: %s — Subject: %s', $to, $args['subject'] ?? '(no subject)' ),
            [
                'to'          => $to,
                'subject'     => $args['subject'] ?? '',
                'email_body'  => mb_substr( $email_body, 0, 50000 ),
                'body_format' => $this->detect_body_format( $email_body, $args['headers'] ?? '' ),
                'headers'     => $args['headers'] ?? '',
                'attachments' => $this->format_attachments( $args['attachments'] ?? [] ),
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

        $data       = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
        $to         = $this->format_recipients( $data['to'] ?? '' );
        $email_body = $this->normalize_email_body( $data['message'] ?? '' );

        $this->core->insert_log(
            $slug,
            'email',
            'error',
            sprintf( '[Email Failed] %s — To: %s', $error->get_error_message(), $to ?: 'unknown' ),
            [
                'error_code'  => $error->get_error_code(),
                'to'          => $to,
                'subject'     => $data['subject'] ?? '',
                'email_body'  => mb_substr( $email_body, 0, 50000 ),
                'body_format' => $this->detect_body_format( $email_body, $data['headers'] ?? '' ),
                'headers'     => $data['headers'] ?? '',
                'attachments' => $this->format_attachments( $data['attachments'] ?? [] ),
                'backtrace'   => $this->compact_trace( $trace, $slug ),
            ]
        );
    }

    private function format_recipients( $recipients ) {
        if ( empty( $recipients ) ) {
            return '';
        }

        return is_array( $recipients ) ? implode( ', ', $recipients ) : (string) $recipients;
    }

    private function normalize_email_body( $body ) {
        if ( is_scalar( $body ) || null === $body ) {
            return (string) $body;
        }

        return wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
    }

    private function detect_body_format( $body, $headers ) {
        $body    = $this->normalize_email_body( $body );
        $headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

        if ( preg_match( '/content-type:\s*text\/html/i', $headers ) || preg_match( '/<[a-z][\s\S]*>/i', $body ) ) {
            return 'html';
        }

        return 'text';
    }

    private function format_attachments( $attachments ) {
        if ( empty( $attachments ) ) {
            return 'none';
        }

        return ( is_array( $attachments ) ? count( $attachments ) : 1 ) . ' file(s)';
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
