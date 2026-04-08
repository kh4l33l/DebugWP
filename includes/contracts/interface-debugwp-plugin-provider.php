<?php
/**
 * Provider interface — the contract every supported plugin must implement.
 *
 * Third-party plugins can implement this interface (or extend the base class)
 * and register themselves via the `debugwp_register_providers` action:
 *
 *     add_action( 'debugwp_register_providers', function ( $debugwp ) {
 *         require_once __DIR__ . '/class-my-provider.php';
 *         $debugwp->register_provider( new My_DebugWP_Provider() );
 *     } );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface DebugWP_Plugin_Provider {

    /**
     * Unique slug used in options, DB rows, and CSS classes.
     * Must be lowercase alphanumeric + hyphens/underscores.
     *
     * @return string
     */
    public function get_slug(): string;

    /**
     * Human-readable label shown in the admin UI.
     *
     * @return string
     */
    public function get_label(): string;

    /**
     * File-path fragments used to attribute errors and HTTP requests to this plugin.
     * Each entry is matched via strpos() against full file paths.
     *
     * Example: [ 'plugins/my-plugin/', 'plugins/my-plugin-pro/' ]
     *
     * @return string[]
     */
    public function get_paths(): array;

    /**
     * Substrings matched against WP cron hook names to associate cron events
     * with this plugin. Return an empty array if not applicable.
     *
     * Example: [ 'my_plugin_', 'myplugin' ]
     *
     * @return string[]
     */
    public function get_cron_hook_patterns(): array;

    /**
     * Fully-qualified class name of the native log reader for this plugin,
     * or null if no native reader exists.
     *
     * The class must implement static methods:
     *   - get_logs( int $limit ): array
     *   - get_unavailable_notice(): string  (optional)
     *
     * @return string|null
     */
    public function get_reader_class(): ?string;

    /**
     * Called once when the provider is registered with DebugWP.
     * Use this to hook into WordPress actions/filters, instantiate loggers, etc.
     *
     * @param DebugWP $core The core DebugWP instance.
     */
    public function boot( DebugWP $core ): void;
}
