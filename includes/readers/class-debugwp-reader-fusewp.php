<?php
/**
 * Native log reader for FuseWP.
 *
 * Reads sync error logs from FuseWP's own database table: {prefix}fusewp_sync_log.
 * Columns: id, error_message, integration, date (UTC datetime).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_FuseWP {

    /**
     * Returns a user-facing notice when the FuseWP sync log table is absent.
     * An empty string means the table is accessible.
     */
    public static function get_unavailable_notice(): string {
        global $wpdb;

        $table = $wpdb->prefix . 'fusewp_sync_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $exists !== $table ) {
            return 'FuseWP sync log table not found. Activate FuseWP and run at least one sync to generate logs.';
        }

        return '';
    }

    /**
     * Read FuseWP sync error logs, newest first.
     *
     * @param int $limit Maximum entries to return.
     * @return array Normalized entries: { datetime, severity, message, source }.
     */
    public static function get_logs( int $limit = 200 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'fusewp_sync_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, error_message, integration, date FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $entries = [];
        foreach ( $rows as $row ) {
            // FuseWP stores date in UTC; convert to site local time for display consistency.
            $ts       = strtotime( $row['date'] );
            $datetime = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : $row['date'];

            $source = ! empty( $row['integration'] ) ? $row['integration'] : 'sync';

            $entries[] = [
                'datetime' => $datetime,
                'severity' => 'error',
                'message'  => $row['error_message'],
                'source'   => $source,
            ];
        }

        return $entries;
    }
}
