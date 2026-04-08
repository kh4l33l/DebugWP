<?php
/**
 * Native log reader for CycleSave.
 *
 * Reads from the `cyclesave_error_log` option (array of entries).
 * Each entry: [ 'date' => 'Y-m-d H:i:s', 'source' => 'stripe', 'message' => '...' ]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_Reader_CycleSave {

    /**
     * @param int $limit
     * @return array Normalized entries.
     */
    public static function get_logs( $limit = 200 ) {
        $raw = get_option( 'cyclesave_error_log', [] );

        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return [];
        }

        $entries = [];
        foreach ( $raw as $entry ) {
            if ( empty( $entry['message'] ) ) {
                continue;
            }

            $entries[] = [
                'datetime' => $entry['date'] ?? '',
                'severity' => 'error',
                'message'  => $entry['message'],
                'source'   => $entry['source'] ?? 'unknown',
            ];
        }

        // CycleSave stores newest last; reverse for newest-first.
        $entries = array_reverse( $entries );

        return array_slice( $entries, 0, $limit );
    }
}
