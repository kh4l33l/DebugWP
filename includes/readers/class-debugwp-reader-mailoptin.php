<?php
/**
 * Native log reader for MailOptin.
 *
 * Reads log files from:
 *   - wp-content/uploads/mailoptin-optin-log/   (optin errors)
 *   - wp-content/uploads/mailoptin-campaign-log/ (campaign errors)
 *
 * Format per line: "2026-03-31 14:23:45: Error message here\r\n"
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_MailOptin {

    /**
     * Get normalized log entries.
     *
     * @param int $limit Max entries to return.
     * @return array [ [ 'datetime' => string, 'severity' => string, 'message' => string, 'source' => string ], ... ]
     */
    public static function get_logs( $limit = 200 ) {
        $entries = [];
        $dirs    = [
            'optin'    => WP_CONTENT_DIR . '/uploads/mailoptin-optin-log/',
            'campaign' => WP_CONTENT_DIR . '/uploads/mailoptin-campaign-log/',
        ];

        foreach ( $dirs as $type => $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $files = glob( $dir . '*.log' );
            if ( empty( $files ) ) {
                continue;
            }

            foreach ( $files as $file ) {
                $contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ( empty( $contents ) ) {
                    continue;
                }

                // Split by \r\n\r\n (campaign) or \r\n (optin).
                $lines = preg_split( '/\r?\n\r?\n?/', $contents, -1, PREG_SPLIT_NO_EMPTY );

                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( empty( $line ) ) {
                        continue;
                    }

                    // Parse "datetime: message" format.
                    if ( preg_match( '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}):\s*(.+)$/s', $line, $m ) ) {
                        $entries[] = [
                            'datetime' => $m[1],
                            'severity' => 'error',
                            'message'  => $m[2],
                            'source'   => $type . ' (' . basename( $file, '.log' ) . ')',
                        ];
                    }
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
