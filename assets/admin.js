jQuery(document).ready(function($) {
    // Keep a copy of all raw errors for grouping
    let allErrors = [];

    // Normalize message (defensive if backend didn't strip timestamp)
    function normalizeMessage(msg) {
        if (typeof msg !== 'string') return '';
        return msg.replace(/^\[[^\]]+\]\s*/, '').trim();
    }

    // Group errors by type + normalized message and count duplicates
    function groupErrors(errors) {
        const groups = {};
        (errors || []).forEach(e => {
            const type = (e.type || 'error').toLowerCase();
            const message = normalizeMessage(e.message || '');
            const key = type + '||' + message;
            if (!groups[key]) {
                groups[key] = {
                    type,
                    message,
                    count: 0,
                    timestamps: []
                };
            }
            groups[key].count += 1;
            if (e.timestamp) {
                groups[key].timestamps.push(e.timestamp);
            }
        });
        return Object.values(groups);
    }

    /**
     * Create and show a toast notification
     */
    function showToast(message, type = 'success', duration = 4000) {
        // Remove any existing toasts
        $('.bugsquasher-toast').remove();

        // Create toast element (align classes with CSS: .bugsquasher-toast.show and .bugsquasher-toast.success|error|info)
        const toast = $(`
            <div class="bugsquasher-toast ${type}">
                <div class="bugsquasher-toast-content">
                    <span class="bugsquasher-toast-icon"></span>
                    <span class="bugsquasher-toast-message">${message}</span>
                    <button class="bugsquasher-toast-close" type="button">&times;</button>
                </div>
            </div>
        `);

        // Add to body
        $('body').append(toast);

        // Trigger animation
        setTimeout(() => toast.addClass('show'), 100);

        // Auto-dismiss
        const timeoutId = setTimeout(() => {
            hideToast(toast);
        }, duration);

        // Manual dismiss
        toast.find('.bugsquasher-toast-close').on('click', () => {
            clearTimeout(timeoutId);
            hideToast(toast);
        });

        return toast;
    }

    /**
     * Hide and remove toast
     */
    function hideToast(toast) {
        toast.removeClass('show');
        setTimeout(() => toast.remove(), 300);
    }

    /**
     * Update error count display using unique and total occurrences
     */
    function updateErrorCount(uniqueCount, totalCount) {
        $('#error-count').text(
            'Found ' + uniqueCount + ' unique errors (' + totalCount + ' total occurrences)'
        );
    }

    /**
     * Escape HTML characters
     */
    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Render grouped errors into the log container
    function renderErrors(grouped) {
        const logContent = $('#log-content');
        if (!grouped || grouped.length === 0) {
            logContent.html('<div class="no-errors"><span class="dashicons dashicons-yes-alt"></span><p>No errors found in the debug log. Great job!</p></div>').show();
            updateErrorCount(0, 0);
            return;
        }

        // total occurrences across all groups
        const total = grouped.reduce((sum, g) => sum + (g.count || 1), 0);

        const html = grouped.map(err => {
            const type = err.type || 'error';
            const safeMsg = escapeHtml(err.message || '');
            const count = err.count || 1;
            // Pick most recent timestamp if we have one
            const ts = (err.timestamps && err.timestamps.length)
                ? escapeHtml(err.timestamps[err.timestamps.length - 1])
                : '';

            // Improved markup for log-entry card
            return `
                <section class="log-entry ${type}" data-type="${type}" data-count="${count}" aria-label="${type} error">
                    <header class="log-entry-header">
                        ${ts ? `<time class="log-entry-timestamp" datetime="${ts}">${ts}</time>` : ''}
                        <span class="log-entry-type ${type}">${type.toUpperCase()}</span>
                        ${count > 1 ? `<span class="log-entry-dup-count" title="Occurrences">×${count}</span>` : ''}
                    </header>
                    <div class="log-entry-message">${safeMsg}</div>
                </section>
            `;
        }).join('');

        logContent.html(html).show();
        updateErrorCount(grouped.length, total);
    }

    // Build UI: render grouped errors, build filters, init filtering + chart
    function displayErrors(errors, errorTypes) {
        const logContent = $('#log-content');
        const filterButtonsContainer = $('#filter-buttons-container');
        const toggleAllButton = $('#toggle-all-filters');

        // Group raw errors
        const grouped = groupErrors(Array.isArray(errors) ? errors : []);

        // Sort for deterministic display (type, then message)
        const groupedSorted = grouped.slice().sort((a, b) => {
            if (a.type === b.type) {
                return (a.message || '').localeCompare(b.message || '');
            }
            return (a.type || '').localeCompare(b.type || '');
        });

        // Render the grouped error list (updates counts)
        renderErrors(groupedSorted);

        // Dynamically create filter buttons
        filterButtonsContainer.empty();
        if (errorTypes && errorTypes.length > 0) {
            errorTypes.forEach(type => {
                const label = (type || '').toString();
                if (!label) return;
                const button = $('<button></button>')
                    .addClass('error-type-filter-btn')
                    .attr('data-type', label)
                    .text(label.charAt(0).toUpperCase() + label.slice(1));
                filterButtonsContainer.append(button);
            });

            // Default active filters
            const defaultActiveFilters = ['fatal', 'parse', 'critical', 'error', 'debug', 'misc'];
            $('.error-type-filter-btn').each(function() {
                const t = $(this).data('type');
                if (defaultActiveFilters.includes(t)) {
                    $(this).addClass('active');
                }
            });

            toggleAllButton.show();
        } else if (groupedSorted.length > 0) {
            filterButtonsContainer.html('<p>No specific error types found to filter by.</p>');
            toggleAllButton.hide();
        } else {
            filterButtonsContainer.html('<p>Load errors to see available filters.</p>');
            toggleAllButton.hide();
        }

        // Apply initial filtering and update chart
        if (groupedSorted.length === 0) {
            logContent.html('<div class="no-errors"><span class="dashicons dashicons-yes-alt"></span><p>No errors found in the debug log. Great job!</p></div>').show();
        } else {
            filterErrors();
        }

        updateSelectAllButtonState();
        renderErrorOverviewChart();
    }

    /**
     * Update debug log status display
     */
    function updateDebugStatus() {
        $.ajax({
            url: bugsquasher_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bugsquasher_get_debug_status',
                nonce: bugsquasher_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.bugsquasher-info').html(response.data);
                }
            },
            error: function() {
                console.error('Failed to update debug status');
            }
        });
    }

    /**
     * Clear debug log
     */
    function clearLog() {
        $.ajax({
            url: bugsquasher_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bugsquasher_clear_log',
                nonce: bugsquasher_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('Debug log cleared successfully!', 'success');
                    loadErrors();
                    updateDebugStatus(); // Refresh the debug log status display
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
            },
            error: function() {
                showToast('Failed to clear debug log.', 'error');
            }
        });
    }

    /**
     * Export errors to a text/markdown file (use grouped counts)
     */
    function exportErrors() {
        const exportFormat = (window.bugsquasher_ajax && bugsquasher_ajax.export_format) || 'detailed';

        // Collect visible grouped entries
        const rows = [];
        $('.log-entry:visible').each(function() {
            const $entry = $(this);
            rows.push({
                type: $entry.find('.log-entry-type').text().trim(),
                timestamp: $entry.find('.log-entry-timestamp').text().trim(),
                message: $entry.find('.log-entry-message').text().trim(),
                count: parseInt($entry.attr('data-count') || '1', 10)
            });
        });

        if (rows.length === 0) {
            showToast('No errors to export.', 'error');
            return;
        }

        // Totals for header
        const totalOccurrences = rows.reduce((sum, r) => sum + (r.count || 1), 0);
        const headerLines = [
            'BugSquasher Error Export',
            'Generated: ' + new Date().toLocaleString(),
            'Total Unique: ' + rows.length,
            'Total Occurrences: ' + totalOccurrences
        ];

        let content = '';
        let filename = 'bugsquasher-errors-' + new Date().toISOString().slice(0, 10);
        const isMarkdown = (exportFormat === 'markdown_table' || exportFormat === 'markdown_list');

        if (exportFormat === 'markdown_table') {
            filename += '.md';
            content += '# ' + headerLines[0] + '\n';
            content += headerLines.slice(1).map(l => '- ' + l).join('\n') + '\n\n';
            content += '| Type | Occurrences | Last Seen | Message |\n';
            content += '|------|------------:|-----------|---------|\n';
            rows.forEach(r => {
                // Escape pipes in message for markdown table safety
                const safeMsg = (r.message || '').replace(/\|/g, '\\|');
                content += `| ${r.type} | ${r.count || 1} | ${r.timestamp || ''} | ${safeMsg} |\n`;
            });
        } else if (exportFormat === 'markdown_list') {
            filename += '.md';
            content += '# ' + headerLines[0] + '\n';
            content += headerLines.slice(1).map(l => '- ' + l).join('\n') + '\n\n';
            rows.forEach(r => {
                const cnt = r.count > 1 ? ` x${r.count}` : '';
                content += `- **${r.type}**${cnt} — ${r.timestamp || ''}\n\n    ${r.message}\n\n`;
            });
        } else {
            // Text formats (detailed | compact)
            filename += '.txt';
            if (exportFormat === 'compact') {
                content += headerLines.join('\n') + '\n';
                content += '================================\n\n';
                rows.forEach(r => {
                    const cnt = r.count > 1 ? ` | x${r.count}` : '';
                    content += `${r.type} | ${r.message}${cnt}\n\n`;
                });
            } else {
                // detailed
                content += headerLines.join('\n') + '\n';
                content += '================================\n\n';
                rows.forEach(r => {
                    const cnt = r.count > 1 ? ` | x${r.count}` : '';
                    content += `${r.type} | ${r.timestamp} | ${r.message}${cnt}\n\n`;
                });
            }
        }

        const blob = new Blob([content], { type: isMarkdown ? 'text/markdown' : 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    // On page load, set the dropdown to the configured default if present
    (function applyDefaultLimit() {
        if (window.bugsquasher_ajax && bugsquasher_ajax.default_limit) {
            var cfg = parseInt(bugsquasher_ajax.default_limit, 10);
            if (!isNaN(cfg)) {
                $('#error-limit').val(cfg.toString());
            }
        }
    })();

    // Always use the selected value, falling back to the settings default
    function getSelectedLimit() {
        var defaultLimit = (window.bugsquasher_ajax && parseInt(bugsquasher_ajax.default_limit, 10)) || 25;
        var selected = $('#error-limit').val();
        // If the dropdown value is not present, use the default
        if (!selected || isNaN(parseInt(selected, 10))) {
            return defaultLimit;
        }
        return parseInt(selected, 10);
    }

    /**
     * Load errors from server
     */
    function loadErrors() {
        console.log('BugSquasher: Starting to load errors...');
        $('#loading').show();
        $('#log-content').hide();

        var limit = getSelectedLimit();
        console.log('BugSquasher: Loading', limit, 'recent errors');

        console.log('BugSquasher: AJAX URL:', bugsquasher_ajax.ajax_url);
        console.log('BugSquasher: Nonce:', bugsquasher_ajax.nonce);

        $.ajax({
            url: bugsquasher_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bugsquasher_get_errors',
                nonce: bugsquasher_ajax.nonce,
                limit: limit
            },
            success: function(response) {
                console.log('BugSquasher: AJAX response received:', response);
                $('#loading').hide();

                if (response.success) {
                    console.log('BugSquasher: Success! Found', response.data.errors.length, 'errors');
                    if (response.data.cached) {
                        console.log('BugSquasher: Results loaded from cache');
                    }

                    // Render + build filters + init counts/chart
                    displayErrors(response.data.errors, response.data.error_types);

                    if (response.data.cached) {
                        $('#error-count').append(' <span style="color: #72aee6;">(cached)</span>');
                    }
                } else {
                    console.error('BugSquasher: Error response:', response.data);
                    $('#log-content').html('<div class="notice notice-error"><p>Error: ' + escapeHtml(response.data) + '</p></div>').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('BugSquasher: AJAX error:', {xhr: xhr, status: status, error: error});
                $('#loading').hide();
                $('#log-content').html('<div class="notice notice-error"><p>Failed to load errors: ' + escapeHtml(error) + '</p></div>').show();
            }
        });
    }

    // Render errors on page load
    loadErrors();

    /**
     * Filter errors by type (uses grouped .log-entry data-count)
     */
    function filterErrors() {
        var checkedTypes = [];
        $('.error-type-filter-btn.active').each(function() {
            checkedTypes.push($(this).data('type'));
        });

        var visibleUnique = 0;
        var visibleOccurrences = 0;
        var totalUnique = 0;
        var totalOccurrences = 0;

        var $entries = $('.log-entry');

        $entries.each(function() {
            var $entry = $(this);
            var entryType = $entry.attr('data-type');
            var count = parseInt($entry.attr('data-count') || '1', 10);

            totalUnique += 1;
            totalOccurrences += count;

            if (checkedTypes.length === 0 || checkedTypes.indexOf(entryType) !== -1) {
                $entry.show();
                visibleUnique += 1;
                visibleOccurrences += count;
            } else {
                $entry.hide();
            }
        });

        // Update count display using occurrences and unique
        if (checkedTypes.length === 0) {
            $('#error-count').text('Select a filter to see results (' + totalUnique + ' unique / ' + totalOccurrences + ' total)');
        } else {
            $('#error-count').text(
                'Showing ' + visibleUnique + ' unique (' + visibleOccurrences + ' total) of ' +
                totalUnique + ' unique (' + totalOccurrences + ' total)'
            );
        }

        updateSelectAllButtonState();
        renderErrorOverviewChart();
    }

    // Chart.js instance holder
    let errorChart = null;

    // Severity order for consistent label ordering
    const TYPE_ORDER = ['fatal','parse','critical','firewall','error','warning','cron','notice','deprecated','debug','info','misc'];

    // Colors per type (aligned with new palette)
    const TYPE_COLORS = {
        fatal: '#d63638',
        parse: '#d63638',
        critical: '#d63638',
        firewall: '#d63638',
        error: '#dba617',
        warning: '#FFB74D',     // updated
        cron: '#dba617',
        notice: '#4FA7A2',      // updated
        deprecated: '#9E9E9E',  // updated
        debug: '#8e44ad',
        info: '#72aee6',
        misc: '#8c8f94',
        default: '#80A1BA'
    };

    // Compute counts by type from currently visible entries (sum data-count)
    function getVisibleTypeCounts() {
        const counts = {};
        const $entries = $('.log-entry:visible');
        if ($entries.length === 0) {
            return counts;
        }
        $entries.each(function() {
            const t = ($(this).attr('data-type') || 'error').toLowerCase();
            const c = parseInt($(this).attr('data-count') || '1', 10);
            counts[t] = (counts[t] || 0) + c;
        });
        return counts;
    }

    // Create or update the bar chart
    function renderErrorOverviewChart() {
        if (typeof Chart === 'undefined') {
            return; // Chart.js not loaded
        }
        const canvas = document.getElementById('errors-bar-chart');
        if (!canvas) {
            return;
        }

        const counts = getVisibleTypeCounts();

        // Order labels by severity and drop zero counts
        const labels = TYPE_ORDER.filter(t => counts[t] > 0);
        const data = labels.map(t => counts[t]);
        const colors = labels.map(t => TYPE_COLORS[t] || TYPE_COLORS.default);

        const ctx = canvas.getContext('2d');

        if (errorChart) {
            errorChart.data.labels = labels;
            errorChart.data.datasets[0].data = data;
            errorChart.data.datasets[0].backgroundColor = colors;
            errorChart.update();
            return;
        }

        errorChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Errors',
                        data,
                        backgroundColor: colors,
                        borderWidth: 0,
                        maxBarThickness: 28,
                        barPercentage: 0.8,
                        categoryPercentage: 0.8
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.parsed.y} ${context.label}`
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#50575e' },
                        grid: {
                            display: true,           // was false; show light gridlines
                            color: '#CDE3E1'         // updated
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#50575e' },
                        grid: {
                            color: '#CDE3E1'        // updated
                        }
                    }
                }
            }
        });
    }

    // Event handlers
    $('#load-errors').on('click', loadErrors);
    $('#clear-log').on('click', clearLog);
    $('#export-errors').on('click', exportErrors);
    $('#error-limit').on('change', function() {
        console.log('BugSquasher: Limit changed to', $(this).val());
        // Auto-reload when limit changes
        if ($('#log-content').is(':visible')) {
            loadErrors();
        }
    });

    // Delegated handler: toggle, filter, and update state
    $(document).on('click', '.error-type-filter-btn', function() {
        $(this).toggleClass('active');
        filterErrors();
        updateSelectAllButtonState();
    });

    // Fix for Select All button functionality (unchanged logic, now works with dynamic buttons)
    $('#toggle-all-filters').on('click', function() {
        var $button = $(this);
        var $buttons = $('.error-type-filter-btn');
        var currentState = $button.data('state');

        if (currentState === 'select' || currentState === 'partial') {
            // Select all
           
            $button.find('.btn-text').text('Deselect All');
        } else {
            // Deselect all
            $buttons.removeClass('active');
            $button.data('state', 'select');
            $button.find('.btn-text').text('Select All');
        }

        filterErrors();
    });

    function updateSelectAllButtonState() {
        var $button = $('#toggle-all-filters');
        var $buttons = $('.error-type-filter-btn');
        var total = $buttons.length;
        var checkedCount = $buttons.filter('.active').length;

        if (total === 0) {
            $button.hide().data('state', 'select').find('.btn-text').text('Select All');
            return;
        } else {
            $button.show();
        }

        if (checkedCount === 0) {
            $button.data('state', 'select');
            $button.find('.btn-text').text('Select All');
        } else if (checkedCount === total) {
            $button.data('state', 'deselect');
            $button.find('.btn-text').text('Deselect All');
        } else {
            $button.data('state', 'partial');
            $button.find('.btn-text').text('Select All');
        }
    }

    // Move WP admin notices below the BugSquasher header on this screen
    (function relocateAdminNotices() {
        const $host = $('#bugsquasher-admin-notices');
        if (!$host.length) return;
        const $notices = $('.wrap > .notice, .wrap > .error, .wrap > .updated, .wrap > .update-nag, .wrap > #message');
        if ($notices.length) {
            $host.append($notices);
        }
    })();

    // Inline cards updater honors the same default
    (function($) {
        var allErrors = [];
        var allTypes = [];

        function fetchErrors(limit) {
            return $.post(bugsquasher_ajax.ajax_url, {
                action: 'bugsquasher_get_errors',
                nonce: bugsquasher_ajax.nonce,
                limit: limit
            });
        }

        function renderStatCards(types, errors) {
            // ...existing code...
        }

        function renderFilterButtons(types) {
            var container = $('#filter-buttons-container');
            if (!container.length) return;
            var html = '';
            types.forEach(function(type) {
                html += '<button type="button" class="error-type-filter-btn active" data-type="' + type + '">' + type + '</button>';
            });
            container.html(html);
            $(document).trigger('bugsquasher:filtersRendered');
        }

        function renderErrors(errors) {
            // ...existing code...
        }

        function updateCardsAndFilters() {
            // ...existing code...
        }

        // Initial render on page load
        $(document).ready(function() {
            updateCardsAndFilters();
        });

        // Update on clicking "Load Recent Errors"
        $(document).on('click', '#load-errors', function() {
            updateCardsAndFilters();
        });

        // Update when limit changes
        $(document).on('change', '#error-limit', function() {
            updateCardsAndFilters();
        });

        // --- Select All Button Logic ---
        function updateSelectAllButtonState() {
            var $btn = $('#toggle-all-filters');
            var $filters = $('#filter-buttons-container .error-type-filter-btn');
            var total = $filters.length;
            var selected = $filters.filter('.active').length;

            if (selected === 0) {
                $btn.attr('data-state', 'select').find('.btn-text').text('Select All');
            } else if (selected === total) {
                $btn.attr('data-state', 'deselect').find('.btn-text').text('Deselect All');
            } else {
                $btn.attr('data-state', 'partial').find('.btn-text').text('Select All');
            }
        }

        // Toggle all filters on Select All button click
        $(document).on('click', '#toggle-all-filters', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $filters = $('#filter-buttons-container .error-type-filter-btn');
            var state = $btn.attr('data-state');

            if (state === 'select' || state === 'partial') {
                $filters.addClass('active');
            } else if (state === 'deselect') {
                $filters.removeClass('active');
            }
            updateSelectAllButtonState();
            filterErrorsByActiveTypes();
        });

        // Individual filter button click
        $(document).on('click', '#filter-buttons-container .error-type-filter-btn', function(e) {
            $(this).toggleClass('active');
            updateSelectAllButtonState();
            filterErrorsByActiveTypes();
        });

        // When filters are rendered, update button state
        $(document).on('bugsquasher:filtersRendered', function() {
            updateSelectAllButtonState();
            filterErrorsByActiveTypes();
        });

        // Filtering logic
        function filterErrorsByActiveTypes() {
            var activeTypes = [];
            $('#filter-buttons-container .error-type-filter-btn.active').each(function() {
                activeTypes.push($(this).data('type'));
            });

            // Show/hide log entries based on active filters
            var $entries = $('#log-content .log-entry');
            var visibleCount = 0;

            $entries.each(function() {
                var $entry = $(this);
                var entryType = $entry.hasClass('log-entry') ? $entry.attr('class').split(' ').filter(function(c){return c !== 'log-entry';})[0] : '';
                // fallback to data-type if present
                if (!entryType && $entry.attr('data-type')) {
                    entryType = $entry.attr('data-type');
                }
                if (activeTypes.length === 0 || activeTypes.indexOf(entryType) !== -1) {
                    $entry.show();
                    visibleCount++;
                } else {
                    $entry.hide();
                }
            });

            // Update error count display
            $('#error-count').text(visibleCount + ' errors shown');
            updateSelectAllButtonState();
            renderErrorOverviewChart();
        }

        // ...existing code...
    })(jQuery);
});