<?php
/**
 * FuseWP Logger — traces sync hooks, queue pushes, and field mapping.
 *
 * Hooks into key FuseWP actions/filters to log the sync pipeline:
 *  1. UM profile update triggers (um_user_after_updating_profile, um_after_user_account_updated)
 *  2. sync_user() call path
 *  3. fusewp_profile_update action
 *  4. Queue job push & processing
 *  5. FluentCRM subscription parameters
 *  6. Field mapping data entity resolution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DebugWP_FuseWP_Logger {

	/** @var DebugWP */
	private $core;

	public function __construct( DebugWP $core ) {
		$this->core = $core;

		if ( ! $this->core->is_debug_enabled( 'fusewp' ) ) {
			return;
		}

		// UM profile update hooks.
		add_action( 'um_user_after_updating_profile', [ $this, 'log_um_profile_update' ], 1, 3 );
		add_action( 'um_after_user_account_updated', [ $this, 'log_um_account_update' ], 1, 2 );

		// FuseWP core sync hooks.
		add_action( 'fusewp_profile_update', [ $this, 'log_profile_update' ], 1 );

		// Field mapping data resolution.
		add_filter( 'fusewp_get_mapping_user_data_entity', [ $this, 'log_mapping_user_data' ], 999, 4 );

		// User sync roles filter.
		add_filter( 'fusewp_user_sync_roles', [ $this, 'log_user_sync_roles' ], 999, 3 );

		// FluentCRM subscription parameters.
		add_filter( 'fusewp_fluentcrm_subscription_parameters', [ $this, 'log_fluentcrm_params' ], 999, 2 );

		// Queue job handler.
		add_action( 'fusewp_queued_job_handler', [ $this, 'log_queue_job' ], 1 );

		// Sync enable check.
		add_filter( 'fusewp_enable_sync_on_profile_update', [ $this, 'log_sync_enabled_check' ], 999, 3 );
	}

	/* ── Ultimate Member hooks ───────────────────────────── */

	public function log_um_profile_update( $to_update, $user_id = null, $args = null ) {
		$this->log( 'info', 'um_user_after_updating_profile fired', [
			'to_update_keys' => is_array( $to_update ) ? array_keys( $to_update ) : gettype( $to_update ),
			'user_id'        => $user_id,
			'has_args'       => ! empty( $args ),
		] );

		if ( is_numeric( $user_id ) ) {
			$this->log_sync_diagnostics( $user_id );
		}

		return $to_update;
	}

	public function log_um_account_update( $user_id, $changes = null ) {
		$this->log( 'info', 'um_after_user_account_updated fired', [
			'user_id' => $user_id,
			'changes' => is_array( $changes ) ? array_keys( $changes ) : null,
		] );
	}

	/* ── FuseWP core hooks ───────────────────────────────── */

	public function log_profile_update( $user_id ) {
		$this->log( 'info', 'fusewp_profile_update action fired', [
			'user_id'    => $user_id,
			'user_type'  => gettype( $user_id ),
			'is_numeric' => is_numeric( $user_id ),
		] );
	}

	public function log_sync_enabled_check( $is_enabled, $source_id, $user_id ) {
		$this->log( 'info', 'fusewp_enable_sync_on_profile_update check', [
			'is_enabled' => $is_enabled,
			'source_id'  => $source_id,
			'user_id'    => $user_id,
			'setting'    => get_option( 'fusewp_settings', [] ),
		] );
		return $is_enabled;
	}

	public function log_user_sync_roles( $roles, $user_id, $action ) {
		$this->log( 'info', 'fusewp_user_sync_roles filter', [
			'roles'   => $roles,
			'user_id' => $user_id,
			'action'  => $action,
		] );
		return $roles;
	}

	public function log_mapping_user_data( $value, $field_id, $wp_user_id, $extras = null ) {
		// Only log UM fields to avoid noise.
		if ( strpos( $field_id, 'fsultimem_' ) === 0 || $field_id === 'AMUK_optin_radio' ) {
			$this->log( 'info', 'Field mapping data resolved', [
				'field_id'   => $field_id,
				'value'      => $value,
				'user_id'    => $wp_user_id,
				'value_type' => gettype( $value ),
			] );
		}
		return $value;
	}

	/* ── FluentCRM ───────────────────────────────────────── */

	public function log_fluentcrm_params( $parameters, $sync_action = null ) {
		$safe_params = $parameters;
		// Redact email for privacy.
		if ( isset( $safe_params['email'] ) ) {
			$safe_params['email'] = $this->mask_email( $safe_params['email'] );
		}
		$this->log( 'info', 'FluentCRM subscribe_user parameters', [
			'parameters'   => $safe_params,
			'has_custom'   => ! empty( $parameters['custom_values'] ),
			'custom_keys'  => isset( $parameters['custom_values'] ) ? array_keys( $parameters['custom_values'] ) : [],
		] );
		return $parameters;
	}

	/* ── Queue ───────────────────────────────────────────── */

	public function log_queue_job( $item ) {
		$safe_item = $item;
		if ( isset( $safe_item['email_address'] ) ) {
			$safe_item['email_address'] = $this->mask_email( $safe_item['email_address'] );
		}
		// Don't log the full mappingUserDataEntity — it's large.
		if ( isset( $safe_item['mappingUserDataEntity'] ) ) {
			$safe_item['mappingUserDataEntity'] = '(MappingUserDataEntity object)';
		}
		if ( isset( $safe_item['extras'] ) ) {
			$safe_item['extras'] = '(WP_User object)';
		}

		$this->log( 'info', 'Queue job processing: ' . ( $item['action'] ?? 'unknown' ), [
			'item'       => $safe_item,
			'source_id'  => $item['source_id'] ?? null,
			'rule_id'    => $item['rule_id'] ?? null,
			'list_id'    => $item['list_id'] ?? null,
			'integration' => $item['integration'] ?? null,
		] );
	}

	/**
	 * Log sync rule and queue state — call after UM profile update hooks fire.
	 */
	public function log_sync_diagnostics( $user_id ) {
		global $wpdb;

		// Check sync rules.
		$rule = function_exists( 'fusewp_sync_get_rule_by_source' )
			? fusewp_sync_get_rule_by_source( 'wp_user_roles' )
			: null;

		$destinations = null;
		if ( $rule ) {
			$destinations = $rule['destinations'] ?? null;
			if ( is_string( $destinations ) ) {
				$destinations = json_decode( $destinations, true );
			}
		}

		// Check queue table.
		$queue_table  = $wpdb->prefix . 'fusewp_queue_jobs';
		$pending_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table}" ); // phpcs:ignore

		$this->log( 'info', 'Sync diagnostics snapshot', [
			'user_id'            => $user_id,
			'rule_exists'        => ! empty( $rule ),
			'rule_id'            => $rule['id'] ?? null,
			'destination_count'  => is_array( $destinations ) ? count( $destinations ) : 0,
			'destination_items'  => is_array( $destinations ) ? array_column( $destinations, 'destination_item' ) : null,
			'integrations'       => is_array( $destinations ) ? array_column( $destinations, 'integration' ) : null,
			'pending_queue_jobs' => $pending_jobs,
			'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		] );
	}

	/* ── Helpers ─────────────────────────────────────────── */

	private function mask_email( $email ) {
		if ( ! is_string( $email ) || strpos( $email, '@' ) === false ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		return substr( $local, 0, 2 ) . '***@' . $domain;
	}

	private function log( $severity, $message, $context = [] ) {
		$context['backtrace_summary'] = wp_debug_backtrace_summary( null, 3, false );
		$this->core->insert_log( 'fusewp', 'sync', $severity, $message, $context );
	}
}
