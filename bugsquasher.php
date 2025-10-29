<?php

/**
 * Plugin Name: BugSquasher
 * Plugin URI: https://stellarpossible.com
 * Description: A simple plugin to filter WordPress debug.log files and show only errors, excluding notices, warnings, and deprecated messages.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Author: StellarPossible LLC
 * Author URI: https://stellarpossible.com/products/bugsquasher/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bugsquasher
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BUGSQUASHER_VERSION', '1.1.0');
define('BUGSQUASHER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BUGSQUASHER_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Configuration Class
 */
class BugSquasher_Config
{
    private static $default_settings = [
        'timezone' => 'UTC',
        'date_format' => 'Y-m-d H:i:s',
        'display_timezone' => true,
        'export_format' => 'detailed',
        'max_errors_display' => 25,
        'cache_duration' => 300,
        'error_types_default' => ['fatal', 'parse', 'critical', 'debug'],
        'timestamp_conversion' => true,
        'rate_limit_requests' => 10,
        'rate_limit_window' => 60
    ];

    public static function get_setting($key, $default = null)
    {
        $settings = get_option('bugsquasher_settings', self::$default_settings);
        return isset($settings[$key]) ? $settings[$key] : ($default !== null ? $default : self::$default_settings[$key]);
    }

    public static function save_settings($new_settings)
    {
        $current = get_option('bugsquasher_settings', self::$default_settings);
        $updated = array_merge($current, $new_settings);
        return update_option('bugsquasher_settings', $updated);
    }

    public static function get_default_settings()
    {
        return self::$default_settings;
    }
}

/**
 * Main BugSquasher Class
 */
class BugSquasher
{
    public function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    public function init()
    {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Prevent any CSS from being auto-loaded for our plugin
        add_action('admin_enqueue_scripts', array($this, 'prevent_css_loading'), 999);

        // AJAX handlers
        add_action('wp_ajax_bugsquasher_get_errors', array($this, 'ajax_get_errors'));
        add_action('wp_ajax_bugsquasher_clear_log', array($this, 'ajax_clear_log'));
        add_action('wp_ajax_bugsquasher_get_debug_status', array($this, 'ajax_get_debug_status'));
    }

    /**
     * Register settings with WordPress
     */
    public function register_settings()
    {
        register_setting('bugsquasher_settings', 'bugsquasher_settings');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_management_page(
            'BugSquasher - Debug Log Viewer',
            'BugSquasher',
            'manage_options',
            'bugsquasher',
            array($this, 'admin_page')
        );

        // Add settings submenu
        add_submenu_page(
            'bugsquasher',
            'BugSquasher Settings',
            'Settings',
            'manage_options',
            'bugsquasher-settings',
            array($this, 'settings_page')
        );
    }

    public function enqueue_admin_assets($hook)
    {
        // Only load on our admin page
        if ($hook !== 'tools_page_bugsquasher') {
            return;
        }

        // Enqueue our stylesheet
        wp_enqueue_style(
            'bugsquasher-admin',
            BUGSQUASHER_PLUGIN_URL . 'assets/admin.css',
            array(),
            BUGSQUASHER_VERSION
        );

        // Register and enqueue font-face with absolute URLs and cache-busting (best practice)
        $font_dir     = BUGSQUASHER_PLUGIN_DIR . 'assets/fonts/';
        $font_url     = BUGSQUASHER_PLUGIN_URL . 'assets/fonts/';
        $font_files   = array(
            'woff2' => 'Wiggly.woff2',
            'woff'  => 'Wiggly.woff',
            'ttf'   => 'Wiggly.ttf',
        );

        $src_parts    = array();
        $preload_url  = '';
        $preload_type = '';

        foreach (array('woff2', 'woff', 'ttf') as $fmt) {
            if (! isset($font_files[$fmt])) {
                continue;
            }
            $file = $font_files[$fmt];
            if (file_exists($font_dir . $file)) {
                $ver  = filemtime($font_dir . $file);
                $url  = $font_url . $file . '?ver=' . $ver;
                $type = ($fmt === 'ttf') ? 'truetype' : $fmt;

                if (! $preload_url) {
                    $preload_url  = $url;
                    $preload_type = ($fmt === 'ttf') ? 'font/ttf' : 'font/' . $fmt;
                }
                $src_parts[] = "url('{$url}') format('{$type}')";
            }
        }

        if ($src_parts) {
            $font_css = "@font-face {
  font-family: 'Wiggly';
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: " . implode(",\n       ", $src_parts) . ";
}";
            wp_register_style('bugsquasher-fonts', false, array(), BUGSQUASHER_VERSION);
            wp_add_inline_style('bugsquasher-fonts', $font_css);
            wp_enqueue_style('bugsquasher-fonts');
        }

