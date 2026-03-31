# DebugWP

Centralized error logging and troubleshooting for WordPress plugins. Captures HTTP requests, PHP errors, and native plugin logs in one unified admin viewer.

## Supported Plugins

- **ProfilePress** (wp-user-avatar)
- **CycleSave**
- **MailOptin**
- **FuseWP**

## Features

- **HTTP Request Logging** — Intercepts all outgoing HTTP requests from supported plugins via `http_api_debug`. Strips sensitive data (passwords, tokens, API keys) automatically. Detects API-level errors inside HTTP 200 responses (EDD license errors, Stripe errors, `success:false` patterns) and decodes Cloudflare/WAF HTML error pages with Ray ID extraction.
- **PHP Error Logging** — Captures PHP warnings, notices, deprecations, and fatal errors originating from watched plugin directories using `set_error_handler()` and a shutdown handler for true fatals.
- **Native Log Readers** — Browse each plugin's own log files directly in the admin, plus the WordPress `debug.log`, with severity detection and plugin attribution.
- **Unified Log Viewer** — WP_List_Table-based admin page with filtering by plugin, type, and severity. Search, sortable columns, pagination, and tabbed detail panels with Summary, Raw JSON, and HTML Preview views.
- **wp-config.php Management** — Toggle `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, and `SCRIPT_DEBUG` from the admin UI. Creates automatic backups before modifying and supports one-click restore.
- **Per-Plugin Debug Toggles** — Enable/disable debug mode per plugin with automatic 48-hour auto-disable via cron to prevent forgotten debug sessions.
- **Log Retention** — Configurable max entries (100–50,000) and retention period (1–90 days). Hourly cron cleans up expired and excess logs.
- **CSV Export** — Download up to 10,000 log entries as CSV.
- **Clean Uninstall** — Drops custom tables, deletes all options and transients, and clears cron events.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the `debugwp` folder to `wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **DebugWP → Settings** to enable debug mode for your plugins.
4. View captured logs under **DebugWP → Log Viewer**.

## Screenshots

_Coming soon._

## License

GPLv2 or later.
