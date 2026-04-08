<?php
/**
 * CycleSave provider for DebugWP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Provider_CycleSave extends DebugWP_Plugin_Provider_Base {

    public function get_slug(): string {
        return 'cyclesave';
    }

    public function get_label(): string {
        return 'CycleSave';
    }

    public function get_paths(): array {
        return [ 'plugins/cyclesave/', 'plugins/cyclesave-pro/' ];
    }

    public function get_cron_hook_patterns(): array {
        return [ 'cyclesave' ];
    }

    public function get_reader_class(): ?string {
        return 'DebugWP_Reader_CycleSave';
    }
}