        // Preload the preferred font (only on our screen)
        add_filter('wp_resource_hints', function ($urls, $relation_type) use ($preload_url, $preload_type) {
            if ($relation_type === 'preload' && is_admin() && isset($_GET['page']) && $_GET['page'] === 'bugsquasher' && $preload_url) {
                $urls[] = array(
                    'href'        => $preload_url,
                    'as'          => 'font',
                    'type'        => $preload_type,
                    'crossorigin' => 'anonymous',
                );
            }
            return $urls;
        }, 10, 2);

        // Only enqueue JavaScript - CSS is embedded directly in admin_page() to avoid MIME issues
        wp_enqueue_script(
            'bugsquasher-admin',
            BUGSQUASHER_PLUGIN_URL . 'assets/admin.js',
            array('jquery'), // dropped chartjs dependency
            BUGSQUASHER_VERSION,
            true
        );

        // Localize script
        $current_settings = get_option('bugsquasher_settings', BugSquasher_Config::get_default_settings());
        wp_localize_script('bugsquasher-admin', 'bugsquasher_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bugsquasher_nonce'),
            // Pass export format to the client (detailed | compact | markdown_table | markdown_list)
            'export_format' => isset($current_settings['export_format']) ? $current_settings['export_format'] : 'detailed',
        ));
    }

    /**
     * Settings page implementation
     */
    public function settings_page()
    {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['bugsquasher_settings_nonce'], 'bugsquasher_settings')) {
            $new_settings = [
                'timezone' => sanitize_text_field($_POST['timezone']),
                'date_format' => sanitize_text_field($_POST['date_format']),
                'display_timezone' => isset($_POST['display_timezone']),
                'timestamp_conversion' => isset($_POST['timestamp_conversion']),
                'max_errors_display' => intval($_POST['max_errors_display']),
                'cache_duration' => intval($_POST['cache_duration']),
                'export_format' => sanitize_text_field($_POST['export_format'])
            ];

            BugSquasher_Config::save_settings($new_settings);
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }

        $current_settings = get_option('bugsquasher_settings', BugSquasher_Config::get_default_settings());
