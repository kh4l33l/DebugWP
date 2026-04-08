<?php
/**
 * Native log reader for ProfilePress / WP User Avatar.
 *
 * Reads log files from: wp-content/uploads/profilepress-logs/
 * File format: {type}-{token}.log
 * Line format: "2026-03-31 14:23:45 - Error message\r\n\r\n"
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_ProfilePress {

    /**
     * Returns a user-facing notice when the ProfilePress log directory does not exist.
     * An empty string means the directory is present (logs may still be empty).
     */
    public static function get_unavailable_notice(): string {
        $log_dir = WP_CONTENT_DIR . '/uploads/profilepress-logs/';
        if ( ! is_dir( $log_dir ) ) {
            return 'ProfilePress has not written any logs yet. To populate this tab, enable "Debug Logging" in ProfilePress → Settings → Advanced, then reproduce the issue.';
        }
        return '';
    }

    /**
     * @param int $limit
     * @return array Normalized entries.
     */
    public static function get_logs( $limit = 200 ) {
        $log_dir = WP_CONTENT_DIR . '/uploads/profilepress-logs/';

        if ( ! is_dir( $log_dir ) ) {
            return [];
        }

        $files = glob( $log_dir . '*.log' );
        if ( empty( $files ) ) {
            return [];
        }

        $entries = [];
        foreach ( $files as $file ) {
            $contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( empty( $contents ) ) {
                continue;
            }

            // Derive log type from filename: "debug-5c2f6a9b.log" → "debug".
            $basename = basename( $file, '.log' );
            $type     = preg_replace( '/-[a-f0-9]+$/i', '', $basename );

            // Split by double line breaks.
            $blocks = preg_split( '/\r?\n\r?\n/', $contents, -1, PREG_SPLIT_NO_EMPTY );

            foreach ( $blocks as $block ) {
                $block = trim( $block );
                if ( empty( $block ) ) {
                    continue;
                }

                // Format: "datetime - message"
                if ( preg_match( '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*-\s*(.+)$/s', $block, $m ) ) {
                    $entries[] = [
                        'datetime' => $m[1],
                        'severity' => 'error',
                        'message'  => $m[2],
                        'source'   => $type,
                    ];
                }
            }
        }

        // Sort newest first.
        usort( $entries, function ( $a, $b ) {
            return strcmp( $b['datetime'], $a['datetime'] );
        } );

        return array_slice( $entries, 0, $limit );
    }
}
