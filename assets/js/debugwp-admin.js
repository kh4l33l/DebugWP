/**
 * DebugWP Admin JS — detail expansion, AJAX actions, confirmations.
 */
(function ($) {
    'use strict';

    if (typeof debugwp === 'undefined') {
        return;
    }

    /* ── Detail row toggle ───────────────────────────────── */

    $(document).on('click', '.debugwp-toggle-detail', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var id   = $btn.data('id');
        var $row = $btn.closest('tr');
        var $detail = $row.next('.debugwp-detail-row');

        // If already open, close it.
        if ($detail.length && $detail.data('log-id') === id) {
            $detail.remove();
            $btn.text('Details');
            return;
        }

        // Remove any other open detail.
        $row.siblings('.debugwp-detail-row').remove();

        $btn.text('Loading…').prop('disabled', true);

        $.ajax({
            url: debugwp.ajax_url,
            method: 'POST',
            data: {
                action: 'debugwp_get_context',
                nonce:  debugwp.nonce,
                log_id: id,
            },
            success: function (res) {
                $btn.text('Hide').prop('disabled', false);

                if (!res.success) {
                    return;
                }

                var context = res.data.context;
                var html    = buildDetailPanel(id, context);

                $row.after(html);

                // Activate first tab.
                var $inserted = $row.next('.debugwp-detail-row');
                $inserted.find('.debugwp-tab-btn:first').trigger('click');
            },
            error: function () {
                $btn.text('Details').prop('disabled', false);
            },
        });
    });

    /**
     * Build the tabbed detail panel HTML.
     */
    function buildDetailPanel(id, context) {
        var isObj     = typeof context === 'object' && context !== null;
        var hasSummary = isObj && (context.html_summary || context.api_error);
        var hasHtml   = isObj && context.response_body && isHtmlBody(context.response_body);
        var hasText   = isObj && context.html_body;

        // Build tabs.
        var tabs = '';
        var panels = '';

        // Summary tab — shows decoded error info prominently.
        if (hasSummary || hasText) {
            tabs += '<button type="button" class="debugwp-tab-btn" data-tab="summary">Summary</button>';
            var summaryContent = '';

            if (isObj && context.html_summary) {
                summaryContent += '<div class="debugwp-summary-banner debugwp-summary-' + severityFromStatus(context.status_code) + '">'
                    + escapeHtml(context.html_summary) + '</div>';
            }
            if (isObj && context.api_error) {
                summaryContent += '<div class="debugwp-summary-banner debugwp-summary-warning">'
                    + escapeHtml(context.api_error) + '</div>';
            }

            // Cloudflare identifiers — highlighted badges.
            if (isObj && (context.cf_ray_id || context.cf_zone)) {
                summaryContent += '<div class="debugwp-cf-ids">';
                if (context.cf_ray_id) {
                    summaryContent += '<span class="debugwp-cf-badge debugwp-cf-ray">Ray ID: <strong>' + escapeHtml(context.cf_ray_id) + '</strong></span> ';
                }
                if (context.cf_zone) {
                    summaryContent += '<span class="debugwp-cf-badge debugwp-cf-zone">Zone: <strong>' + escapeHtml(context.cf_zone) + '</strong></span>';
                }
                summaryContent += '<p class="description">Provide the Ray ID to Cloudflare support or use it in the Cloudflare dashboard under Security &gt; Events to find the exact firewall rule that blocked this request.</p>';
                summaryContent += '</div>';
            }

            // Request metadata.
            if (isObj && (context.url || context.method || context.status_code || context.backtrace)) {
                summaryContent += '<div class="debugwp-summary-meta">';
                if (context.url) {
                    summaryContent += '<strong>URL:</strong> ' + escapeHtml(context.url) + '<br>';
                }
                if (context.method) {
                    summaryContent += '<strong>Method:</strong> ' + escapeHtml(context.method) + '<br>';
                }
                if (context.status_code) {
                    summaryContent += '<strong>Status:</strong> ' + context.status_code + '<br>';
                }
                if (context.backtrace && context.backtrace.length) {
                    summaryContent += '<strong>Call Stack:</strong><br>';
                    for (var i = 0; i < context.backtrace.length; i++) {
                        summaryContent += '  ' + escapeHtml(context.backtrace[i]) + '<br>';
                    }
                }
                summaryContent += '</div>';
            }

            if (hasText && !context.html_summary) {
                summaryContent += '<h4>Page Content (text)</h4><pre class="debugwp-text-body">' + escapeHtml(context.html_body) + '</pre>';
            }

            panels += '<div class="debugwp-tab-panel" data-tab="summary">' + summaryContent + '</div>';
        }

        // JSON tab — always shown.
        tabs += '<button type="button" class="debugwp-tab-btn" data-tab="json">Raw JSON</button>';
        var jsonStr = isObj ? JSON.stringify(context, null, 2) : String(context);
        panels += '<div class="debugwp-tab-panel" data-tab="json"><pre class="debugwp-context">' + escapeHtml(jsonStr) + '</pre></div>';

        // HTML Preview tab — sandboxed iframe rendering the actual HTML.
        // Skip when a decoded summary exists (e.g. Cloudflare challenge pages
        // require JS to render, so the sandboxed iframe only shows "Enable JavaScript…").
        if (hasHtml && !context.html_summary) {
            tabs += '<button type="button" class="debugwp-tab-btn" data-tab="preview">HTML Preview</button>';
            panels += '<div class="debugwp-tab-panel" data-tab="preview">'
                + '<p class="description">Sandboxed render of the HTML response. No scripts are executed.</p>'
                + '<iframe class="debugwp-html-preview" sandbox="" srcdoc="' + escapeAttr(context.response_body) + '"></iframe>'
                + '</div>';
        }

        return '<tr class="debugwp-detail-row" data-log-id="' + id + '">'
            + '<td colspan="6">'
            + '<div class="debugwp-detail-tabs">' + tabs + '</div>'
            + '<div class="debugwp-detail-panels">' + panels + '</div>'
            + '</td></tr>';
    }

    /**
     * Tab switching.
     */
    $(document).on('click', '.debugwp-tab-btn', function () {
        var $btn   = $(this);
        var tab    = $btn.data('tab');
        var $row   = $btn.closest('.debugwp-detail-row');

        $row.find('.debugwp-tab-btn').removeClass('active');
        $btn.addClass('active');

        $row.find('.debugwp-tab-panel').hide();
        $row.find('.debugwp-tab-panel[data-tab="' + tab + '"]').show();
    });

    /* ── Helpers ──────────────────────────────────────────── */

    function isHtmlBody(str) {
        return typeof str === 'string' && (str.indexOf('<html') !== -1 || str.indexOf('<!DOCTYPE') !== -1 || str.indexOf('<!doctype') !== -1);
    }

    function severityFromStatus(code) {
        if (code >= 500) return 'error';
        if (code >= 400) return 'warning';
        return 'info';
    }

    function escapeHtml(str) {
        return $('<span>').text(str).html();
    }

    function escapeAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ── Delete single log ───────────────────────────────── */

    $(document).on('click', '.debugwp-delete-log', function (e) {
        e.preventDefault();

        if (!confirm('Delete this log entry?')) {
            return;
        }

        var $btn = $(this);
        var id = $btn.data('id');

        $.post(debugwp.ajax_url, {
            action: 'debugwp_delete_log',
            nonce:  debugwp.nonce,
            log_id: id,
        }, function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () {
                    $(this).next('.debugwp-detail-row').remove();
                    $(this).remove();
                });
            }
        });
    });

    /* ── Bulk actions (intercept form submit for delete_all) ── */

    $(document).on('submit', '.debugwp-wrap form', function (e) {
        var action = $(this).find('select[name="action"]').val() ||
                     $(this).find('select[name="action2"]').val();

        if (action === 'delete_all') {
            e.preventDefault();

            if (!confirm('Delete ALL log entries? This cannot be undone.')) {
                return;
            }

            $.post(debugwp.ajax_url, {
                action: 'debugwp_delete_all_logs',
                nonce:  debugwp.nonce,
            }, function (res) {
                if (res.success) {
                    location.reload();
                }
            });
            return;
        }

        if (action === 'delete') {
            e.preventDefault();

            var ids = [];
            $('input[name="log_ids[]"]:checked').each(function () {
                ids.push($(this).val());
            });

            if (!ids.length) {
                alert('No entries selected.');
                return;
            }

            if (!confirm('Delete ' + ids.length + ' selected entries?')) {
                return;
            }

            $.post(debugwp.ajax_url, {
                action:  'debugwp_bulk_delete',
                nonce:   debugwp.nonce,
                log_ids: ids,
            }, function (res) {
                if (res.success) {
                    location.reload();
                }
            });
        }
    });

    /* ── Page number input navigation ───────────────────── */

    /**
     * Captured logs — navigate to a specific page on Enter key.
     */
    $(document).on('keypress', '#debugwp-table-wrap .current-page', function (e) {
        if (e.which !== 13) {
            return;
        }
        e.preventDefault();
        var url = new URL(window.location.href);
        url.searchParams.set('paged', Math.max(1, parseInt($(this).val(), 10) || 1));
        window.location.href = url.toString();
    });

    /**
     * Native logs — navigate to a specific page on Enter key.
     */
    $(document).on('keypress', '.debugwp-native-page-input', function (e) {
        if (e.which !== 13) {
            return;
        }
        e.preventDefault();
        var total = parseInt($(this).data('total'), 10) || 1;
        var page  = Math.max(1, Math.min(parseInt($(this).val(), 10) || 1, total));
        var url   = new URL(window.location.href);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    });

})(jQuery);
