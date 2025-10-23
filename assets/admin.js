jQuery(document).ready(function($) {
    // Keep a copy of all errors for re-render/filter
    let allErrors = [];

    // Load errors on page load
    loadErrors();

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
     * Update error count display
     */
    function updateErrorCount(count) {
        console.log('BugSquasher: Updating error count to', count);
        $('#error-count').text('Found ' + count + ' errors');
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

    // Render errors into the log container
    function renderErrors(errors) {
        const logContent = $('#log-content');
        if (!errors || errors.length === 0) {
            logContent.html('<div class="no-errors"><span class="dashicons dashicons-yes-alt"></span><p>No errors found in the debug log. Great job!</p></div>').show();
            updateErrorCount(0);
            return;
        }

        const html = errors.map(err => {
            const type = err.type || 'error';
            const ts = err.timestamp ? escapeHtml(err.timestamp) : '';
            const safeMsg = escapeHtml(err.message || '');
            return `
                <div class="log-entry ${type}" data-type="${type}">
                    <div class="log-entry-header">
                        <span class="log-entry-timestamp">${ts}</span>
                        <span class="log-entry-type ${type}">${type.toUpperCase()}</span>
                    </div>
                    <div class="log-entry-message">${safeMsg}</div>
                </div>
            `;
        }).join('');

        logContent.html(html).show();
        updateErrorCount(errors.length);
    }

    /**
     * Display errors in the log container + build filters
     */
    function displayErrors(errors, errorTypes) {
        const logContent = $('#log-content');
        const errorCount = $('#error-count');
        const filterButtonsContainer = $('#filter-buttons-container');
        const toggleAllButton = $('#toggle-all-filters');

        // Store all loaded errors
        allErrors = Array.isArray(errors) ? errors : [];

        // Render the error list
        renderErrors(allErrors);

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

            // Set default active filters (only if those types exist)
            const defaultActiveFilters = ['fatal', 'parse', 'critical', 'error', 'debug'];
            $('.error-type-filter-btn').each(function() {
                const t = $(this).data('type');
                if (defaultActiveFilters.includes(t)) {
                    $(this).addClass('active');
                }
            });

            toggleAllButton.show();
        } else if (allErrors.length > 0) {
            filterButtonsContainer.html('<p>No specific error types found to filter by.</p>');
            toggleAllButton.hide();
        } else {
            filterButtonsContainer.html('<p>Load errors to see available filters.</p>');
            toggleAllButton.hide();
        }

        if (allErrors.length === 0) {
            logContent.html('<div class="no-errors"><span class="dashicons dashicons-yes-alt"></span><p>No errors found in the debug log. Great job!</p></div>').show();
            errorCount.text('No errors found');
        } else {
            // Apply initial filtering based on default active buttons
            filterErrors();
        }

        updateSelectAllButtonState();
    }

    /**
     * Load errors from server
     */
    function loadErrors() {
        console.log('BugSquasher: Starting to load errors...');
        $('#loading').show();
        $('#log-content').hide();
        
        // Get selected limit
        var limit = $('#error-limit').val() || 50;
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
                    displayErrors(response.data.errors, response.data.error_types);
                    updateErrorCount(response.data.count);
                    
                    // Apply initial filtering based on checkbox states
                    filterErrors();
                    
                    // Show cache status
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
     * Export errors to a text file
     */
    function exportErrors() {
        var errors = [];
        $('.log-entry:visible').each(function() {
            var $entry = $(this);
            var type = $entry.find('.log-entry-type').text();
            var timestamp = $entry.find('.log-entry-timestamp').text();
            var message = $entry.find('.log-entry-message').text();
            
            errors.push(type + ' | ' + timestamp + ' | ' + message);
        });
        
        if (errors.length === 0) {
            showToast('No errors to export.', 'error');
            return;
        }
        
        var content = 'BugSquasher Error Export\n';
        content += 'Generated: ' + new Date().toLocaleString() + '\n';
        content += 'Total Errors: ' + errors.length + '\n';
        content += '================================\n\n';
        content += errors.join('\n\n');
        
        var blob = new Blob([content], { type: 'text/plain' });
        var url = window.URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'bugsquasher-errors-' + new Date().toISOString().slice(0, 10) + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    /**
     * Filter errors by type (uses .log-entry generated by renderErrors)
     */
    function filterErrors() {
        var checkedTypes = [];
        $('.error-type-filter-btn.active').each(function() {
            checkedTypes.push($(this).data('type'));
        });

        var visibleCount = 0;
        var $entries = $('.log-entry');

        $entries.each(function() {
            var $entry = $(this);
            var entryType = $entry.attr('data-type');

            if (checkedTypes.length === 0 || checkedTypes.indexOf(entryType) !== -1) {
                $entry.show();
                visibleCount++;
            } else {
                $entry.hide();
            }
        });

        // Update count of visible errors
        var totalErrors = $entries.length;
        if (visibleCount === totalErrors && checkedTypes.length > 0) {
            $('#error-count').text('Found ' + totalErrors + ' errors');
        } else if (checkedTypes.length === 0) {
            $('#error-count').text('Showing 0 of ' + totalErrors + ' errors. Select a filter to see results.');
        } else {
            $('#error-count').text('Showing ' + visibleCount + ' of ' + totalErrors + ' errors');
        }
        updateSelectAllButtonState();
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
            $buttons.addClass('active');
            $button.data('state', 'deselect');
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
});