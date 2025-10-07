jQuery(document).ready(function($) {
    
    // Load errors on page load
    loadErrors();

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

        // Get all available error types in the current results
        var availableTypes = [];
        $('.log-entry').each(function() {
            var entryType = $(this).attr('data-type');
            if (availableTypes.indexOf(entryType) === -1) {
                availableTypes.push(entryType);
            }
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

        // Check for missing checked types
        var missingTypes = [];
        $.each(checkedTypes, function(index, type) {
            if (availableTypes.indexOf(type) === -1) {
                missingTypes.push(type);
            }
        });

        // Show missing types notification
        if (missingTypes.length > 0 && $('.log-entry').length > 0) {
            showMissingTypesNotification(missingTypes);
        } else {
            $('#missing-types-notification').hide();
        }

        // Update count of visible errors
        var totalErrors = $('.log-entry').length;
        if (visibleCount === totalErrors) {
            $('#error-count').text('Found ' + totalErrors + ' errors');
        } else {
            $('#error-count').text('Showing ' + visibleCount + ' of ' + totalErrors + ' errors');
        }
    }

    /**
     * Show notification for missing bug types
     */
    function showMissingTypesNotification(missingTypes) {
        var currentLimit = parseInt($('#error-limit').val()) || 50;
        var suggestedLimits = [];
        
        if (currentLimit < 100) suggestedLimits.push(100);
        if (currentLimit < 500) suggestedLimits.push(500);
        if (currentLimit < 1000) suggestedLimits.push(1000);
        
        var typeList = missingTypes.map(function(type) {
            return '<strong>' + type.charAt(0).toUpperCase() + type.slice(1) + '</strong>';
        }).join(', ');
        
        var html = '<div class="missing-types-notification">';
        html += '<h4>🔍 Selected error types not found</h4>';
        html += '<p>The following checked error types were not found in the current results: ' + typeList + '</p>';
        
        if (suggestedLimits.length > 0) {
            html += '<div class="expand-suggestion">';
            html += '💡 <strong>Suggestion:</strong> Try expanding your search to ';
            html += suggestedLimits.map(function(limit) {
                return '<a href="#" onclick="$(\'#error-limit\').val(' + limit + '); loadErrors(); return false;" style="color: white; text-decoration: underline;">' + limit + ' errors</a>';
            }).join(' or ');
            html += ' to find more results.';
            html += '</div>';
        }
        
        html += '</div>';
        
        $('#missing-types-notification').html(html).show();
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

});