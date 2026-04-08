<?php
/**
 * Abstract base class for plugin providers.
 *
 * Implements sensible defaults so concrete providers only need to
 * override the methods that are relevant to them.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class DebugWP_Plugin_Provider_Base implements DebugWP_Plugin_Provider {

    /**
     * Cron hook patterns — override in subclass if applicable.
     *
     * @return string[]
     */
    public function get_cron_hook_patterns(): array {
        return [];
    }

    /**
     * Native log reader class — override in subclass if applicable.
     *
     * @return string|null
     */
    public function get_reader_class(): ?string {
        return null;
    }

    /**
     * Boot hook — override in subclass to register loggers, hooks, etc.
     *
     * @param DebugWP $core
     */
    public function boot( DebugWP $core ): void {
        // No-op by default.
    }
}
