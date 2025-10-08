jQuery(document).ready(function($) {
    
    // Load errors on page load
    loadErrors();

    /**
     * Create and show a toast notification
     */
    function showToast(message, type = 'success', duration = 4000) {
        // Remove any existing toasts
        $('.bugsquasher-toast').remove();
        
        // Create toast element
        const toast = $(`
            <div class="bugsquasher-toast bugsquasher-toast-${type}">
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
        setTimeout(() => toast.addClass('bugsquasher-toast-show'), 100);
        
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
        toast.removeClass('bugsquasher-toast-show');
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

    /**
     * Display errors in the log container
     */
    function displayErrors(errors) {
        console.log('BugSquasher: Displaying', errors.length, 'errors');
        
        var $container = $('#log-content');
        
        if ($container.length === 0) {
            console.error('BugSquasher: #log-content element not found!');
            return;
        }
        
        if (errors.length === 0) {
            console.log('BugSquasher: No errors to display');
            $container.html(
                '<div class="no-errors">' +
                '<div class="dashicons dashicons-yes-alt"></div>' +
                '<h3>No errors found!</h3>' +
                '<p>Your debug log is clean of fatal errors, parse errors, and critical issues.</p>' +
                '</div>'
            );
            $container.show();
            return;
        }
        
        var html = '';
        $.each(errors, function(index, error) {
            console.log('BugSquasher: Processing error', index, ':', error);
            html += '<div class="log-entry ' + error.type + '" data-type="' + error.type + '">';
            html += '<div class="log-entry-header">';
            html += '<span class="log-entry-type ' + error.type + '">' + error.type.toUpperCase() + '</span>';
            if (error.timestamp) {
                html += '<span class="log-entry-timestamp">' + escapeHtml(error.timestamp) + '</span>';
            }
            html += '</div>';
            html += '<div class="log-entry-message">' + escapeHtml(error.message) + '</div>';
            html += '</div>';
        });

        $container.html(html);
        $container.show();
        console.log('BugSquasher: Errors displayed successfully');
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
                    displayErrors(response.data.errors);
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
     * Filter errors by type
     */
    function filterErrors() {
        var checkedTypes = [];
        $('.error-type-filter:checked').each(function() {
            checkedTypes.push($(this).val());
        });

        var visibleCount = 0;
        $('.log-entry').each(function() {
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
        var totalErrors = $('.log-entry').length;
        if (visibleCount === totalErrors) {
            $('#error-count').text('Found ' + totalErrors + ' errors');
        } else {
            $('#error-count').text('Showing ' + visibleCount + ' of ' + totalErrors + ' errors');
        }
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

    // Filter checkboxes
    $('.error-type-filter').on('change', function() {
        filterErrors();
    });

    // Debug toggle handlers
    $('#wp-debug-toggle, #wp-debug-log-toggle, #wp-debug-display-toggle').on('change', function(e) {
        const $toggle = $(this);
        const setting = $toggle.attr('id').replace('-toggle', '').replace(/-/g, '_').toUpperCase();
        const value = $toggle.is(':checked');
        
        // Check if the toggle is disabled (permissions issue)
        if ($toggle.prop('disabled')) {
            e.preventDefault();
            $toggle.prop('checked', !value); // Revert the change
            showToast('wp-config.php permissions prevent debug setting modification.', 'error');
            return false;
        }
        
        // Check for development environment confirmation if required
        const $devConfirm = $('#dev-environment-confirm');
        if ($devConfirm.length && !$devConfirm.is(':checked')) {
            e.preventDefault();
            $toggle.prop('checked', !value); // Revert the change
            showToast('Please confirm this is a development environment before modifying debug settings.', 'error');
            // Highlight the confirmation checkbox
            $devConfirm.closest('div').css('border', '2px solid #d63638').css('border-radius', '4px');
            setTimeout(() => {
                $devConfirm.closest('div').css('border', '').css('border-radius', '');
            }, 3000);
            return false;
        }
        
        // Check if this is WP_DEBUG_DISPLAY and WP_DEBUG is not enabled
        if (setting === 'WP_DEBUG_DISPLAY' && !$('#wp-debug-toggle').is(':checked')) {
            e.preventDefault();
            $toggle.prop('checked', false);
            showToast('WP_DEBUG must be enabled before you can enable WP_DEBUG_DISPLAY', 'error');
            return false;
        }
        
        // Prevent multiple rapid clicks
        if ($toggle.data('processing')) {
            e.preventDefault();
            return false;
        }
        
        // Mark as processing
        $toggle.data('processing', true);
        $toggle.prop('disabled', true);
        
        console.log('Toggling', setting, 'to', value);
        
        $.ajax({
            url: bugsquasher_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bugsquasher_toggle_debug',
                nonce: bugsquasher_ajax.nonce,
                setting: setting,
                value: value
            },
            success: function(response) {
                console.log('AJAX Response:', response);
                if (response.success) {
                    showToast(response.data.message, 'success');
                    
                    // Don't reload immediately, just update the UI state
                    // The toggle should stay in its new position
                    
                    // If we just enabled/disabled WP_DEBUG, handle WP_DEBUG_DISPLAY accordingly
                    if (setting === 'WP_DEBUG') {
                        const $displayToggle = $('#wp-debug-display-toggle');
                        const $displayItem = $displayToggle.closest('.debug-toggle-item');
                        
                        if (value) {
                            // WP_DEBUG enabled - enable WP_DEBUG_DISPLAY toggle
                            $displayToggle.prop('disabled', false);
                            $displayItem.removeClass('debug-item-disabled');
                        } else {
                            // WP_DEBUG disabled - disable and uncheck WP_DEBUG_DISPLAY
                            $displayToggle.prop('disabled', true).prop('checked', false);
                            $displayItem.addClass('debug-item-disabled');
                        }
                    }
                    
                    // Refresh the debug status info after a short delay
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showToast('Error: ' + (response.data || 'Unknown error'), 'error');
                    // Revert the toggle state on error
                    $toggle.prop('checked', !value);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', xhr.responseText);
                showToast('AJAX Error: ' + error, 'error');
                // Revert the toggle state on error
                $toggle.prop('checked', !value);
            },
            complete: function() {
                // Re-enable the toggle and clear processing flag
                $toggle.prop('disabled', false);
                $toggle.data('processing', false);
            }
        });
    });

    // Fix for Select All button functionality
    $('#toggle-all-filters').on('click', function() {
        var $button = $(this);
        var $checkboxes = $('.error-type-filter');
        var currentState = $button.data('state');
        
        if (currentState === 'select' || currentState === 'partial') {
            // Select all
            $checkboxes.prop('checked', true);
            $button.data('state', 'deselect');
            $button.find('.btn-text').text('Deselect All');
        } else {
            // Deselect all
            $checkboxes.prop('checked', false);
            $button.data('state', 'select');
            $button.find('.btn-text').text('Select All');
        }
        
        // Trigger filter update
        updateVisibleErrors();
    });
    
    // Update button state when individual filters change
    $('.error-type-filter').on('change', function() {
        updateSelectAllButtonState();
    });
    
    function updateSelectAllButtonState() {
        var $button = $('#toggle-all-filters');
        var $checkboxes = $('.error-type-filter');
        var checkedCount = $checkboxes.filter(':checked').length;
        
        if (checkedCount === 0) {
            $button.data('state', 'select');
            $button.find('.btn-text').text('Select All');
        } else if (checkedCount === $checkboxes.length) {
            $button.data('state', 'deselect');
            $button.find('.btn-text').text('Deselect All');
        } else {
            $button.data('state', 'partial');
            $button.find('.btn-text').text('Select All');
        }
    }
    
    // Update filter function
    function updateVisibleErrors() {
        var selectedTypes = [];
        $('.error-type-filter:checked').each(function() {
            selectedTypes.push($(this).val());
        });

        $('.log-entry').each(function() {
            var entryType = $(this).data('type');
            if (selectedTypes.includes(entryType)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }
    
});