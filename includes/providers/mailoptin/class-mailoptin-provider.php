<?php
/**
 * MailOptin provider for DebugWP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Provider_MailOptin extends DebugWP_Plugin_Provider_Base {

    public function get_slug(): string {
        return 'mailoptin';
    }

    public function get_label(): string {
        return 'MailOptin';
    }

    public function get_paths(): array {
        return [ 'plugins/mailoptin/' ];
    }

    public function get_cron_hook_patterns(): array {
        return [ 'mailoptin', 'mo_' ];
    }

    public function get_reader_class(): ?string {
        return 'DebugWP_Reader_MailOptin';
    }
}
