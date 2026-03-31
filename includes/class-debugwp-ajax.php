<?php
/**
 * AJAX handlers — delete, bulk delete, export CSV, fetch detail context.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Ajax {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;

        add_action( 'wp_ajax_debugwp_delete_log', [ $this, 'delete_log' ] );
        add_action( 'wp_ajax_debugwp_delete_all_logs', [ $this, 'delete_all_logs' ] );
        add_action( 'wp_ajax_debugwp_export_csv', [ $this, 'export_csv' ] );
        add_action( 'wp_ajax_debugwp_get_context', [ $this, 'get_context' ] );
        add_action( 'wp_ajax_debugwp_bulk_delete', [ $this, 'bulk_delete' ] );
    }

    /* ── Security check ──────────────────────────────────── */

    private function verify() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        if ( ! check_ajax_referer( 'debugwp_nonce', '_wpnonce', false ) && ! check_ajax_referer( 'debugwp_nonce', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce.' );
        }
    }

    /* ── Delete single log ───────────────────────────────── */

    public function delete_log() {
        $this->verify();

        global $wpdb;
        $id = absint( $_POST['log_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $wpdb->delete( $wpdb->prefix . 'debugwp_logs', [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        wp_send_json_success();
    }

    /* ── Bulk delete ─────────────────────────────────────── */

    public function bulk_delete() {
        $this->verify();

        global $wpdb;
        $ids = array_map( 'absint', (array) ( $_POST['log_ids'] ?? [] ) );
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No IDs provided.' );
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}debugwp_logs WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ids
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        wp_send_json_success( [ 'deleted' => count( $ids ) ] );
    }

    /* ── Delete all logs ─────────────────────────────────── */

    public function delete_all_logs() {
        $this->verify();

        global $wpdb;
        $plugin = sanitize_key( $_POST['plugin_slug'] ?? '' );

        if ( $plugin ) {
            $wpdb->delete( $wpdb->prefix . 'debugwp_logs', [ 'plugin_slug' => $plugin ], [ '%s' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}debugwp_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        wp_send_json_success();
    }

    /* ── Get context JSON for detail expansion ───────────── */

    public function get_context() {
        $this->verify();

        global $wpdb;
        $id = absint( $_GET['log_id'] ?? $_POST['log_id'] ?? 0 );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid ID.' );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT context FROM {$wpdb->prefix}debugwp_logs WHERE id = %d",
            $id
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( ! $row ) {
            wp_send_json_error( 'Not found.' );
        }

        wp_send_json_success( [
            'context' => json_decode( $row->context, true ) ?: $row->context,
        ] );
    }

    /* ── Export to CSV ───────────────────────────────────── */

    public function export_csv() {
        // Verify nonce from URL parameter.
        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'debugwp_nonce' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'debugwp_logs';

        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 10000", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $filename = 'debugwp-logs-' . gmdate( 'Y-m-d-His' ) . '.csv';

        // Send headers.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );

        $output = fopen( 'php://output', 'w' );

        // Header row.
        fputcsv( $output, [ 'ID', 'Plugin', 'Type', 'Severity', 'Message', 'Context', 'Created At' ] );

        foreach ( $rows as $row ) {
            fputcsv( $output, [
                $row['id'],
                $row['plugin_slug'],
                $row['log_type'],
                $row['severity'],
                $row['message'],
                $row['context'],
                $row['created_at'],
            ] );
        }

        fclose( $output );
        exit;
    }
}
