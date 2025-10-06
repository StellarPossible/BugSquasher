jQuery(document).ready(function($) {

    // Load errors on page load
    loadErrors();

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
     * Update error count display
     */
    function updateErrorCount(count) {
        console.log('BugSquasher: Updating error count to', count);
        $('#error-count').text('Found ' + count + ' errors');
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
                    alert('Debug log cleared successfully!');
                    loadErrors();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to clear debug log.');
            }
        });
    }

    /**
     * Display errors in the log container
     */
    function displayErrors(errors) {
        console.log('BugSquasher: Displaying', errors.length, 'errors');
        
        var $container = $('#log-content');
        
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
            alert('No errors to export.');
            return;
        }
        
        var content = 'BugSquasher Error Export\\n';
        content += 'Generated: ' + new Date().toLocaleString() + '\\n';
        content += 'Total Errors: ' + errors.length + '\\n';
        content += '================================\\n\\n';
        content += errors.join('\\n\\n');
        
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
     * Escape HTML characters
     */
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
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
     * Update error count display
     */
    function updateErrorCount(count) {
        console.log('BugSquasher: Updating error count to', count);
        $('#error-count').text('Found ' + count + ' errors');
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
                    alert('Debug log cleared successfully!');
                    loadErrors();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to clear debug log.');
            }
        });
    }

    /**
     * Display errors in the log container
     */
    function displayErrors(errors) {
        console.log('BugSquasher: Displaying', errors.length, 'errors');
        
        var $container = $('#log-content');
        
        if (errors.length === 0) {
            console.log('BugSquasher: No errors to display');
            $container.html(
                '<div class="no-errors">' +
                '<div class="dashicons dashicons-yes-alt"></div>' +
                '<h3>No errors found!</h3>' +
                '<p>Your debug log is clean of fatal errors, parse errors, and critical issues.</p>' +
                '</div>'
            );
            return;
        }
        
        var html = '';
        $.each(errors, function(index, error) {
            console.log('BugSquasher: Processing error', index, ':', error);
            html += '<div class="log-entry ' + error.type + '" data-type="' + error.type + '">';
            html += '<div class="log-entry-header">';
            html += '<span class="log-entry-type ' + error.type + '">' + error.type + '</span>';
            if (error.timestamp) {
                html += '<span class="log-entry-timestamp">' + error.timestamp + '</span>';
            }
            if (error.line_number) {
                html += '<span class="log-entry-line">Line ' + error.line_number + '</span>';
            }
            html += '</div>';
            html += '<div class="log-entry-message">' + escapeHtml(error.message) + '</div>';
            html += '</div>';
        });
        
        $container.html(html);
        filterErrors();
    }

    /**
     * Filter errors based on selected checkboxes
     */
    function filterErrors() {
        var showFatal = $('#show-fatal').is(':checked');
        var showParse = $('#show-parse').is(':checked');
        var showError = $('#show-error').is(':checked');
        var showCritical = $('#show-critical').is(':checked');
        
        $('.log-entry').each(function() {
            var $entry = $(this);
            var type = $entry.data('type');
            var show = false;
            
            switch(type) {
                case 'fatal':
                    show = showFatal;
                    break;
                case 'parse':
                    show = showParse;
                    break;
                case 'error':
                    show = showError;
                    break;
                case 'critical':
                    show = showCritical;
                    break;
            }
            
            if (show) {
                $entry.show();
            } else {
                $entry.hide();
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
            var lineNumber = $entry.find('.log-entry-line').text();
            var message = $entry.find('.log-entry-message').text();
            
            errors.push(type.toUpperCase() + ' | ' + timestamp + ' | ' + lineNumber + ' | ' + message);
        });
        
        if (errors.length === 0) {
            alert('No errors to export.');
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
     * Escape HTML characters
     */
    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
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

});