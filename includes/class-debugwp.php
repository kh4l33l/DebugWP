<?php
/**
 * Core singleton — boots all DebugWP components.
 *
 * Plugin support is now driven by providers (see DebugWP_Plugin_Provider).
 * Built-in providers live under includes/providers/{slug}/.
 * Third-party plugins register via the `debugwp_register_providers` action.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DebugWP {

    private static $instance = null;

    /** @var DebugWP_Plugin_Provider[] Registered providers keyed by slug. */
    private $providers = [];

    /**
     * Legacy format cache — built lazily from providers.
     *
     * @var array|null slug => [ 'label' => string, 'paths' => string[] ]
     */
    private $supported_plugins_cache = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Discover and register built-in providers.
        $this->discover_built_in_providers();

        // Allow third-party plugins to register their own providers.
        do_action( 'debugwp_register_providers', $this );

        // Admin-only components.
        if ( is_admin() ) {
            new DebugWP_Settings( $this );
            new DebugWP_Ajax( $this );
            DebugWP_Cron_UI::init();
            new DebugWP_Dashboard_Widget( $this );
            new DebugWP_Site_Health( $this );
        }

        // HTTP logger — only boot if at least one plugin debug is active (it is verbose).
        if ( $this->has_any_debug_enabled() ) {
            new DebugWP_HTTP_Logger( $this );
            new DebugWP_Webhook_Logger( $this );
        }

        // Mail logger — boot when debug enabled (lighter than HTTP logger).
        if ( $this->has_any_debug_enabled() ) {
            new DebugWP_Mail_Logger( $this );
        }

        // PHP error handler — always register so non-fatal PHP errors from watched plugin
        // directories are captured as soon as DebugWP loads, even if no debug toggle is on.
        // The handler itself checks is_debug_enabled() before writing to the DB.
        new DebugWP_PHP_Logger( $this );

        // Cron — always register so cleanup runs.
        new DebugWP_Cron( $this );

        // Cron logger — logs when supported-plugin cron events fire.
        new DebugWP_Cron_Logger( $this );
    }

    /* ── Provider registration ───────────────────────────── */

    /**
     * Register a plugin provider.
     *
     * @param DebugWP_Plugin_Provider $provider
     */
    public function register_provider( DebugWP_Plugin_Provider $provider ) {
        $slug = sanitize_key( $provider->get_slug() );

        if ( isset( $this->providers[ $slug ] ) ) {
            return; // Already registered — first wins.
        }

        $this->providers[ $slug ] = $provider;

        // Invalidate legacy cache.
        $this->supported_plugins_cache = null;

        // Let the provider hook into WordPress.
        $provider->boot( $this );
    }

    /**
     * Get all registered providers keyed by slug.
     *
     * @return DebugWP_Plugin_Provider[]
     */
    public function get_providers() {
        return $this->providers;
    }

    /**
     * Scan the built-in providers directory and register each provider found.
     */
    private function discover_built_in_providers() {
        $providers_dir = DEBUGWP_DIR . 'includes/providers/';

        if ( ! is_dir( $providers_dir ) ) {
            return;
        }

        $provider_files = glob( $providers_dir . '*/class-*-provider.php' );

        if ( empty( $provider_files ) ) {
            return;
        }

        $before = get_declared_classes();

        foreach ( $provider_files as $file ) {
            require_once $file;
        }

        $new_classes = array_diff( get_declared_classes(), $before );

        foreach ( $new_classes as $class_name ) {
            $ref = new ReflectionClass( $class_name );
            if ( $ref->isAbstract() || $ref->isInterface() ) {
                continue;
            }
            if ( $ref->implementsInterface( 'DebugWP_Plugin_Provider' ) ) {
                $this->register_provider( new $class_name() );
            }
        }
    }

    /* ── Getters ─────────────────────────────────────────── */

    /**
     * Returns the legacy format used by Settings, PHP Logger, HTTP Logger, etc.
     * Built dynamically from registered providers.
     *
     * @return array slug => [ 'label' => string, 'paths' => string[] ]
     */
    public function get_supported_plugins() {
        if ( null === $this->supported_plugins_cache ) {
            $this->supported_plugins_cache = [];
            foreach ( $this->providers as $slug => $provider ) {
                $this->supported_plugins_cache[ $slug ] = [
                    'label' => $provider->get_label(),
                    'paths' => $provider->get_paths(),
                ];
            }
        }
        return $this->supported_plugins_cache;
    }

    /**
     * Get merged cron hook patterns from all providers plus DebugWP's own.
     *
     * @return array slug => string[]
     */
    public function get_all_cron_hook_patterns() {
        $patterns = [ 'debugwp' => [ 'debugwp' ] ];
        foreach ( $this->providers as $slug => $provider ) {
            $hook_patterns = $provider->get_cron_hook_patterns();
            if ( ! empty( $hook_patterns ) ) {
                $patterns[ $slug ] = $hook_patterns;
            }
        }
        return $patterns;
    }

    /**
     * Get merged file-path fragments from all providers.
     * Used by the shutdown handler to attribute fatal errors.
     *
     * @return array slug => string[]
     */
    public static function get_all_paths() {
        if ( null === self::$instance ) {
            return [];
        }
        $paths = [];
        foreach ( self::$instance->providers as $slug => $provider ) {
            $paths[ $slug ] = $provider->get_paths();
        }
        return $paths;
    }

    public function get_settings() {
        return wp_parse_args(
            get_option( 'debugwp_settings', [] ),
            [
                'max_entries'        => 5000,
                'retention_days'     => 7,
                'cron_email_enabled' => 0,
                'cron_email_address' => '',
            ]
        );
    }

    public function is_debug_enabled( $slug ) {
        return (bool) get_option( "debugwp_{$slug}_enabled", false );
    }

    public function has_any_debug_enabled() {
        foreach ( array_keys( $this->providers ) as $slug ) {
            if ( $this->is_debug_enabled( $slug ) ) {
                return true;
            }
        }
        return false;
    }

    public function get_enabled_slugs() {
        $enabled = [];
        foreach ( array_keys( $this->providers ) as $slug ) {
            if ( $this->is_debug_enabled( $slug ) ) {
                $enabled[] = $slug;
            }
        }
        return $enabled;
    }

    /**
     * Resolve a file path to a supported plugin slug.
     */
    public function resolve_plugin_slug( $file_path ) {
        foreach ( $this->providers as $slug => $provider ) {
            foreach ( $provider->get_paths() as $path_fragment ) {
                if ( strpos( $file_path, $path_fragment ) !== false ) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /* ── Log insertion helper ───────────────────────────── */

    /**
     * Insert a log entry. Identical entries (same slug + type + severity + message)
     * within the last 5 minutes are deduplicated — the existing row's hit_count
     * and last_seen are updated instead of inserting a duplicate.
     */
    public function insert_log( $plugin_slug, $log_type, $severity, $message, $context = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        $slug_clean    = sanitize_key( $plugin_slug );
        $type_clean    = sanitize_key( $log_type );
        $severity_clean = sanitize_key( $severity );
        $message_clean = sanitize_text_field( mb_substr( $message, 0, 65000 ) );
        $now           = current_time( 'mysql' );

        // Deduplication: look for a matching entry in the last 5 minutes.
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE plugin_slug = %s AND log_type = %s AND severity = %s AND message = %s AND created_at >= %s
             ORDER BY created_at DESC LIMIT 1",
            $slug_clean,
            $type_clean,
            $severity_clean,
            $message_clean,
            $cutoff
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $existing ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET hit_count = hit_count + 1, last_seen = %s WHERE id = %d",
                $now,
                $existing->id
            ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return;
        }

        $result = $wpdb->insert(
            $table,
            [
                'plugin_slug' => $slug_clean,
                'log_type'    => $type_clean,
                'severity'    => $severity_clean,
                'message'     => $message_clean,
                'context'     => wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ),
                'hit_count'   => 1,
                'last_seen'   => $now,
                'created_at'  => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( false === $result ) {
            error_log( sprintf( '[DebugWP] DB insert failed for %s (%s): %s', $plugin_slug, $severity, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
