<?php
/**
 * FuseWP provider for DebugWP.
 *
 * Boots the FuseWP logger which traces sync hooks, queue pushes, and field mapping.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Provider_FuseWP extends DebugWP_Plugin_Provider_Base {

    public function get_slug(): string {
        return 'fusewp';
    }

    public function get_label(): string {
        return 'FuseWP';
    }

    public function get_paths(): array {
        return [ 'plugins/fusewp/', 'plugins/fusewp-pro/' ];
    }

    public function get_cron_hook_patterns(): array {
        return [ 'fusewp' ];
    }

    public function get_reader_class(): ?string {
        return 'DebugWP_Reader_FuseWP';
    }

    public function boot( DebugWP $core ): void {
        // Traces sync hooks, queue, and field mapping.
        new DebugWP_FuseWP_Logger( $core );
    }
}
