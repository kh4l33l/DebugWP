<?php
/**
 * ProfilePress Logger — captures incoming Stripe webhooks and outgoing Stripe API calls.
 *
 * Incoming: hooks init at priority 8 (before ProfilePress at 9) to log the raw
 *           webhook payload from any ppress-listener endpoint.
 *
 * Outgoing: injects DebugWP_Stripe_HTTP_Client into the Stripe PHP SDK via
 *           ProfilePressVendor\Stripe\ApiRequestor::setHttpClient() so every
 *           Stripe API call made by ProfilePress is captured (cancel, retrieve, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DebugWP_ProfilePress_Logger {

	/** @var DebugWP */
	private $core;

	private const SENSITIVE_KEYS = [
		'password', 'passwd', 'secret', 'api_key', 'apikey', 'api_secret',
		'token', 'access_token', 'refresh_token', 'client_secret', 'license',
		'private_key', 'authorization', 'card_number', 'cvv', 'cvc',
	];

	public function __construct( DebugWP $core ) {
		$this->core = $core;

		// Always register the incoming webhook hook — debug check lives inside.
		add_action( 'init', [ $this, 'capture_incoming_webhook' ], 8 );

		// Outgoing Stripe SDK calls — only inject when debug is enabled.
		if ( $this->core->is_debug_enabled( 'profilepress' ) ) {
			$this->inject_stripe_http_client();
		}
	}

	/* ── Incoming webhooks ───────────────────────────────── */

	/**
	 * Reads and logs the raw incoming webhook payload before ProfilePress processes it.
	 * Runs at init priority 8; ProfilePress hooks at priority 9.
	 */
	public function capture_incoming_webhook() {
		if ( empty( $_GET['ppress-listener'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! $this->core->is_debug_enabled( 'profilepress' ) ) {
			return;
		}

		$listener = sanitize_key( wp_unslash( $_GET['ppress-listener'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// php://input can be read multiple times for JSON request bodies in PHP 7.4+.
		$raw_body = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload  = json_decode( $raw_body, true );

		$event_type = is_array( $payload ) ? ( $payload['type'] ?? 'unknown' ) : 'unknown';
		$object_id  = $payload['data']['object']['id'] ?? '';
		$livemode   = isset( $payload['livemode'] ) ? ( $payload['livemode'] ? 'live' : 'test' ) : '';

		$message = sprintf( 'Incoming %s webhook — %s', $listener, $event_type );
		if ( $livemode ) {
			$message .= " ({$livemode})";
		}

		$ctx = [
			'listener'   => $listener,
			'event_type' => $event_type,
			'object_id'  => $object_id,
			'livemode'   => $livemode,
		];

		if ( is_array( $payload ) ) {
			$ctx['payload'] = $this->sanitize_payload( $payload );
		} else {
			// Non-JSON body — store truncated raw bytes for inspection.
			$ctx['raw_body'] = mb_substr( $raw_body, 0, 2000 );
		}

		$this->core->insert_log( 'profilepress', 'webhook_incoming', 'info', $message, $ctx );
	}

	/* ── Outgoing Stripe SDK calls ───────────────────────── */

	/**
	 * Injects DebugWP_Stripe_HTTP_Client into the Stripe SDK.
	 *
	 * setHttpClient() has no type hint, so our wrapper does not need to declare
	 * `implements ClientInterface` — the SDK resolves via duck-typing.
	 */
	private function inject_stripe_http_client() {
		if ( ! class_exists( 'ProfilePressVendor\Stripe\ApiRequestor' ) ) {
			return;
		}

		$real_client = \ProfilePressVendor\Stripe\HttpClient\CurlClient::instance();

		\ProfilePressVendor\Stripe\ApiRequestor::setHttpClient(
			new DebugWP_Stripe_HTTP_Client( $this->core, $real_client )
		);
	}

	/* ── Helpers ─────────────────────────────────────────── */

	private function sanitize_payload( array $data ) {
		foreach ( $data as $key => $value ) {
			$lower = strtolower( (string) $key );
			foreach ( self::SENSITIVE_KEYS as $sensitive ) {
				if ( strpos( $lower, $sensitive ) !== false ) {
					$data[ $key ] = '***REDACTED***';
					break;
				}
			}
			if ( is_array( $value ) && ( $data[ $key ] ?? null ) !== '***REDACTED***' ) {
				$data[ $key ] = $this->sanitize_payload( $value );
			}
		}
		return $data;
	}
}
