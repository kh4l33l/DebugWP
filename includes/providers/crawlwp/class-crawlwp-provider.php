<?php
/**
 * CrawlWP provider for DebugWP.
 *
 * Covers the CrawlWP SEO plugins (formerly Mihdan Index Now):
 *   - mihdan-index-now      (lite / core)
 *   - mihdan-index-now-pro  (premium)
 *
 * Boots a logger focused on EDD Software Licensing traffic to crawlwp.com so
 * license activation / deactivation / check requests and their responses are
 * captured as first-class entries — making it clear when activation fails and why.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Provider_CrawlWP extends DebugWP_Plugin_Provider_Base {

    public function get_slug(): string {
        return 'crawlwp';
    }

    public function get_label(): string {
        return 'CrawlWP';
    }

    public function get_paths(): array {
        return [ 'plugins/mihdan-index-now/', 'plugins/mihdan-index-now-pro/' ];
    }

    public function get_cron_hook_patterns(): array {
        return [ 'crawlwp', 'mihdan-index-now', 'index_now' ];
    }

    public function get_reader_class(): ?string {
        return 'DebugWP_Reader_CrawlWP';
    }

    public function boot( DebugWP $core ): void {
        // Traces EDD license activation/deactivation/check requests and responses.
        new DebugWP_CrawlWP_Logger( $core );
    }
}
