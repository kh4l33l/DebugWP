<?php
/**
 * CrawlWP Logger — captures EDD Software Licensing traffic to crawlwp.com.
 *
 * CrawlWP Premium activates, deactivates, and checks its license by POSTing/GETing
 * `edd_action` requests to https://crawlwp.com (see LicenseControl in
 * mihdan-index-now-pro/src/libsodium/src/Licensing/). Those calls run through the
 * WordPress HTTP API, so we hook http_api_debug and isolate the license requests to
 * record a focused, fully-parsed entry per attempt — surfacing the response status,
 * EDD error code, and a plain-language reason whenever activation fails.
 *
 * The generic HTTP logger also captures these calls; this logger adds a dedicated,
 * decoded `license` entry so failures and their cause are easy to find.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DebugWP_CrawlWP_Logger {

	/** @var DebugWP */
	private $core;

	/** Host that receives the EDD licensing requests. */
	private const STORE_HOST = 'crawlwp.com';

	/** edd_action values we treat as license events. */
	private const LICENSE_ACTIONS = [ 'activate_license', 'deactivate_license', 'check_license' ];

	/**
	 * Human-readable explanations for EDD license error / status codes.
	 */
	private const EDD_REASONS = [
		'expired'             => 'License key has expired',
		'disabled'            => 'License key has been disabled',
		'revoked'             => 'License key has been revoked',
		'missing'             => 'Invalid license key (not found)',
		'invalid'             => 'License is not active for this site URL',
		'site_inactive'       => 'License is not active for this site URL',
		'item_name_mismatch'  => 'License key is for a different product',
		'key_mismatch'        => 'License key mismatch',
		'no_activations_left' => 'License has reached its activation limit',
	];

	public function __construct( DebugWP $core ) {
		$this->core = $core;

		if ( ! $this->core->is_debug_enabled( 'crawlwp' ) ) {
			return;
		}

		add_action( 'http_api_debug', [ $this, 'capture_license_request' ], 10, 5 );
	}

	/**
	 * Fired after every wp_remote_* call; we only act on CrawlWP license traffic.
	 *
	 * @param array|WP_Error $response    Response or error.
	 * @param string         $context     'response' on success.
	 * @param string         $class       Transport class used.
	 * @param array          $parsed_args Request arguments.
	 * @param string         $url         Request URL.
	 */
	public function capture_license_request( $response, $context, $class, $parsed_args, $url ) {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( $host !== self::STORE_HOST && substr( $host, -( strlen( self::STORE_HOST ) + 1 ) ) !== '.' . self::STORE_HOST ) {
			return;
		}

		$params = $this->normalize_body( $parsed_args['body'] ?? [] );
		$action = $params['edd_action'] ?? '';

		if ( ! in_array( $action, self::LICENSE_ACTIONS, true ) ) {
			return;
		}

		$verb = [
			'activate_license'   => 'activation',
			'deactivate_license' => 'deactivation',
			'check_license'      => 'check',
		][ $action ];

		$ctx = [
			'edd_action' => $action,
			'url'        => $url,
			'request'    => $this->sanitize_request( $params ),
		];

		// ── Transport-level failure (no HTTP 200 reached the store) ──
		if ( is_wp_error( $response ) ) {
			$ctx['error_code']    = $response->get_error_code();
			$ctx['error_message'] = $response->get_error_message();
			$message = sprintf( 'License %s failed — request error: %s', $verb, $response->get_error_message() );
			$this->core->insert_log( 'crawlwp', 'license', 'error', $message, $ctx );
			return;
		}

		$status_code        = (int) wp_remote_retrieve_response_code( $response );
		$ctx['status_code'] = $status_code;
		$body               = wp_remote_retrieve_body( $response );
		$data               = json_decode( $body, true );

		if ( $status_code !== 200 ) {
			$ctx['response_body'] = mb_substr( (string) $body, 0, 2000 );
			$message = sprintf( 'License %s failed — HTTP %d from %s', $verb, $status_code, self::STORE_HOST );
			$this->core->insert_log( 'crawlwp', 'license', 'error', $message, $ctx );
			return;
		}

		if ( ! is_array( $data ) ) {
			// 200 but an unparseable body — record it so the cause is visible.
			$ctx['response_body'] = mb_substr( (string) $body, 0, 2000 );
			$message = sprintf( 'License %s — HTTP 200 with unrecognized response', $verb );
			$this->core->insert_log( 'crawlwp', 'license', 'warning', $message, $ctx );
			return;
		}

		$ctx['response'] = $this->sanitize_response( $data );

		[ $severity, $message ] = $this->interpret( $action, $verb, $data );

		$this->core->insert_log( 'crawlwp', 'license', $severity, $message, $ctx );
	}

	/* ── Interpretation ─────────────────────────────────────── */

	/**
	 * Turn a decoded EDD response into a [ severity, message ] pair.
	 *
	 * @return array{0:string,1:string}
	 */
	private function interpret( $action, $verb, array $data ) {
		// EDD signals an outright failure with success === false + an error code.
		if ( array_key_exists( 'success', $data ) && false === $data['success'] ) {
			$code   = isset( $data['error'] ) ? (string) $data['error'] : 'unknown';
			$reason = self::EDD_REASONS[ $code ] ?? "Error: {$code}";

			if ( $code === 'expired' && ! empty( $data['expires'] ) ) {
				$reason .= ' (expired ' . $data['expires'] . ')';
			}
			if ( $code === 'no_activations_left' && isset( $data['license_limit'] ) ) {
				$reason .= ' (limit: ' . $data['license_limit'] . ')';
			}

			return [ 'error', sprintf( 'License %s failed — %s', $verb, $reason ) ];
		}

		// Deactivation reports its outcome in the `license` field.
		if ( $action === 'deactivate_license' ) {
			$state = $data['license'] ?? 'unknown';
			$sev   = ( $state === 'deactivated' ) ? 'info' : 'warning';
			return [ $sev, sprintf( 'License deactivation — %s', $state ) ];
		}

		// Activation / check: the `license` field carries the status.
		$state = $data['license'] ?? 'unknown';

		if ( $state === 'valid' ) {
			$extra = '';
			if ( ! empty( $data['expires'] ) ) {
				$extra = ' (expires ' . $data['expires'] . ')';
			}
			return [ 'info', sprintf( 'License %s succeeded — valid%s', $verb, $extra ) ];
		}

		// Any other status (invalid, expired, disabled, site_inactive, …) is a problem.
		$reason   = self::EDD_REASONS[ $state ] ?? "status: {$state}";
		$severity = ( $state === 'invalid' || $state === 'inactive' ) ? 'error' : 'warning';

		return [ $severity, sprintf( 'License %s — %s', $verb, $reason ) ];
	}

	/* ── Helpers ────────────────────────────────────────────── */

	/**
	 * Normalize the request body to an associative array regardless of how it
	 * was passed (array for these calls, but be defensive about strings).
	 */
	private function normalize_body( $body ) {
		if ( is_array( $body ) ) {
			return $body;
		}
		if ( is_string( $body ) && $body !== '' ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			parse_str( $body, $parsed );
			return is_array( $parsed ) ? $parsed : [];
		}
		return [];
	}

	/**
	 * Redact the license key in the outgoing request before storing it.
	 */
	private function sanitize_request( array $params ) {
		if ( isset( $params['license'] ) ) {
			$params['license'] = $this->mask_key( (string) $params['license'] );
		}
		return $params;
	}

	/**
	 * Keep the useful EDD response fields; mask anything resembling the key.
	 */
	private function sanitize_response( array $data ) {
		// EDD echoes the submitted key back in some responses.
		if ( isset( $data['license_key'] ) ) {
			$data['license_key'] = $this->mask_key( (string) $data['license_key'] );
		}
		if ( isset( $data['customer_email'] ) && is_string( $data['customer_email'] ) ) {
			$data['customer_email'] = $this->mask_email( $data['customer_email'] );
		}
		return $data;
	}

	private function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 4 ) {
			return '***';
		}
		return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
	}

	private function mask_email( $email ) {
		if ( strpos( $email, '@' ) === false ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		return substr( $local, 0, 2 ) . '***@' . $domain;
	}
}
