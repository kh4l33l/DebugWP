<?php
/**
 * Incoming Webhook Logger — captures REST API and admin-post webhook requests
 * handled by supported plugins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Webhook_Logger {

    /** @var DebugWP */
    private $core;

    /** Keys stripped from webhook payloads. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'api_key', 'apikey', 'api_secret',
        'token', 'access_token', 'refresh_token', 'client_secret',
        'private_key', 'credit_card', 'card_number', 'cvv', 'cvc', 'ssn',
    ];

    public function __construct( DebugWP $core ) {
        $this->core = $core;
        add_filter( 'rest_pre_dispatch', [ $this, 'on_rest_request' ], 10, 3 );
        add_action( 'admin_post_nopriv_debugwp_webhook_catch', '__return_true' ); // noop placeholder
        add_action( 'parse_request', [ $this, 'on_parse_request' ] );
    }

    /**
     * Log incoming REST API requests that route to supported-plugin namespaces.
     *
     * @param mixed            $result  Pre-dispatch result (null to proceed).
     * @param WP_REST_Server   $server  REST server.
     * @param WP_REST_Request  $request Request.
     * @return mixed Unmodified $result.
     */
    public function on_rest_request( $result, $server, $request ) {
        $route = $request->get_route();
        $slug  = $this->route_to_slug( $route );

        if ( ! $slug || ! $this->core->is_debug_enabled( $slug ) ) {
            return $result;
        }

        $method = $request->get_method();
        $body   = $this->sanitize_body( $request->get_body_params() ?: $request->get_json_params() );

        $this->core->insert_log(
            $slug,
            'webhook_incoming',
            'info',
            sprintf( '[Webhook] %s %s', $method, $route ),
            [
                'route'   => $route,
                'method'  => $method,
                'ip'      => $this->get_client_ip(),
                'body'    => $body,
                'headers' => $this->safe_headers( $request->get_headers() ),
            ]
        );

        return $result;
    }

    /**
     * Catch incoming webhooks via query-string URLs (non-REST), e.g.
     * ?wc-api=payfast, ?ppress_stripe_webhook, ?cyclesave_webhook, etc.
     *
     * @param WP $wp WordPress environment.
     */
    public function on_parse_request( $wp ) {
        $query = $_SERVER['QUERY_STRING'] ?? '';

        if ( empty( $query ) ) {
            return;
        }

        $slug = $this->query_to_slug( $query );

        if ( ! $slug || ! $this->core->is_debug_enabled( $slug ) ) {
            return;
        }

        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri     = $_SERVER['REQUEST_URI'] ?? '';
        $payload = [];

        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $raw = file_get_contents( 'php://input' );
            if ( $raw ) {
                $decoded = json_decode( $raw, true );
                $payload = is_array( $decoded ) ? $decoded : [ 'raw' => mb_substr( $raw, 0, 5000 ) ];
            }
        }

        $this->core->insert_log(
            $slug,
            'webhook_incoming',
            'info',
            sprintf( '[Webhook] %s %s', $method, $uri ),
            [
                'uri'     => $uri,
                'method'  => $method,
                'query'   => $query,
                'ip'      => $this->get_client_ip(),
                'body'    => $this->sanitize_body( $payload ),
                'headers' => $this->safe_headers( $this->get_request_headers() ),
            ]
        );
    }

    /**
     * Map REST route to a supported plugin slug.
     */
    private function route_to_slug( $route ) {
        $route_map = [
            '/profilepress/'  => 'profilepress',
            '/ppress/'        => 'profilepress',
            '/mailoptin/'     => 'mailoptin',
            '/fusewp/'        => 'fusewp',
            '/cyclesave/'     => 'cyclesave',
        ];

        // Check registered providers for matching route patterns.
        foreach ( $this->core->get_providers() as $slug => $provider ) {
            // Check if any path fragment appears in the route.
            foreach ( $provider->get_paths() as $path ) {
                $folder = basename( dirname( $path ) ) ?: basename( $path );
                if ( stripos( $route, '/' . $folder . '/' ) !== false ) {
                    return $slug;
                }
            }
        }

        // Fallback to hardcoded map.
        foreach ( $route_map as $prefix => $slug ) {
            if ( stripos( $route, $prefix ) !== false ) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Map query-string webhook URLs to a slug.
     */
    private function query_to_slug( $query ) {
        $query_map = [
            'ppress'       => 'profilepress',
            'profilepress' => 'profilepress',
            'mailoptin'    => 'mailoptin',
            'fusewp'       => 'fusewp',
            'cyclesave'    => 'cyclesave',
        ];

        $query_lower = strtolower( $query );
        foreach ( $query_map as $pattern => $slug ) {
            if ( strpos( $query_lower, $pattern ) !== false ) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Strip sensitive keys from body data.
     */
    private function sanitize_body( $body ) {
        if ( ! is_array( $body ) ) {
            return $body;
        }

        $sanitized = [];
        foreach ( $body as $key => $value ) {
            if ( in_array( strtolower( $key ), self::SENSITIVE_KEYS, true ) ) {
                $sanitized[ $key ] = '*** REDACTED ***';
            } elseif ( is_array( $value ) ) {
                $sanitized[ $key ] = $this->sanitize_body( $value );
            } else {
                $sanitized[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 2000 ) : $value;
            }
        }
        return $sanitized;
    }

    /**
     * Return safe request headers (strip auth tokens).
     */
    private function safe_headers( $headers ) {
        $redact = [ 'authorization', 'cookie', 'x-api-key' ];
        $safe   = [];
        foreach ( $headers as $key => $value ) {
            $lower = strtolower( $key );
            if ( in_array( $lower, $redact, true ) ) {
                $safe[ $key ] = '*** REDACTED ***';
            } else {
                $safe[ $key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
            }
        }
        return $safe;
    }

    private function get_client_ip() {
        // Prefer the standard header; do NOT trust X-Forwarded-For blindly.
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    }

    /**
     * Get request headers from $_SERVER for non-REST contexts.
     */
    private function get_request_headers() {
        $headers = [];
        foreach ( $_SERVER as $key => $value ) {
            if ( strpos( $key, 'HTTP_' ) === 0 ) {
                $name = str_replace( '_', '-', strtolower( substr( $key, 5 ) ) );
                $headers[ $name ] = $value;
            }
        }
        return $headers;
    }
}
