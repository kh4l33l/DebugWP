<?php
/**
 * Native log reader for CrawlWP.
 *
 * Reads CrawlWP's own log table: {prefix}crawlwp_log.
 * Columns: log_id, created_at (UTC datetime), level (PSR level), search_engine,
 *          direction, status_code, message.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_CrawlWP {

    /** PSR / CrawlWP levels mapped to DebugWP severities. */
    private const LEVEL_MAP = [
        'emergency' => 'error',
        'alert'     => 'error',
        'critical'  => 'error',
        'error'     => 'error',
        'warning'   => 'warning',
        'notice'    => 'info',
        'info'      => 'info',
        'debug'     => 'info',
    ];

    /**
     * Returns a user-facing notice when the CrawlWP log table is absent.
     * An empty string means the table is accessible.
     */
    public static function get_unavailable_notice(): string {
        global $wpdb;

        $table = $wpdb->prefix . 'crawlwp_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $exists !== $table ) {
            return 'CrawlWP log table not found. Activate CrawlWP (Mihdan Index Now) to generate logs.';
        }

        return '';
    }

    /**
     * Read CrawlWP logs, newest first.
     *
     * @param int $limit Maximum entries to return.
     * @return array Normalized entries: { datetime, severity, message, source }.
     */
    public static function get_logs( int $limit = 200 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'crawlwp_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT log_id, created_at, level, search_engine, direction, status_code, message
                 FROM {$table} ORDER BY log_id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $entries = [];
        foreach ( $rows as $row ) {
            $level    = strtolower( (string) $row['level'] );
            $severity = self::LEVEL_MAP[ $level ] ?? 'info';

            // CrawlWP stores created_at in UTC.
            $ts       = strtotime( (string) $row['created_at'] );
            $datetime = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $row['created_at'];

            // Build a source label from the engine + direction + HTTP status.
            $source_parts = [];
            if ( ! empty( $row['search_engine'] ) ) {
                $source_parts[] = $row['search_engine'];
            }
            if ( ! empty( $row['direction'] ) ) {
                $source_parts[] = $row['direction'];
            }
            $source = $source_parts ? implode( ' / ', $source_parts ) : 'crawlwp';

            $message = (string) $row['message'];
            if ( ! empty( $row['status_code'] ) && (int) $row['status_code'] !== 200 ) {
                $message .= sprintf( ' (HTTP %d)', (int) $row['status_code'] );
            }

            $entries[] = [
                'datetime' => $datetime,
                'severity' => $severity,
                'message'  => $message,
                'source'   => $source,
            ];
        }

        return $entries;
    }
}
