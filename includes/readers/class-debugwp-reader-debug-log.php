<?php
/**
 * Native log reader for wp-content/debug.log.
 *
 * Reads the WordPress debug log and returns only the entries that mention
 * any supported plugin — identified by file path fragments or plugin name
 * strings — with per-entry plugin attribution.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_Debug_Log {

    /**
     * Plugin path/name fragments mapped to their slug.
     * Order matters — first match wins.
     */
    private static function plugin_patterns(): array {
        return [
            'cyclesave'    => [
                'plugins/cyclesave-pro/',
                'plugins/cyclesave/',
                'CycleSave',
            ],
            'profilepress' => [
                'plugins/profilepress-pro/',
                'plugins/wp-user-avatar/',
                'ProfilePress',
                'WP User Avatar',
            ],
            'mailoptin'    => [
                'plugins/mailoptin/',
                'MailOptin',
            ],
            'fusewp'       => [
                'plugins/fusewp-pro/',
                'plugins/fusewp/',
                'FuseWP',
            ],
        ];
    }

    /**
     * Returns a user-facing notice when the debug log is absent or unreadable.
     * An empty string means the file is accessible.
     */
    public static function get_unavailable_notice(): string {
        $path = WP_CONTENT_DIR . '/debug.log';

        if ( ! file_exists( $path ) ) {
            return 'wp-content/debug.log does not exist. To generate it, add define(\'WP_DEBUG\', true) and define(\'WP_DEBUG_LOG\', true) to wp-config.php, then reproduce the error.';
        }

        if ( ! is_readable( $path ) ) {
            return 'wp-content/debug.log exists but cannot be read. Check file permissions on wp-content/debug.log.';
        }

        return '';
    }

    /**
     * Read and parse debug.log, returning only entries relevant to supported plugins.
     *
     * @param int $limit Maximum entries to return (newest first).
     * @return array Normalized entries: { datetime, severity, message, source }.
     */
    public static function get_logs( int $limit = 500 ): array {
        $path = WP_CONTENT_DIR . '/debug.log';

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return [];
        }

        // Read at most the last 2 MB to avoid exhausting memory on large files.
        $max_bytes = 2 * 1024 * 1024;
        $size      = (int) filesize( $path );
        $handle    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        if ( ! $handle ) {
            return [];
        }

        if ( $size > $max_bytes ) {
            fseek( $handle, -$max_bytes, SEEK_END );
            fgets( $handle ); // Discard the first (likely partial) line.
        }

        $content = stream_get_contents( $handle );
        fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

        if ( empty( $content ) ) {
            return [];
        }

        // Each WordPress debug.log entry starts with a timestamp bracket.
        // Split on that boundary so multi-line stack traces stay together.
        $raw_entries = preg_split(
            '/(?=^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\])/m',
            $content,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if ( empty( $raw_entries ) ) {
            return [];
        }

        $entries  = [];
        $patterns = self::plugin_patterns();

        foreach ( $raw_entries as $raw ) {
            $raw = trim( $raw );
            if ( empty( $raw ) ) {
                continue;
            }

            // Identify which plugin this entry belongs to (first match wins).
            $slug = null;
            foreach ( $patterns as $plugin_slug => $fragments ) {
                foreach ( $fragments as $fragment ) {
                    if ( strpos( $raw, $fragment ) !== false ) {
                        $slug = $plugin_slug;
                        break 2;
                    }
                }
            }

            if ( ! $slug ) {
                continue; // Entry does not mention any supported plugin.
            }

            // Parse the leading timestamp: [31-Mar-2026 14:23:45 UTC]
            $datetime = '';
            if ( preg_match( '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}) UTC\]/', $raw, $m ) ) {
                $ts = strtotime( $m[1] );
                if ( $ts ) {
                    $datetime = gmdate( 'Y-m-d H:i:s', $ts );
                }
            }

            // Strip the timestamp prefix for the display message.
            $message = trim( preg_replace( '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} UTC\]\s*/', '', $raw ) );

            $entries[] = [
                'datetime' => $datetime,
                'severity' => self::detect_severity( $message ),
                'message'  => $message,
                'source'   => $slug,
            ];
        }

        // Reverse so newest entries appear first.
        $entries = array_reverse( $entries );

        return array_slice( $entries, 0, $limit );
    }

    /**
     * Map a log message to a severity level based on PHP error type keywords.
     */
    private static function detect_severity( string $message ): string {
        $msg = strtolower( $message );

        if (
            strpos( $msg, 'fatal error' ) !== false ||
            strpos( $msg, 'parse error' ) !== false ||
            strpos( $msg, 'e_error' ) !== false ||
            strpos( $msg, 'e_fatal' ) !== false
        ) {
            return 'error';
        }

        if (
            strpos( $msg, 'warning' ) !== false ||
            strpos( $msg, 'e_warning' ) !== false
        ) {
            return 'warning';
        }

        if (
            strpos( $msg, 'deprecated' ) !== false ||
            strpos( $msg, 'e_deprecated' ) !== false
        ) {
            return 'debug';
        }

        return 'info';
    }
}
