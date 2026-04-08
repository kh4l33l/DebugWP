<?php
/**
 * Log Viewer — WP_List_Table-based admin page with filtering, pagination, and detail expansion.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class DebugWP_Log_Viewer extends WP_List_Table {

    /** @var DebugWP */
    private $core;

    public function __construct( DebugWP $core ) {
        $this->core = $core;

        parent::__construct( [
            'singular' => 'log',
            'plural'   => 'logs',
            'ajax'     => false,
        ] );
    }

    /* ── Columns ─────────────────────────────────────────── */

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'created_at'  => 'Time',
            'plugin_slug' => 'Plugin',
            'log_type'    => 'Type',
            'severity'    => 'Severity',
            'message'     => 'Message',
            'hit_count'   => 'Hits',
            'actions'     => 'Actions',
        ];
    }

    public function get_sortable_columns() {
        return [
            'created_at'  => [ 'created_at', true ],
            'plugin_slug' => [ 'plugin_slug', false ],
            'severity'    => [ 'severity', false ],
        ];
    }

    /* ── Query ───────────────────────────────────────────── */

    public function prepare_items() {
        global $wpdb;

        $table    = $wpdb->prefix . 'debugwp_logs';
        $per_page = $this->get_items_per_page( 'debugwp_logs_per_page', 20 );
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification

        $where  = [];
        $params = [];

        // Plugin filter.
        if ( ! empty( $_GET['plugin_slug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $where[]  = 'plugin_slug = %s';
            $params[] = sanitize_key( wp_unslash( $_GET['plugin_slug'] ) );
        }

        // Type filter.
        if ( ! empty( $_GET['log_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $where[]  = 'log_type = %s';
            $params[] = sanitize_key( wp_unslash( $_GET['log_type'] ) );
        }

        // Severity filter.
        if ( ! empty( $_GET['severity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $where[]  = 'severity = %s';
            $params[] = sanitize_key( wp_unslash( $_GET['severity'] ) );
        }

        // Search (message + context).
        if ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $search_term = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
            $where[]  = '(message LIKE %s OR context LIKE %s)';
            $params[] = $search_term;
            $params[] = $search_term;
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Sorting.
        $allowed_order = [ 'created_at', 'plugin_slug', 'severity' ];
        $orderby       = in_array( ( $_GET['orderby'] ?? '' ), $allowed_order, true ) ? sanitize_sql_orderby( $_GET['orderby'] ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification
        $order         = ( strtoupper( $_GET['order'] ?? '' ) === 'ASC' ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

        // Total count.
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        // Fetch rows.
        $offset   = ( $paged - 1 ) * $per_page;
        $data_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $all_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        $this->items = $rows ?: [];

        $this->set_pagination_args( [
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        ] );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    /* ── Column renderers ────────────────────────────────── */

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', (int) $item['id'] );
    }

    public function column_created_at( $item ) {
        return '<span class="debugwp-datetime" title="' . esc_attr( $item['created_at'] ) . '">'
            . esc_html( wp_date( 'M j, g:i:s A', strtotime( $item['created_at'] ) ) )
            . '</span>';
    }

    public function column_plugin_slug( $item ) {
        $plugins = $this->core->get_supported_plugins();
        $label   = $plugins[ $item['plugin_slug'] ]['label'] ?? $item['plugin_slug'];
        return '<span class="debugwp-plugin debugwp-plugin-' . esc_attr( $item['plugin_slug'] ) . '">' . esc_html( $label ) . '</span>';
    }

    public function column_log_type( $item ) {
        $labels = [
            'http_request'     => 'HTTP Request',
            'php_error'        => 'PHP Error',
            'plugin_native'    => 'Plugin Log',
            'webhook_incoming' => 'Incoming Webhook',
            'stripe_api'       => 'Stripe API',
            'email'            => 'Email',
            'cron'             => 'Cron',
        ];
        return esc_html( $labels[ $item['log_type'] ] ?? $item['log_type'] );
    }

    public function column_hit_count( $item ) {
        $count = (int) ( $item['hit_count'] ?? 1 );
        if ( $count <= 1 ) {
            return '<span class="debugwp-hit-count">1</span>';
        }
        $last = ! empty( $item['last_seen'] ) && $item['last_seen'] !== '0000-00-00 00:00:00'
            ? wp_date( 'M j, g:i:s A', strtotime( $item['last_seen'] ) )
            : '';
        $title = $last ? sprintf( 'Last seen: %s', $last ) : '';
        return '<span class="debugwp-hit-count debugwp-hit-count-high" title="' . esc_attr( $title ) . '">' . esc_html( $count ) . '×</span>';
    }

    public function column_severity( $item ) {
        return '<span class="debugwp-severity debugwp-severity-' . esc_attr( $item['severity'] ) . '">'
            . esc_html( ucfirst( $item['severity'] ) )
            . '</span>';
    }

    public function column_message( $item ) {
        $message = esc_html( mb_substr( $item['message'], 0, 200 ) );
        if ( mb_strlen( $item['message'] ) > 200 ) {
            $message .= '&hellip;';
        }

        return '<span class="debugwp-message">' . $message . '</span>';
    }

    public function column_actions( $item ) {
        $buttons = '';

        $has_context = ! empty( $item['context'] ) && $item['context'] !== 'null';
        if ( $has_context ) {
            $buttons .= '<button type="button" class="button button-small debugwp-toggle-detail" data-id="' . (int) $item['id'] . '">Details</button> ';
        }

        $buttons .= '<button type="button" class="button button-small debugwp-delete-single" data-id="' . (int) $item['id'] . '" title="Delete">Delete</button>';

        return $buttons;
    }

    public function column_default( $item, $name ) {
        return esc_html( $item[ $name ] ?? '' );
    }

    /* ── Bulk actions ────────────────────────────────────── */

    public function get_bulk_actions() {
        return [
            'delete'     => 'Delete Selected',
            'delete_all' => 'Delete All Logs',
        ];
    }

    /* ── Filters (above the table) ───────────────────────── */

    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }

        $plugins = $this->core->get_supported_plugins();
        $current_plugin   = sanitize_key( $_GET['plugin_slug'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        $current_type     = sanitize_key( $_GET['log_type'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        $current_severity = sanitize_key( $_GET['severity'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="alignleft actions debugwp-filters">
            <select name="plugin_slug">
                <option value="">All Plugins</option>
                <?php foreach ( $plugins as $slug => $info ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_plugin, $slug ); ?>>
                        <?php echo esc_html( $info['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="log_type">
                <option value="">All Types</option>
                <option value="http_request" <?php selected( $current_type, 'http_request' ); ?>>HTTP Requests</option>
                <option value="php_error" <?php selected( $current_type, 'php_error' ); ?>>PHP Errors</option>
                <option value="plugin_native" <?php selected( $current_type, 'plugin_native' ); ?>>Plugin Logs</option>
                <option value="webhook_incoming" <?php selected( $current_type, 'webhook_incoming' ); ?>>Incoming Webhooks</option>
                <option value="stripe_api" <?php selected( $current_type, 'stripe_api' ); ?>>Stripe API</option>
                <option value="email" <?php selected( $current_type, 'email' ); ?>>Email</option>
                <option value="cron" <?php selected( $current_type, 'cron' ); ?>>Cron</option>
            </select>

            <select name="severity">
                <option value="">All Severities</option>
                <option value="error" <?php selected( $current_severity, 'error' ); ?>>Error</option>
                <option value="warning" <?php selected( $current_severity, 'warning' ); ?>>Warning</option>
                <option value="info" <?php selected( $current_severity, 'info' ); ?>>Info</option>
                <option value="debug" <?php selected( $current_severity, 'debug' ); ?>>Debug</option>
            </select>

            <?php submit_button( 'Filter', '', 'filter_action', false ); ?>

            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=debugwp_export_csv' ), 'debugwp_nonce', '_wpnonce' ) ); ?>" class="button">Export CSV</a>
        </div>
        <?php
    }

    /* ── Full render (wraps table in form + detail rows via JS) ── */

    public function render() {
        // Also show native plugin logs if enabled.
        $native_tab = sanitize_key( $_GET['native'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        $enabled    = $this->core->get_enabled_slugs();

        echo '<div class="debugwp-viewer-tabs">';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=debugwp-logs' ) ) . '" class="button ' . ( empty( $native_tab ) ? 'button-primary' : '' ) . '">Captured Logs</a> ';

        foreach ( $enabled as $slug ) {
            $plugins = $this->core->get_supported_plugins();
            $label   = $plugins[ $slug ]['label'] ?? $slug;
            $url     = admin_url( 'admin.php?page=debugwp-logs&native=' . $slug );
            $active  = $native_tab === $slug ? 'button-primary' : '';
            echo '<a href="' . esc_url( $url ) . '" class="button ' . esc_attr( $active ) . '">' . esc_html( $label ) . ' Native Logs</a> ';
        }

        // WP Debug Log tab — always visible; reads wp-content/debug.log.
        $debug_url    = admin_url( 'admin.php?page=debugwp-logs&native=debug_log' );
        $debug_active = $native_tab === 'debug_log' ? 'button-primary' : '';
        echo '<a href="' . esc_url( $debug_url ) . '" class="button ' . esc_attr( $debug_active ) . '">WP Debug Log</a> ';

        echo '</div>';

        if ( ! empty( $native_tab ) ) {
            $this->render_native_logs( $native_tab );
            return;
        }

        echo '<div id="debugwp-table-wrap">';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="debugwp-logs" />';
        $this->search_box( 'Search Logs', 'debugwp_search' );
        $this->display();
        echo '</form>';
        echo '</div>';

        // Hidden template for detail expansion.
        echo '<script type="text/html" id="tmpl-debugwp-detail-row">';
        echo '<tr class="debugwp-detail-row"><td colspan="9"><pre class="debugwp-context">{{data.context}}</pre></td></tr>';
        echo '</script>';
    }

    /**
     * Render native plugin log entries with sorting and pagination.
     */
    private function render_native_logs( $slug ) {
        // Build the reader map dynamically from registered providers.
        $readers = [ 'debug_log' => 'DebugWP_Reader_Debug_Log' ];
        foreach ( $this->core->get_providers() as $provider_slug => $provider ) {
            $reader_class = $provider->get_reader_class();
            if ( $reader_class ) {
                $readers[ $provider_slug ] = $reader_class;
            }
        }

        if ( ! isset( $readers[ $slug ] ) ) {
            echo '<p>No native reader for this plugin.</p>';
            return;
        }

        $entries = call_user_func( [ $readers[ $slug ], 'get_logs' ], 500 );

        if ( empty( $entries ) ) {
            $notice = '';
            if ( method_exists( $readers[ $slug ], 'get_unavailable_notice' ) ) {
                $notice = call_user_func( [ $readers[ $slug ], 'get_unavailable_notice' ] );
            }
            if ( $notice ) {
                echo '<div class="debugwp-empty-state notice notice-warning inline"><p>' . esc_html( $notice ) . '</p></div>';
            } else {
                echo '<div class="debugwp-empty-state"><p>No native logs found for this plugin.</p></div>';
            }
            return;
        }

        // ── Sorting ──
        $allowed_sort = [ 'datetime', 'severity', 'source', 'message' ];
        $orderby      = in_array( ( $_GET['orderby'] ?? '' ), $allowed_sort, true ) ? $_GET['orderby'] : 'datetime'; // phpcs:ignore WordPress.Security.NonceVerification
        $order        = ( strtoupper( $_GET['order'] ?? '' ) === 'ASC' ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

        usort( $entries, function ( $a, $b ) use ( $orderby, $order ) {
            $cmp = strnatcasecmp( $a[ $orderby ] ?? '', $b[ $orderby ] ?? '' );
            return $order === 'ASC' ? $cmp : -$cmp;
        } );

        // ── Pagination ──
        $per_page    = (int) get_user_option( 'debugwp_logs_per_page' ) ?: 20;
        $total       = count( $entries );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $paged       = max( 1, min( absint( $_GET['paged'] ?? 1 ), $total_pages ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $offset      = ( $paged - 1 ) * $per_page;
        $page_entries = array_slice( $entries, $offset, $per_page );

        $base_url = admin_url( 'admin.php?page=debugwp-logs&native=' . urlencode( $slug ) );

        // Helper to build a sort URL for a column header.
        $sort_url = function ( $col ) use ( $base_url, $orderby, $order ) {
            $new_order = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
            return esc_url( add_query_arg( [
                'orderby' => $col,
                'order'   => $new_order,
            ], $base_url ) );
        };

        $sort_class = function ( $col ) use ( $orderby, $order ) {
            if ( $orderby !== $col ) {
                return 'sortable desc';
            }
            return 'sorted ' . strtolower( $order );
        };

        // ── Controls bar ──
        echo '<div id="debugwp-native-wrap">';
        echo '<div class="debugwp-native-controls">';
        echo '<span class="debugwp-native-count">' . esc_html( number_format_i18n( $total ) ) . ' entries</span>';
        echo '</div>';

        // ── Table ──
        echo '<table class="wp-list-table widefat fixed striped debugwp-native-table">';
        echo '<thead><tr>';

        $columns = [
            'datetime' => 'Time',
            'severity' => 'Severity',
            'source'   => 'Source',
            'message'  => 'Message',
        ];
        foreach ( $columns as $col => $label ) {
            $cls      = esc_attr( $sort_class( $col ) );
            $aria_sort = ( $orderby === $col ) ? ( $order === 'ASC' ? 'ascending' : 'descending' ) : 'none';
            echo '<th class="' . $cls . '" aria-sort="' . $aria_sort . '">';
            echo '<a href="' . $sort_url( $col ) . '"><span>' . esc_html( $label ) . '</span>';
            echo '<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>';
            echo '</a></th>';
        }

        echo '</tr></thead><tbody>';

        foreach ( $page_entries as $entry ) {
            $sev = esc_attr( $entry['severity'] );
            echo '<tr>';
            echo '<td>' . esc_html( $entry['datetime'] ) . '</td>';
            echo '<td><span class="debugwp-severity debugwp-severity-' . $sev . '">' . esc_html( ucfirst( $entry['severity'] ) ) . '</span></td>';
            echo '<td>' . esc_html( $entry['source'] ) . '</td>';
            echo '<td><span class="debugwp-message">' . esc_html( mb_substr( $entry['message'], 0, 500 ) ) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // ── Pagination nav ──
        if ( $total_pages > 1 ) {
            echo '<div class="debugwp-native-pagination">';
            echo '<span class="debugwp-native-page-info">Page ' . $paged . ' of ' . $total_pages . '</span>';

            $page_links = [];

            // First / Prev.
            if ( $paged > 1 ) {
                $page_links[] = '<a class="first-page button" href="' . esc_url( add_query_arg( [ 'paged' => 1, 'orderby' => $orderby, 'order' => $order ], $base_url ) ) . '">&laquo;</a>';
                $page_links[] = '<a class="prev-page button" href="' . esc_url( add_query_arg( [ 'paged' => $paged - 1, 'orderby' => $orderby, 'order' => $order ], $base_url ) ) . '">&lsaquo;</a>';
            } else {
                $page_links[] = '<span class="button disabled">&laquo;</span>';
                $page_links[] = '<span class="button disabled">&lsaquo;</span>';
            }

            // Page input.
            $page_links[] = '<input class="current-page debugwp-native-page-input" type="text" size="2" value="' . $paged . '" data-total="' . $total_pages . '" />';

            // Next / Last.
            if ( $paged < $total_pages ) {
                $page_links[] = '<a class="next-page button" href="' . esc_url( add_query_arg( [ 'paged' => $paged + 1, 'orderby' => $orderby, 'order' => $order ], $base_url ) ) . '">&rsaquo;</a>';
                $page_links[] = '<a class="last-page button" href="' . esc_url( add_query_arg( [ 'paged' => $total_pages, 'orderby' => $orderby, 'order' => $order ], $base_url ) ) . '">&raquo;</a>';
            } else {
                $page_links[] = '<span class="button disabled">&rsaquo;</span>';
                $page_links[] = '<span class="button disabled">&raquo;</span>';
            }

            echo implode( ' ', $page_links );
            echo '</div>';
        }

        echo '</div>'; // #debugwp-native-wrap
    }
}
