# DebugWP

Centralized error logging and troubleshooting for WordPress plugins. Captures HTTP requests, PHP errors, cron failures, outgoing emails, incoming webhooks, and native plugin logs in one unified admin viewer.

## Supported Plugins

DebugWP uses a **provider-based architecture** — each supported plugin lives in its own folder under `includes/providers/` with a dedicated provider class implementing the `DebugWP_Plugin_Provider` interface.

- **ProfilePress** (wp-user-avatar)
- **CycleSave**
- **MailOptin**
- **FuseWP**
- **CrawlWP** (mihdan-index-now / mihdan-index-now-pro)

Adding support for a new plugin is as simple as creating a new provider folder.

## Features

### Logging & Capture

- **HTTP Request Logging** — Intercepts all outgoing HTTP requests from supported plugins via `http_api_debug`. Strips sensitive data (passwords, tokens, API keys) automatically. Detects API-level errors inside HTTP 200 responses (EDD license errors, Stripe errors, `success:false` patterns) and decodes Cloudflare/WAF HTML error pages with Ray ID extraction.
- **PHP Error Logging** — Captures PHP warnings, notices, deprecations, and fatal errors originating from watched plugin directories using `set_error_handler()` and a shutdown handler for true fatals.
- **Mail Logging** — Captures `wp_mail()` calls from supported plugins, logging recipients, subjects, headers, and attachment counts. Failed emails are logged at error severity.
- **Webhook Logging** — Logs incoming REST API and query-string webhook requests, matching routes to plugin slugs. Sensitive headers and body keys are automatically redacted.
- **Cron Error Catching** — Wraps cron callbacks with `set_error_handler()` / `try-catch` to capture failures that would otherwise be silent.
- **Native Log Readers** — Browse each plugin's own log files directly in the admin, plus the WordPress `debug.log`, with severity detection and plugin attribution.
- **Log Deduplication** — Identical log entries within a 5-minute window are deduplicated automatically, incrementing a hit count and updating the last-seen timestamp instead of inserting duplicates.

### Admin UI

- **Unified Log Viewer** — Full-width WP_List_Table with filtering by plugin, type, and severity. Search across messages and context data, sortable columns, pagination, and tabbed detail panels (Summary, Raw JSON, HTML Preview). Dedicated Actions column with Details and Delete buttons.
- **Dashboard Widget** — At-a-glance widget on the WordPress dashboard showing 24-hour error/warning/total counts, active debug modes, and overdue cron alerts.
- **Environment Info Panel** — Displays WordPress, PHP, server, database, mail, active plugin, and theme information with a "Copy to Clipboard" button. Available under **DebugWP → Environment**.
- **wp-config.php Management** — Toggle `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, and `SCRIPT_DEBUG` from the admin UI. Creates automatic backups before modifying and supports one-click restore.
- **Per-Plugin Debug Toggles** — Enable/disable debug mode per plugin with automatic 48-hour auto-disable via cron to prevent forgotten debug sessions.

### Cron Monitoring & Notifications

- **Overdue Cron Alerts** — Admin notices on all DebugWP pages when cron events are overdue.
- **Email Notifications** — Configurable email alerts when cron jobs fail or are overdue, with transient-based throttling to prevent floods. Recipient address is editable via the `debugwp_cron_alert_email` filter.

### Site Health Integration

- **Stale Debug Modes** — Flags debug modes left enabled for more than 24 hours.
- **Overdue Crons** — Reports cron events that haven't run on schedule.
- **Error Rate** — Monitors the 24-hour error count and flags high volumes.
- **Debug Log Size** — Warns when `debug.log` grows beyond recommended thresholds.

### WP-CLI

Full command-line interface via `wp debugwp`:

| Command | Description |
|---------|-------------|
| `wp debugwp status` | Show debug mode status for all plugins |
| `wp debugwp toggle <slug>` | Toggle debug mode for a plugin |
| `wp debugwp logs` | List log entries (supports `--severity`, `--plugin`, `--type`, `--limit`, `--format`) |
| `wp debugwp cron` | Show cron event status |
| `wp debugwp flush` | Delete all log entries |
| `wp debugwp env` | Display environment information |

### Data Management

- **Log Retention** — Configurable max entries (100–50,000) and retention period (1–90 days). Hourly cron cleans up expired and excess logs.
- **CSV Export** — Download up to 10,000 log entries as CSV.
- **Clean Uninstall** — Drops custom tables, deletes all options and transients, and clears cron events.

## Architecture

```
includes/
├── contracts/
│   ├── interface-debugwp-plugin-provider.php   # Provider interface
│   └── class-debugwp-plugin-provider-base.php  # Abstract base class
├── providers/
│   ├── crawlwp/       # CrawlWP provider + license logger + reader
│   ├── cyclesave/     # CycleSave provider + reader
│   ├── fusewp/        # FuseWP provider + logger + reader
│   ├── mailoptin/     # MailOptin provider + reader
│   └── profilepress/  # ProfilePress provider + logger + reader + Stripe HTTP client
├── class-debugwp.php              # Core singleton & provider registry
├── class-debugwp-db.php           # Database table & migrations (v1.1)
├── class-debugwp-settings.php     # Admin menus & settings
├── class-debugwp-log-viewer.php   # WP_List_Table log viewer
├── class-debugwp-cli.php          # WP-CLI commands
├── class-debugwp-dashboard-widget.php  # Dashboard widget
├── class-debugwp-environment.php  # Environment info panel
├── class-debugwp-site-health.php  # Site Health tests
├── class-debugwp-mail-logger.php  # wp_mail() capture
├── class-debugwp-webhook-logger.php   # Incoming webhook logging
├── class-debugwp-cron.php         # Cron scheduling & email alerts
├── class-debugwp-cron-logger.php  # Cron error catching
└── class-debugwp-cron-ui.php      # Cron status admin page
```

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Installation

1. Upload the `debugwp` folder to `wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **DebugWP → Settings** to enable debug mode for your plugins.
4. View captured logs under **DebugWP → Log Viewer**.

## License

GPLv2 or later.
