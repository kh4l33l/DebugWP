<?php
/**
 * Core singleton — boots all DebugWP components.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DebugWP {

    private static $instance = null;

    /** @var array Supported plugins: slug => { label, paths[] } */
    private $supported_plugins = [];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_supported_plugins();

        // Admin-only components.
        if ( is_admin() ) {
            new DebugWP_Settings( $this );
            new DebugWP_Ajax( $this );
            DebugWP_Cron_UI::init();
        }

        // HTTP logger — only boot if at least one plugin debug is active (it is verbose).
        if ( $this->has_any_debug_enabled() ) {
            new DebugWP_HTTP_Logger( $this );
        }

        // PHP error handler — always register so non-fatal PHP errors from watched plugin
        // directories are captured as soon as DebugWP loads, even if no debug toggle is on.
        // The handler itself checks is_debug_enabled() before writing to the DB.
        new DebugWP_PHP_Logger( $this );

        // Cron — always register so cleanup runs.
        new DebugWP_Cron( $this );

        // ProfilePress logger — always instantiated so the init hook for incoming
        // webhooks is registered; Stripe SDK injection only fires when debug is enabled.
        new DebugWP_ProfilePress_Logger( $this );

        // FuseWP logger — traces sync hooks, queue, and field mapping.
        new DebugWP_FuseWP_Logger( $this );

        // Cron logger — logs when supported-plugin cron events fire.
        new DebugWP_Cron_Logger( $this );
    }

    private function register_supported_plugins() {
        $this->supported_plugins = [
            'mailoptin' => [
                'label' => 'MailOptin',
                'paths' => [ 'plugins/mailoptin/' ],
            ],
            'cyclesave' => [
                'label' => 'CycleSave',
                'paths' => [ 'plugins/cyclesave/', 'plugins/cyclesave-pro/' ],
            ],
            'profilepress' => [
                'label' => 'ProfilePress',
                'paths' => [ 'plugins/wp-user-avatar/', 'plugins/profilepress-pro/' ],
            ],
            'fusewp' => [
                'label' => 'FuseWP',
                'paths' => [ 'plugins/fusewp/', 'plugins/fusewp-pro/' ],
            ],
        ];
    }

    /* ── Getters ─────────────────────────────────────────── */

    public function get_supported_plugins() {
        return $this->supported_plugins;
    }

    public function get_settings() {
        return wp_parse_args(
            get_option( 'debugwp_settings', [] ),
            [
                'max_entries'    => 5000,
                'retention_days' => 7,
            ]
        );
    }

    public function is_debug_enabled( $slug ) {
        return (bool) get_option( "debugwp_{$slug}_enabled", false );
    }

    public function has_any_debug_enabled() {
        foreach ( array_keys( $this->supported_plugins ) as $slug ) {
            if ( $this->is_debug_enabled( $slug ) ) {
                return true;
            }
        }
        return false;
    }

    public function get_enabled_slugs() {
        $enabled = [];
        foreach ( array_keys( $this->supported_plugins ) as $slug ) {
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
        foreach ( $this->supported_plugins as $slug => $info ) {
            foreach ( $info['paths'] as $path_fragment ) {
                if ( strpos( $file_path, $path_fragment ) !== false ) {
                    return $slug;
                }
            }
        }
        return null;
    }

    /* ── Log insertion helper ───────────────────────────── */

    public function insert_log( $plugin_slug, $log_type, $severity, $message, $context = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        $result = $wpdb->insert(
            $table,
            [
                'plugin_slug' => sanitize_key( $plugin_slug ),
                'log_type'    => sanitize_key( $log_type ),
                'severity'    => sanitize_key( $severity ),
                'message'     => sanitize_text_field( mb_substr( $message, 0, 65000 ) ),
                'context'     => wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( false === $result ) {
            error_log( sprintf( '[DebugWP] DB insert failed for %s (%s): %s', $plugin_slug, $severity, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
