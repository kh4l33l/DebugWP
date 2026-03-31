<?php
/**
 * HTTP Request Logger — hooks into WordPress HTTP API to capture all outgoing requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_HTTP_Logger {

    /** @var DebugWP */
    private $core;

    /** Keys stripped from request/response bodies to prevent leaking secrets. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'secret', 'api_key', 'apikey', 'api_secret',
        'token', 'access_token', 'refresh_token', 'client_secret', 'license',
        'license_key', 'private_key', 'authorization', 'credit_card', 'card_number',
        'cvv', 'cvc', 'ssn',
    ];

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_action( 'http_api_debug', [ $this, 'on_http_debug' ], 10, 5 );
    }

    /**
     * Fired after every wp_remote_* call.
     *
     * @param array|WP_Error $response    Response or error.
     * @param string         $context     'response' on success.
     * @param string         $class       Transport class used.
     * @param array          $parsed_args Request arguments.
     * @param string         $url         Request URL.
     */
    public function on_http_debug( $response, $context, $class, $parsed_args, $url ) {
        // Walk the backtrace to find which plugin made this call.
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
        $slug  = $this->resolve_slug_from_trace( $trace );

        if ( ! $slug ) {
            return; // Not from a supported plugin.
        }

        if ( ! $this->core->is_debug_enabled( $slug ) ) {
            return; // Debug not enabled for this plugin.
        }

        // Determine severity.
        $is_error    = is_wp_error( $response );
        $status_code = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $severity    = 'info';

        if ( $is_error ) {
            $severity = 'error';
        } elseif ( $status_code >= 500 ) {
            $severity = 'error';
        } elseif ( $status_code >= 400 ) {
            $severity = 'warning';
        }

        // Build message.
        $method = strtoupper( $parsed_args['method'] ?? 'GET' );
        if ( $is_error ) {
            $message = sprintf( '%s %s — WP_Error: %s', $method, $url, $response->get_error_message() );
        } else {
            $message = sprintf( '%s %s — HTTP %d', $method, $url, $status_code );
        }

        // Build context.
        $ctx = [
            'url'          => $url,
            'method'       => $method,
            'status_code'  => $status_code,
            'request_body' => $this->sanitize_body( $parsed_args['body'] ?? '' ),
        ];

        if ( $is_error ) {
            $ctx['error_code']    = $response->get_error_code();
            $ctx['error_message'] = $response->get_error_message();
        } else {
            $body = wp_remote_retrieve_body( $response );
            $ctx['response_body'] = mb_substr( $body, 0, 5000 );

            // Detect API-level errors in 200 responses (e.g. EDD license, Stripe, gateway errors).
            $api_error = $this->detect_api_error( $body, $url );
            if ( $api_error ) {
                $severity = 'warning';
                $message .= ' — ' . $api_error;
                $ctx['api_error'] = $api_error;
            }

            // Decode HTML error pages (Cloudflare, WAF, nginx, Apache, etc.).
            $html_decode = $this->decode_html_response( $body, $status_code );
            if ( $html_decode ) {
                $ctx['html_summary'] = $html_decode['summary'];
                $ctx['html_body']    = $html_decode['text'];

                // Surface Cloudflare / WAF identifiers as top-level context fields.
                if ( ! empty( $html_decode['ray_id'] ) ) {
                    $ctx['cf_ray_id'] = $html_decode['ray_id'];
                }
                if ( ! empty( $html_decode['cf_zone'] ) ) {
                    $ctx['cf_zone'] = $html_decode['cf_zone'];
                }

                // Elevate message with the summary if no API error already set.
                if ( ! $api_error ) {
                    $message .= ' — ' . $html_decode['summary'];
                }

                // Always append Ray ID to the message for quick scanning in the log list.
                if ( ! empty( $html_decode['ray_id'] ) && strpos( $message, $html_decode['ray_id'] ) === false ) {
                    $message .= ' [Ray: ' . $html_decode['ray_id'] . ']';
                }
            }
        }

        // Compact backtrace for context.
        $ctx['backtrace'] = $this->compact_trace( $trace, $slug );

        $this->core->insert_log( $slug, 'http_request', $severity, $message, $ctx );
    }

    /**
     * Walk backtrace to find a supported plugin slug.
     */
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

    /**
     * Reduce backtrace to relevant frames (file + line only, plugin-relative paths).
     */
    private function compact_trace( array $trace, $slug ) {
        $compact = [];
        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }
            // Only include frames from the originating plugin.
            if ( $this->core->resolve_plugin_slug( $frame['file'] ) === $slug ) {
                $compact[] = str_replace( WP_PLUGIN_DIR . '/', '', $frame['file'] ) . ':' . ( $frame['line'] ?? '?' );
            }
            if ( count( $compact ) >= 8 ) {
                break;
            }
        }
        return $compact;
    }

    /**
     * Detect API-level errors inside HTTP 200 responses.
     *
     * Covers: EDD license responses (MailOptin, ProfilePress, CycleSave),
     * Stripe error objects, and generic { success: false } patterns.
     *
     * @param string $body Raw response body.
     * @param string $url  Request URL.
     * @return string|null Human-readable error, or null if response looks successful.
     */
    private function detect_api_error( $body, $url ) {
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return null;
        }

        // EDD Software Licensing responses: { success: false, error: "expired" }
        if ( isset( $data['success'] ) && false === $data['success'] && ! empty( $data['error'] ) ) {
            $edd_labels = [
                'expired'              => 'License key expired',
                'revoked'              => 'License key disabled/revoked',
                'missing'              => 'Invalid license key',
                'invalid'              => 'License not active for this URL',
                'site_inactive'        => 'License not active for this URL',
                'item_name_mismatch'   => 'License key is for a different product',
                'no_activations_left'  => 'License activation limit reached',
                'disabled'             => 'License key disabled',
                'key_mismatch'         => 'License key mismatch',
            ];

            $code  = $data['error'];
            $label = $edd_labels[ $code ] ?? "License error: {$code}";

            if ( ! empty( $data['expires'] ) ) {
                $label .= ' (expires: ' . $data['expires'] . ')';
            }

            return $label;
        }

        // EDD license check: { license: "expired" } (no success key, but license status).
        if ( isset( $data['license'] ) && in_array( $data['license'], [ 'expired', 'disabled', 'revoked', 'invalid', 'site_inactive', 'key_mismatch' ], true ) ) {
            return 'License status: ' . $data['license'];
        }

        // Stripe error object: { error: { type: "...", message: "..." } }
        if ( isset( $data['error']['message'] ) ) {
            $type = $data['error']['type'] ?? 'api_error';
            return "Stripe {$type}: " . mb_substr( $data['error']['message'], 0, 200 );
        }

        // Generic { success: false, message: "..." } pattern.
        if ( isset( $data['success'] ) && false === $data['success'] && ! empty( $data['message'] ) ) {
            return mb_substr( $data['message'], 0, 200 );
        }

        return null;
    }

    /**
     * Decode HTML error responses into a human-readable summary.
     *
     * Detects: Cloudflare challenges/blocks, AWS WAF, Sucuri, Wordfence,
     * nginx/Apache default error pages, and generic HTML errors.
     *
     * @param string $body        Raw response body.
     * @param int    $status_code HTTP status code.
     * @return array|null { summary: string, text: string } or null if not HTML.
     */
    private function decode_html_response( $body, $status_code ) {
        // Only process HTML responses.
        if ( empty( $body ) || strpos( $body, '<' ) === false ) {
            return null;
        }

        // Quick check: does it look like HTML?
        $lower = strtolower( mb_substr( $body, 0, 500 ) );
        if ( strpos( $lower, '<html' ) === false && strpos( $lower, '<!doctype' ) === false ) {
            return null;
        }

        // Extract <title>.
        $title = '';
        if ( preg_match( '/<title[^>]*>([^<]+)/i', $body, $m ) ) {
            $title = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }

        // Extract visible text for readable display.
        $text = $this->html_to_text( $body );

        // --- Cloudflare ---
        if ( strpos( $body, '_cf_chl_opt' ) !== false || strpos( $body, 'cf-challenge' ) !== false || strpos( $body, 'challenge-platform' ) !== false ) {
            $ray = '';
            if ( preg_match( '/cRay:\s*[\'"]([a-f0-9]+)/i', $body, $rm ) ) {
                $ray = $rm[1];
            }
            $zone = '';
            if ( preg_match( '/cZone:\s*[\'"]([^\'"]+)/i', $body, $zm ) ) {
                $zone = $zm[1];
            }

            $summary = 'Cloudflare challenge/block';
            if ( $status_code === 403 ) {
                $summary = 'Cloudflare blocked request (403 Forbidden)';
            } elseif ( $status_code === 503 ) {
                $summary = 'Cloudflare challenge page (503)';
            }
            $summary .= $zone ? " — zone: {$zone}" : '';
            $summary .= $ray ? " — Ray ID: {$ray}" : '';
            $summary .= '. The server\'s IP may need to be whitelisted in Cloudflare, or the request needs a valid browser User-Agent.';

            return [
                'summary' => $summary,
                'text'    => $text,
                'ray_id'  => $ray,
                'cf_zone' => $zone,
            ];
        }

        // --- Cloudflare generic errors (52x, "Cloudflare" in body) ---
        if ( strpos( $body, 'cloudflare' ) !== false || strpos( $lower, 'cloudflare' ) !== false ) {
            // Also try to extract Ray ID from generic CF error pages.
            $generic_ray = '';
            if ( preg_match( '/ray[\s\-_]*(?:id)?[:\s]*([a-f0-9]{16})/i', $body, $gr ) ) {
                $generic_ray = $gr[1];
            }

            if ( $status_code >= 520 && $status_code <= 530 ) {
                $cf_errors = [
                    520 => 'Web server returned an unknown error',
                    521 => 'Web server is down',
                    522 => 'Connection timed out to origin',
                    523 => 'Origin is unreachable',
                    524 => 'Origin server took too long to respond',
                    525 => 'SSL handshake failed',
                    526 => 'Invalid SSL certificate on origin',
                    527 => 'Railgun connection error',
                    530 => 'Origin DNS error',
                ];
                $label = $cf_errors[ $status_code ] ?? "Cloudflare error {$status_code}";
                return [
                    'summary' => "Cloudflare: {$label} (HTTP {$status_code})",
                    'text'    => $text,
                    'ray_id'  => $generic_ray,
                    'cf_zone' => '',
                ];
            }

            return [
                'summary' => "Cloudflare page returned (HTTP {$status_code}): {$title}",
                'text'    => $text,
                'ray_id'  => $generic_ray,
                'cf_zone' => '',
            ];
        }

        // --- Sucuri WAF ---
        if ( strpos( $body, 'Sucuri' ) !== false || strpos( $body, 'sucuri' ) !== false ) {
            return [ 'summary' => "Sucuri WAF block (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        // --- Wordfence ---
        if ( strpos( $body, 'wordfence' ) !== false || strpos( $body, 'Wordfence' ) !== false ) {
            return [ 'summary' => "Wordfence firewall block (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        // --- AWS WAF ---
        if ( strpos( $body, 'aws' ) !== false && strpos( $body, 'waf' ) !== false ) {
            return [ 'summary' => "AWS WAF block (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        // --- ModSecurity ---
        if ( strpos( $lower, 'mod_security' ) !== false || strpos( $lower, 'modsecurity' ) !== false ) {
            return [ 'summary' => "ModSecurity block (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        // --- Nginx / Apache default error pages ---
        if ( preg_match( '/<center>\s*(?:nginx|apache)/i', $body ) ) {
            return [ 'summary' => "Server error page (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        // --- Generic: any non-2xx HTML response gets a summary with the title ---
        if ( $status_code >= 400 && ! empty( $title ) ) {
            return [ 'summary' => "HTML error (HTTP {$status_code}): {$title}", 'text' => $text ];
        }

        return null;
    }

    /**
     * Convert HTML to readable plain text.
     */
    private function html_to_text( $html ) {
        // Remove script and style blocks.
        $clean = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/si', '', $html );
        // Convert <br>, <p>, <div>, <li>, <tr> to newlines.
        $clean = preg_replace( '/<(br|p|div|li|tr|h[1-6])[^>]*>/i', "\n", $clean );
        // Strip remaining tags.
        $clean = wp_strip_all_tags( $clean );
        // Normalize whitespace.
        $clean = preg_replace( '/[ \t]+/', ' ', $clean );
        $clean = preg_replace( '/\n{3,}/', "\n\n", $clean );
        $clean = trim( $clean );
        // Truncate.
        return mb_substr( $clean, 0, 2000 );
    }

    /**
     * Strip sensitive keys from request body data.
     *
     * @param mixed $body String or array.
     * @return mixed Sanitized body.
     */
    private function sanitize_body( $body ) {
        if ( empty( $body ) ) {
            return '';
        }

        if ( is_string( $body ) ) {
            // Try to parse as JSON.
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) ) {
                return wp_json_encode( $this->strip_sensitive( $decoded ) );
            }
            // Try to parse as URL-encoded.
            parse_str( $body, $parsed );
            if ( ! empty( $parsed ) && count( $parsed ) > 1 ) {
                return http_build_query( $this->strip_sensitive( $parsed ) );
            }
            return mb_substr( $body, 0, 2000 );
        }

        if ( is_array( $body ) ) {
            return $this->strip_sensitive( $body );
        }

        return '';
    }

    private function strip_sensitive( array $data ) {
        foreach ( $data as $key => $value ) {
            $lower = strtolower( (string) $key );
            foreach ( self::SENSITIVE_KEYS as $sensitive ) {
                if ( strpos( $lower, $sensitive ) !== false ) {
                    $data[ $key ] = '***REDACTED***';
                    break;
                }
            }
            if ( is_array( $value ) && $data[ $key ] !== '***REDACTED***' ) {
                $data[ $key ] = $this->strip_sensitive( $value );
            }
        }
        return $data;
    }
}
