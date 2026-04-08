<?php
/**
 * ProfilePress provider for DebugWP.
 *
 * Boots the ProfilePress logger which captures incoming Stripe webhooks
 * and injects Stripe SDK HTTP client logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Provider_ProfilePress extends DebugWP_Plugin_Provider_Base {

    public function get_slug(): string {
        return 'profilepress';
    }

    public function get_label(): string {
        return 'ProfilePress';
    }

    public function get_paths(): array {
        return [ 'plugins/wp-user-avatar/', 'plugins/profilepress-pro/' ];
    }

    public function get_cron_hook_patterns(): array {
        return [ 'profilepress', 'ppress' ];
    }

    public function get_reader_class(): ?string {
        return 'DebugWP_Reader_ProfilePress';
    }

    public function boot( DebugWP $core ): void {
        // Always instantiated so the init hook for incoming webhooks is registered;
        // Stripe SDK injection only fires when debug is enabled.
        new DebugWP_ProfilePress_Logger( $core );
    }
}
