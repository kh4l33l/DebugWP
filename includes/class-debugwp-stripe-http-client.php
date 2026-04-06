<?php
/**
 * Stripe SDK HTTP client wrapper for ProfilePress.
 *
 * Wraps ProfilePress's vendored Stripe PHP SDK CurlClient so that every
 * outgoing Stripe API call (cancel, retrieve, charge, etc.) is logged to
 * the DebugWP log table.
 *
 * No `implements ClientInterface` declaration is used intentionally:
 * ProfilePressVendor\Stripe\ApiRequestor::setHttpClient() has no type hint
 * and resolves the client via duck-typing, so declaring the interface is not
 * required and avoids a hard dependency on the ProfilePress vendor namespace
 * at class-load time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DebugWP_Stripe_HTTP_Client {

	/** @var DebugWP */
	private $core;

	/** @var object Real ProfilePressVendor\Stripe\HttpClient\CurlClient instance. */
	private $real_client;

	private const SENSITIVE_KEYS = [
		'password', 'secret', 'api_key', 'apikey', 'api_secret',
		'token', 'access_token', 'client_secret', 'private_key',
		'card_number', 'number', 'cvc', 'cvv', 'exp_month', 'exp_year',
	];

	public function __construct( DebugWP $core, $real_client ) {
		$this->core        = $core;
		$this->real_client = $real_client;
	}

	/**
	 * Intercept the request, delegate to the real client, then log it.
	 *
	 * @return array [ string $rbody, int $rcode, array $rheaders ]
	 */
	public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1' ) {
		list( $rbody, $rcode, $rheaders ) = $this->real_client->request(
			$method, $absUrl, $headers, $params, $hasFile, $apiMode
		);

		$this->log( $method, $absUrl, $params, $rbody, $rcode );

		return [ $rbody, $rcode, $rheaders ];
	}

	/**
	 * Delegate any other method calls to the real client (e.g. getUserAgentInfo()).
	 */
	public function __call( $name, $args ) {
		if ( method_exists( $this->real_client, $name ) ) {
			return call_user_func_array( [ $this->real_client, $name ], $args );
		}
	}

	/* ── Logging ─────────────────────────────────────────── */

	private function log( $method, $absUrl, $params, $rbody, $rcode ) {
		$severity = 'info';
		if ( $rcode >= 500 ) {
			$severity = 'error';
		} elseif ( $rcode >= 400 ) {
			$severity = 'warning';
		}

		$path    = (string) parse_url( $absUrl, PHP_URL_PATH );
		$message = sprintf( '%s %s — HTTP %d', strtoupper( (string) $method ), $path, $rcode );

		$ctx = [
			'url'         => $absUrl,
			'method'      => strtoupper( (string) $method ),
			'status_code' => $rcode,
			'params'      => $this->sanitize_params( $params ),
		];

		$data = json_decode( $rbody, true );

		if ( isset( $data['error'] ) ) {
			$severity         = 'error';
			$type             = $data['error']['type'] ?? 'api_error';
			$err_msg          = mb_substr( $data['error']['message'] ?? '', 0, 200 );
			$message         .= " — Stripe {$type}: {$err_msg}";
			$ctx['stripe_error'] = [
				'type'    => $type,
				'code'    => $data['error']['code'] ?? '',
				'message' => $err_msg,
			];
		} elseif ( is_array( $data ) ) {
			// Surface the most useful response fields without storing the full body.
			$ctx['response_object'] = $data['object'] ?? null;
			$ctx['response_id']     = $data['id'] ?? null;
			$ctx['response_status'] = $data['status'] ?? null;
		}

		$this->core->insert_log( 'profilepress', 'stripe_api', $severity, $message, $ctx );
	}

	/* ── Helpers ─────────────────────────────────────────── */

	private function sanitize_params( $params ) {
		if ( ! is_array( $params ) ) {
			return $params;
		}
		foreach ( $params as $key => $value ) {
			$lower = strtolower( (string) $key );
			foreach ( self::SENSITIVE_KEYS as $sensitive ) {
				if ( strpos( $lower, $sensitive ) !== false ) {
					$params[ $key ] = '***REDACTED***';
					break;
				}
			}
			if ( is_array( $value ) && ( $params[ $key ] ?? null ) !== '***REDACTED***' ) {
				$params[ $key ] = $this->sanitize_params( $value );
			}
		}
		return $params;
	}
}