?>
        <div class="wrap">
            <h1>BugSquasher Settings</h1>
            <form method="post" action="">
                <?php wp_nonce_field('bugsquasher_settings', 'bugsquasher_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Timezone</th>
                        <td>
                            <select name="timezone">
                                <?php
                                $timezones = [
                                    'UTC' => 'UTC',
                                    'America/New_York' => 'Eastern Time',
                                    'America/Chicago' => 'Central Time',
                                    'America/Denver' => 'Mountain Time',
                                    'America/Los_Angeles' => 'Pacific Time',
                                    'Europe/London' => 'London',
                                    'Europe/Paris' => 'Paris',
                                    'Asia/Tokyo' => 'Tokyo',
                                    'Australia/Sydney' => 'Sydney'
                                ];
                                foreach ($timezones as $value => $label) {
                                    $selected = ($current_settings['timezone'] === $value) ? 'selected' : '';
                                    echo "<option value=\"$value\" $selected>$label</option>";
                                }
                                ?>
                            </select>
                            <p class="description">Select your preferred timezone for displaying timestamps.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Date Format</th>
                        <td>
                            <select name="date_format">
                                <?php
                                $formats = [
                                    'Y-m-d H:i:s' => 'YYYY-MM-DD HH:MM:SS',
                                    'm/d/Y g:i A' => 'MM/DD/YYYY H:MM AM/PM',
                                    'd/m/Y H:i' => 'DD/MM/YYYY HH:MM',
                                    'M j, Y g:i A' => 'Mon DD, YYYY H:MM AM/PM',
                                    'c' => 'ISO 8601 Format'
                                ];
                                foreach ($formats as $value => $label) {
                                    $selected = ($current_settings['date_format'] === $value) ? 'selected' : '';
                                    $example = date($value);
                                    echo "<option value=\"$value\" $selected>$label ($example)</option>";
                                }
                                ?>
                            </select>
                            <p class="description">Choose how timestamps should be formatted.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Display Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="display_timezone" value="1" <?php checked($current_settings['display_timezone']); ?>>
                                Show timezone in timestamps
                            </label><br>

                            <label>
                                <input type="checkbox" name="timestamp_conversion" value="1" <?php checked($current_settings['timestamp_conversion']); ?>>
                                Convert timestamps to selected timezone
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Default Errors to Display</th>
                        <td>
                            <input type="number" name="max_errors_display" value="<?php echo esc_attr($current_settings['max_errors_display']); ?>" min="10" max="500">
                            <p class="description">Number of errors to display by default (10-500).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Cache Duration</th>
                        <td>
                            <input type="number" name="cache_duration" value="<?php echo esc_attr($current_settings['cache_duration']); ?>" min="60" max="3600">
                            <p class="description">How long to cache results (in seconds, 60-3600).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Export Format</th>
                        <td>
                            <select name="export_format">
                                <option value="detailed" <?php selected($current_settings['export_format'], 'detailed'); ?>>Detailed (with timestamps)</option>
                                <option value="compact" <?php selected($current_settings['export_format'], 'compact'); ?>>Compact (messages only)</option>
                                <option value="markdown_table" <?php selected($current_settings['export_format'], 'markdown_table'); ?>>Markdown table</option>
                                <option value="markdown_list" <?php selected($current_settings['export_format'], 'markdown_list'); ?>>Markdown list</option>
                            </select>
                            <p class="description">Choose the format for exported error logs.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Enhanced timestamp formatting
     */
    private function format_timestamp($raw_timestamp)
    {
        $timezone = BugSquasher_Config::get_setting('timezone', 'UTC');
        $format = BugSquasher_Config::get_setting('date_format', 'Y-m-d H:i:s');
        $convert_timezone = BugSquasher_Config::get_setting('timestamp_conversion', true);
        $display_timezone = BugSquasher_Config::get_setting('display_timezone', true);

        if (!$convert_timezone) {
            return $raw_timestamp;
        }

        try {
            // Parse the WordPress debug log timestamp format
            $dt = DateTime::createFromFormat('d-M-Y H:i:s T', $raw_timestamp);
            if ($dt) {
                $dt->setTimezone(new DateTimeZone($timezone));
                $formatted = $dt->format($format);

                if ($display_timezone) {
                    $formatted .= ' ' . $dt->format('T');
                }

                return $formatted;
            }
        } catch (Exception $e) {
            error_log('BugSquasher: Timestamp conversion error: ' . $e->getMessage());
        }

        return $raw_timestamp; // Fallback to original
    }

    /**
     * Prevent any CSS from being auto-loaded for our plugin
     */
    public function prevent_css_loading($hook)
    {
        if ($hook !== 'tools_page_bugsquasher') {
            return;
        }
        // No-op: allow our stylesheet to load
    }

    /**
     * Admin page content
     */
    public function admin_page()
    {
    ?>
        <div class="wrap">
            <div class="bugsquasher-header">
                <div class="bugsquasher-logo-container">
                    <!-- Mobile-only powered by shown above the logo in column layout -->
                    <div class="bugsquasher-powered-by bugsquasher-powered-by--mobile">
                        Powered by
                        <a href="https://stellarpossible.com" target="_blank" rel="noopener">
                            <img src="<?php echo BUGSQUASHER_PLUGIN_URL . 'assets/images/spicon.png'; ?>" alt="StellarPossible" class="spicon" />
                        </a>
                    </div>
                    <img
                        src="<?php echo BUGSQUASHER_PLUGIN_URL . 'assets/images/BugSquasherLogo.png'; ?>"
                        alt="BugSquasher Logo"
                        class="bugsquasher-logo" />
                    <div class="header-title-wrapper">
                        <div class="bugsquasher-powered-by bugsquasher-powered-by--desktop">
                            Powered by
                            <a href="https://stellarpossible.com" target="_blank" rel="noopener">
                                <img src="<?php echo BUGSQUASHER_PLUGIN_URL . 'assets/images/spicon.png'; ?>" alt="StellarPossible" class="spicon" />
                            </a>
                        </div>
                        <div class="bugsquasher-title">BugSquasher</div>
                        <p class="bugsquasher-subtitle">Your friendly neighborhood debugger.</p>
                    </div>
                </div>

                <?php
                // Debug status moved to header
                $debug_log   = $this->get_debug_log_path();
                $debug_found = ($debug_log && file_exists($debug_log));
                $debug_size  = $debug_found ? size_format(filesize($debug_log)) : null;

                $wp_debug_enabled   = (defined('WP_DEBUG') && WP_DEBUG);
                $log_errors_enabled = (bool) ini_get('log_errors');
                $php_log            = (string) ini_get('error_log');
                ?>
                <div class="bugsquasher-security-status">
                    <strong>Debug Status</strong>
                    <div class="debug-status-container">
                        <span class="status-chip <?php echo $debug_found ? 'ok' : 'bad'; ?>">
                            Debug log: <?php
                                        if ($debug_found) {
                                            echo 'Found (' . esc_html($debug_size) . ')';
                                        } elseif (! $debug_log) {
                                            echo 'Not configured';
                                        } else {
                                            echo 'Not found';
                                        }
                                        ?>
                        </span>
                        <span class="status-chip <?php echo $wp_debug_enabled ? 'ok' : 'bad'; ?>">
                            WP_DEBUG: <?php echo $wp_debug_enabled ? 'Enabled' : 'Disabled'; ?>
                        </span>
                        <span class="status-chip neutral">
                            Path: <?php echo $debug_log ? '<code>' . esc_html($debug_log) . '</code>' : '—'; ?>
                        </span>
                        <span class="status-chip neutral">
                            PHP error_log: <?php echo $php_log ? '<code>' . esc_html($php_log) . '</code>' : '—'; ?>
                        </span>
                        <span class="status-chip <?php echo $log_errors_enabled ? 'ok' : 'bad'; ?>">
                            Log Errors: <?php echo $log_errors_enabled ? 'On' : 'Off'; ?>
                        </span>
                    </div>
                </div>

                <?php // Removed header action buttons (moved to Error Overview card) 
                ?>
            </div>

            <!-- Place admin notices for this page here -->
            <div id="bugsquasher-admin-notices"></div>

            <!-- Toast Container -->
            <div class="bugsquasher-toast-container" id="toast-container"></div>

            <div class="bugsquasher-container">
                <div class="bugsquasher-top-grid">
                    <!-- Error Overview stat cards (replaces bar chart) -->
                    <div class="bugsquasher-chart-card">
                        <div class="bugsquasher-chart-header">
                            <h3>Error Overview</h3>
                            <div class="bugsquasher-chart-actions">
                                <small>Counts reflect current filters</small>
                                <a href="?page=bugsquasher-settings" class="icon-btn" title="Settings" aria-label="Settings">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                </a>
                                <a href="https://stellarpossible.com/products/bugsquasher/" target="_blank" rel="noopener" class="icon-btn" title="Help" aria-label="Help">
                                    <span class="dashicons dashicons-editor-help"></span>
                                </a>
                            </div>
                        </div>

                        <div class="bugsquasher-stats-grid" id="bs-stat-cards" aria-live="polite">
                            <!-- Stat cards will be populated by JS -->
                            <div class="bs-stat-card bs-placeholder">
                                <div class="label">Loading...</div>
                                <div class="count">—</div>
                            </div>
                        </div>
                    </div>

                    <!-- Moved: Filter by Error Type card next to Error Overview -->
                    <div class="bugsquasher-filters">
                        <!-- inline controls inside the filter card -->
                        <div class="filter-controls">
                            <button id="load-errors" class="button button-primary">Load Recent Errors</button>
                            <select id="error-limit">
                                <option value="25" selected>Show 25 recent</option>
                                <option value="50">Show 50 recent</option>
                                <option value="100">Show 100 recent</option>
                                <option value="250">Show 250 recent</option>
                                <option value="500">Show 500 recent</option>
                                <option value="1000">Show 1,000 recent</option>
                                <option value="2000">Show 2,000 recent</option>
                                <option value="3000">Show 3,000 recent (max)</option>
                            </select>
                            <button id="clear-log" class="button">Clear Log</button>
                            <button id="export-errors" class="button">Export Errors</button>
                        </div>
                        <div class="filter-header">
                            <div class="filter-title-container">
                                <span class="dashicons dashicons-filter"></span>
                                <h3>Filter by Error Type</h3>
                            </div>
                            <button id="toggle-all-filters" class="button select-all-btn" data-state="select">
                                <span class="btn-text">Select All</span>
                            </button>
                        </div>

                        <div class="filter-buttons" id="filter-buttons-container">
                            <!-- Filter buttons will be dynamically inserted here -->
                            <p>Load errors to see available filters.</p>
                        </div>
                    </div>
                </div>

                <div class="bugsquasher-log-container">
                    <div id="error-count">Click "Load Recent Errors" to start</div>
                    <div id="missing-types-notification"></div>
                    <div id="log-content"></div>
                    <div id="loading">
                        <p>Loading errors...</p>
                    </div>
                </div>
            </div>

            <!-- Inline JS to render stat cards from existing AJAX endpoint -->
            <script>
                (function($) {
                    function fetchErrors(limit) {
                        return $.post(bugsquasher_ajax.ajax_url, {
                            action: 'bugsquasher_get_errors',
                            nonce: bugsquasher_ajax.nonce,
                            limit: limit
                        });
                    }

                    function renderStatCards(types, errors) {
                        var grid = document.getElementById('bs-stat-cards');
                        if (!grid) return;

                        // Count by type
                        var counts = {};
                        types.forEach(function(t) {
                            counts[t] = 0;
                        });
                        errors.forEach(function(e) {
                            if (e && e.type && counts.hasOwnProperty(e.type)) {
                                counts[e.type]++;
                            }
                        });

                        // Build cards
                        var html = '';
                        types.forEach(function(type) {
                            var count = counts[type] || 0;
                            var cls = 'type-' + type;
                            html += '<div class="bs-stat-card ' + cls + '">' +
                                '<div class="label">' + type + '</div>' +
                                '<div class="count">' + (count.toLocaleString ? count.toLocaleString() : count) + '</div>' +
                                '</div>';
                        });

                        // Also add a total card (optional)
                        var total = errors.length || 0;
                        html = '<div class="bs-stat-card type-total">' +
                            '<div class="label">total</div>' +
                            '<div class="count">' + (total.toLocaleString ? total.toLocaleString() : total) + '</div>' +
                            '</div>' + html;

                        grid.innerHTML = html;
                    }

                    function updateCards() {
                        var limitEl = document.getElementById('error-limit');
                        var limit = limitEl ? parseInt(limitEl.value, 10) || 25 : 25;
                        fetchErrors(limit).done(function(resp) {
                            if (resp && resp.success && resp.data) {
                                var types = resp.data.error_types || [];
                                var errors = resp.data.errors || [];
                                renderStatCards(types, errors);
                            }
                        });
                    }

                    // Initial render on page load
                    $(document).ready(function() {
                        updateCards();
                    });

                    // Update on clicking "Load Recent Errors"
                    $(document).on('click', '#load-errors', function() {
                        updateCards();
                    });

                    // Update when limit changes
                    $(document).on('change', '#error-limit', function() {
                        updateCards();
                    });
                })(jQuery);
            </script>
        </div>
<?php
    }

    /**
     * Check rate limiting
     */
    private function check_rate_limit()
    {
        $max_requests = BugSquasher_Config::get_setting('rate_limit_requests', 10);
        $window = BugSquasher_Config::get_setting('rate_limit_window', 60);

        $user_id = get_current_user_id();
        $cache_key = 'bugsquasher_rate_limit_' . $user_id;

        $requests = get_transient($cache_key);
        if ($requests === false) {
            $requests = [];
        }

        $now = time();
        // Remove old requests outside the window
        $requests = array_filter($requests, function ($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });

        if (count($requests) >= $max_requests) {
            return false;
        }

        // Add current request
        $requests[] = $now;
        set_transient($cache_key, $requests, $window);

        return true;
    }

    /**
     * Get debug status
     */
    private function get_debug_status()
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return '<span class="status-disabled">Disabled</span>';
        }

        $log_file = $this->get_debug_log_path();
        if (!$log_file || !file_exists($log_file)) {
            return '<span class="status-not-found">Log file not found</span>';
        }

        $size = $this->format_file_size(filesize($log_file));
        return '<span class="status-enabled">Enabled (' . $size . ')</span>';
    }

    /**
     * Get debug log file path
     */
    private function get_debug_log_path()
    {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
            return false;
        }

        if (is_string(WP_DEBUG_LOG)) {
            return WP_DEBUG_LOG;
        }

        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Format file size
     */
    private function format_file_size($bytes)
    {
        $units = array('B', 'KB', 'MB', 'GB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * AJAX handler to get filtered errors
     */
    public function ajax_get_errors()
    {
        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: ajax_get_errors called');
        }

        check_ajax_referer('bugsquasher_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BugSquasher: Unauthorized access attempt');
            }
            wp_die('Unauthorized');
        }

        // Check rate limiting
        if (!$this->check_rate_limit()) {
            wp_send_json_error('Rate limit exceeded. Please wait before making another request.');
        }
        $log_file = $this->get_debug_log_path();

        if (!$log_file || !file_exists($log_file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BugSquasher: Debug log file not found at: ' . $log_file);
            }
            wp_send_json_error('Debug log file not found');
        }

        // Get limit from request (default 25 for quick loading)
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 25;
        $limit = max(25, min(3000, $limit)); // Between 25 and 3000

        // Check if we have cached results
        $cache_key = 'bugsquasher_errors_' . $limit . '_' . filemtime($log_file);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false && isset($cached_data['errors']) && isset($cached_data['error_types'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BugSquasher: Using cached results');
            }
            $cached_data['cached'] = true;
            $cached_data['file_size'] = $this->format_file_size(filesize($log_file));
            wp_send_json_success($cached_data);
        }

        $errors = $this->parse_debug_log($limit);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Found ' . count($errors) . ' errors');
        }

        // Get unique error types and sort them
        $error_types = array_unique(array_column($errors, 'type'));
        $sorted_error_types = $this->sort_error_types(array_values($error_types));

        $data_to_cache = [
            'errors' => $errors,
            'error_types' => $sorted_error_types,
            'count' => count($errors)
        ];

        // Cache results for 5 minutes
        set_transient($cache_key, $data_to_cache, 300);

        wp_send_json_success(array(
            'errors' => $errors,
            'error_types' => $sorted_error_types,
            'count' => count($errors),
            'file_size' => $this->format_file_size(filesize($log_file)),
            'cached' => false
        ));
    }

    /**
     * AJAX handler to clear debug log
     */
    public function ajax_clear_log()
    {
        check_ajax_referer('bugsquasher_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $log_file = $this->get_debug_log_path();

        if (!$log_file || !file_exists($log_file)) {
            wp_send_json_error('Debug log file not found');
        }

        if (file_put_contents($log_file, '') !== false) {
            wp_send_json_success('Debug log cleared successfully');
        } else {
            wp_send_json_error('Failed to clear debug log');
        }
    }

    /**
     * AJAX handler to get debug log status
     */
    public function ajax_get_debug_status()
    {
        check_ajax_referer('bugsquasher_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $debug_log = $this->get_debug_log_path();
        $status_html = '';

        if ($debug_log && file_exists($debug_log)) {
            $size = filesize($debug_log);
            $size_formatted = size_format($size);
            $status_html = '<span class="status-enabled">Debug log found (' . $size_formatted . ')</span>';

            // Show debug info if WP_DEBUG is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $status_html .= '<br><small>WP_DEBUG: Enabled | Path: ' . $debug_log . '</small>';
                $status_html .= '<br><small>PHP Error Log: ' . ini_get('error_log') . '</small>';
                $status_html .= '<br><small>Log Errors: ' . (ini_get('log_errors') ? 'On' : 'Off') . '</small>';
            }
        } else {
            if (!$debug_log) {
                $status_html = '<span class="status-not-found">Debug log not configured</span>';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $status_html .= '<br><small>WP_DEBUG: Enabled</small>';
                    $status_html .= '<br><small>WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled') . '</small>';
                    $status_html .= '<br><small>Set WP_DEBUG_LOG to true in wp-config.php to enable logging</small>';
                }
            } else {
                $status_html = '<span class="status-not-found">Debug log not found</span>';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $status_html .= '<br><small>WP_DEBUG: Enabled | Expected at: ' . $debug_log . '</small>';
                    $status_html .= '<br><small>WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled') . '</small>';
                    $status_html .= '<br><small>Check your wp-config.php settings</small>';
                }
            }
        }

        wp_send_json_success($status_html);
    }

    /**
     * Parse debug log and return formatted errors (efficient version)
     */
    private function parse_debug_log($max_lines = 100)
    {
        $log_file = $this->get_debug_log_path();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Looking for debug log at: ' . ($log_file ?: 'not configured'));
        }

        if (!$log_file || !file_exists($log_file)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BugSquasher: Debug log file not configured or does not exist');
            }
            return [];
        }

        $file_size = filesize($log_file);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Debug log file exists, size: ' . $file_size . ' bytes');
        }

        // For large files, read only the last portion
        $lines = $this->read_tail_lines($log_file, $max_lines * 10); // Read more lines to account for multi-line entries

        if (empty($lines)) {
            return [];
        }

        return $this->parse_log_lines($lines, $max_lines);
    }

    /**
     * Read last N lines from a file efficiently
     */
    private function read_tail_lines($file, $max_lines = 1000)
    {
        if (!file_exists($file)) {
            return [];
        }

        $file_size = filesize($file);
        if ($file_size == 0) {
            return [];
        }

        // For small files, just read everything
        if ($file_size < 50000) { // 50KB
            $content = file_get_contents($file);
            return explode("\n", $content);
        }

        // For large files, read from the end
        $handle = fopen($file, 'r');
        if (!$handle) {
            return [];
        }

        // Start from end and work backwards
        $lines = [];
        $buffer = '';
        $pos = $file_size;
        $chunk_size = 8192; // 8KB chunks

        while ($pos > 0 && count($lines) < $max_lines) {
            $pos = max(0, $pos - $chunk_size);
            fseek($handle, $pos);
            $chunk = fread($handle, min($chunk_size, $file_size - $pos));
            $buffer = $chunk . $buffer;

            // Split into lines
            $chunk_lines = explode("\n", $buffer);

            // Keep the first line as it might be incomplete
            $buffer = array_shift($chunk_lines);

            // Add lines to the beginning of our array
            $lines = array_merge($chunk_lines, $lines);
        }

        fclose($handle);

        // Return the last N lines
        return array_slice($lines, -$max_lines);
    }

    /**
     * Parse log lines into error entries
     */
    private function parse_log_lines($lines, $max_errors = 100)
    {
        $errors = [];
        $current_entry = '';
        $timestamp_pattern = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [A-Z]{3,4}\]/';
        $processed_count = 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Parsing ' . count($lines) . ' lines, max errors: ' . $max_errors);
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Check if this line starts a new log entry
            if (preg_match($timestamp_pattern, $line)) {
                // Process previous entry if it exists
                if (!empty($current_entry)) {
                    $processed = $this->process_log_entry($current_entry);
                    if ($processed && count($errors) < $max_errors) {
                        $errors[] = $processed;
                    }
                    $processed_count++;
                }

                // Start new entry
                $current_entry = $line;
            } else {
                // Continue previous entry
                $current_entry .= "\n" . $line;
            }
        }

        // Process the last entry
        if (!empty($current_entry) && count($errors) < $max_errors) {
            $processed = $this->process_log_entry($current_entry);
            if ($processed) {
                $errors[] = $processed;
            }
            $processed_count++;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Processed ' . $processed_count . ' entries, found ' . count($errors) . ' errors');
        }

        // Reverse to show most recent first
        return array_reverse($errors);
    }

    /**
     * Ensure every entry is processed; unknowns go to "misc"
     */
    private function process_log_entry($entry)
    {
        // Extract and format timestamp
        $timestamp = '';
        if (preg_match('/^\[([^\]]+)\]/', $entry, $matches)) {
            $raw_timestamp = $matches[1];
            $timestamp = $this->format_timestamp($raw_timestamp);
        }

        // Strip the leading [timestamp] from the message so duplicates can be grouped
        $clean_message = preg_replace('/^\[[^\]]+\]\s*/', '', trim($entry));

        return [
            'timestamp' => $timestamp,
            'type' => $this->get_error_type($entry),
            'message' => $clean_message,
        ];
    }

    /**
     * Get error type from log line, fallback to misc
     */
    private function get_error_type($line)
    {
        if (preg_match('/Fatal error|PHP Fatal error/i', $line)) {
            return 'fatal';
        } elseif (preg_match('/Parse error|PHP Parse error/i', $line)) {
            return 'parse';
        } elseif (preg_match('/AIOS .*firewall.*error/i', $line)) {
            return 'firewall';
        } elseif (preg_match('/CRITICAL/i', $line)) {
            return 'critical';
        } elseif (preg_match('/Cron .* error/i', $line)) {
            return 'cron';
        } elseif (preg_match('/PHP Warning|Warning:/i', $line)) {
            return 'warning';
        } elseif (preg_match('/PHP Notice|Notice:/i', $line)) {
            return 'notice';
        } elseif (preg_match('/PHP Deprecated|Deprecated:/i', $line)) {
            return 'deprecated';
        } elseif (preg_match('/Exception|Throwable|Error:/i', $line)) {
            // Generic PHP error/exception category
            return 'error';
        } elseif (preg_match('/\[PCT_.*_DEBUG\]/', $line)) {
            return 'debug';
        } elseif (preg_match('/\[BugSquasher\]/', $line)) {
            return 'info';
        }

        // Unknown classification -> Misc
        return 'misc';
    }

    /**
     * Sort error types by criticality
     */
    private function sort_error_types($types)
    {
        $order = [
            'fatal' => 1,
            'parse' => 2,
            'critical' => 3,
            'firewall' => 4,
            'error' => 5,
            'warning' => 6,
            'cron' => 7,
            'notice' => 8,
            'deprecated' => 9,
            'debug' => 10,
            'info' => 11,
            'misc' => 12,
        ];

        usort($types, function ($a, $b) use ($order) {
            $a_order = isset($order[$a]) ? $order[$a] : 99;
            $b_order = isset($order[$b]) ? $order[$b] : 99;
            return $a_order - $b_order;
        });

        return $types;
    }
}

// Initialize the plugin
new BugSquasher();
