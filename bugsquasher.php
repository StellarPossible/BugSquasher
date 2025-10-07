<?php

/**
 * Plugin Name: BugSquasher
 * Plugin URI: https://stellarpossible.com
 * Description: A simple plugin to filter WordPress debug.log files and show only errors, excluding notices, warnings, and deprecated messages.
 * Version: 1.1.0
 * Author: StellarPossible LLC
 * License: GPL v2 or later
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
        'error_types_default' => ['fatal', 'parse', 'critical'],
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
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'tools_page_bugsquasher') {
            return;
        }

        // Only enqueue JavaScript - CSS will be embedded directly to avoid MIME issues
        wp_enqueue_script('bugsquasher-admin', BUGSQUASHER_PLUGIN_URL . 'assets/admin.js', array('jquery'), BUGSQUASHER_VERSION, true);

        wp_localize_script('bugsquasher-admin', 'bugsquasher_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bugsquasher_nonce')
        ));

        // Explicitly remove any potential CSS that might be auto-enqueued
        wp_deregister_style('bugsquasher-admin');
        wp_deregister_style('bugsquasher');
    }

    /**
     * Prevent any CSS from being auto-loaded for our plugin
     */
    public function prevent_css_loading($hook)
    {
        if ($hook !== 'tools_page_bugsquasher') {
            return;
        }

        // Remove any potential CSS files that WordPress might try to auto-load
        global $wp_styles;
        if (isset($wp_styles->registered['bugsquasher-admin'])) {
            wp_deregister_style('bugsquasher-admin');
        }
        if (isset($wp_styles->registered['bugsquasher'])) {
            wp_deregister_style('bugsquasher');
        }
    }

    /**
     * Admin page content
     */
    public function admin_page()
    {
    ?>
        <style type="text/css">
            /* BugSquasher Admin Styles - Embedded to avoid MIME issues */
            .bugsquasher-container {
                max-width: 1200px;
                margin: 20px 0;
            }

            .bugsquasher-controls {
                background: #fff;
                padding: 15px;
                border: 1px solid #ccd0d4;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .bugsquasher-info {
                margin-left: auto;
            }

            .status-enabled {
                color: #00a32a;
                font-weight: bold;
            }

            .status-disabled,
            .status-not-found {
                color: #d63638;
                font-weight: bold;
            }

            .bugsquasher-filters {
                background: #FEF3E2;
                padding: 15px;
                border: 1px solid #3C467B;
                border-radius: 6px;
                margin-bottom: 20px;
            }

            .bugsquasher-filters h3 {
                margin-top: 0;
                margin-bottom: 10px;
                color: #3C467B;
                font-weight: 600;
            }

            .bugsquasher-filters label {
                display: inline-block;
                margin-right: 15px;
                margin-bottom: 5px;
                color: #3C467B;
            }

            /* Missing types notification */
            .missing-types-notification {
                background: linear-gradient(135deg, #6E8CFB, #636CCB);
                color: white;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 15px;
                border-left: 4px solid #3C467B;
                box-shadow: 0 2px 4px rgba(60, 70, 123, 0.1);
            }

            .missing-types-notification h4 {
                margin: 0 0 8px 0;
                font-size: 16px;
                font-weight: 600;
            }

            .missing-types-notification p {
                margin: 0;
                font-size: 14px;
                opacity: 0.95;
            }

            .missing-types-notification .expand-suggestion {
                background: rgba(255, 255, 255, 0.2);
                padding: 8px 12px;
                border-radius: 4px;
                margin-top: 10px;
                font-size: 13px;
                border: 1px solid rgba(255, 255, 255, 0.3);
            }

            .bugsquasher-log-container {
                background: #fff;
                border: 1px solid #ccd0d4;
                min-height: 400px;
            }

            #error-count {
                padding: 10px 15px;
                background: #f6f7f7;
                border-bottom: 1px solid #ccd0d4;
                font-weight: bold;
            }

            #log-content {
                padding: 15px;
                font-family: monospace;
                font-size: 13px;
                line-height: 1.5;
                max-height: 600px;
                overflow-y: auto;
            }

            .log-entry {
                margin-bottom: 15px;
                padding: 10px;
                border-left: 4px solid #ccc;
                background: #f9f9f9;
            }

            .log-entry.fatal {
                border-left-color: #d63638;
                background: #fef2f2;
            }

            .log-entry.parse {
                border-left-color: #d63638;
                background: #fef2f2;
            }

            .log-entry.critical {
                border-left-color: #d63638;
                background: #fef2f2;
            }

            .log-entry.warning {
                border-left-color: #f56e28;
                background: #fef7f0;
            }

            .log-entry.notice {
                border-left-color: #007cba;
                background: #f0f8ff;
            }

            .log-entry.deprecated {
                border-left-color: #8c8f94;
                background: #f6f7f7;
            }

            .log-entry.firewall {
                border-left-color: #d63638;
                background: #fef2f2;
            }

            .log-entry.cron {
                border-left-color: #dba617;
                background: #fffbf0;
            }

            .log-entry.info {
                border-left-color: #72aee6;
                background: #f0f6fc;
            }

            .log-entry.error {
                border-left-color: #dba617;
                background: #fffbf0;
            }

            .log-entry-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 5px;
                font-size: 11px;
                color: #666;
            }

            .log-entry-type {
                background: #666;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
                text-transform: uppercase;
            }

            .log-entry-type.fatal,
            .log-entry-type.parse,
            .log-entry-type.critical,
            .log-entry-type.firewall {
                background: #d63638;
            }

            .log-entry-type.warning {
                background: #f56e28;
            }

            .log-entry-type.notice {
                background: #007cba;
            }

            .log-entry-type.deprecated {
                background: #8c8f94;
            }

            .log-entry-type.info {
                background: #72aee6;
            }

            .log-entry-type.cron,
            .log-entry-type.error {
                background: #dba617;
            }

            .log-entry-message {
                word-break: break-all;
                white-space: pre-wrap;
            }

            .no-errors {
                text-align: center;
                padding: 40px;
                color: #666;
            }

            .no-errors .dashicons {
                font-size: 48px;
                margin-bottom: 10px;
                color: #00a32a;
            }

            #loading {
                text-align: center;
                padding: 40px;
            }
        </style>

        <div class="wrap">
            <h1>Bug Squasher</h1>

            <div class="bugsquasher-container">
                <div class="bugsquasher-controls">
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

                    <div class="bugsquasher-info">
                        <?php
                        $debug_log = $this->get_debug_log_path();
                        if ($debug_log && file_exists($debug_log)) {
                            $size = filesize($debug_log);
                            $size_formatted = size_format($size);
                            echo '<span class="status-enabled">Debug log found (' . $size_formatted . ')</span>';

                            // Show debug info if WP_DEBUG is enabled
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                echo '<br><small>WP_DEBUG: Enabled | Path: ' . $debug_log . '</small>';
                                echo '<br><small>PHP Error Log: ' . ini_get('error_log') . '</small>';
                                echo '<br><small>Log Errors: ' . (ini_get('log_errors') ? 'On' : 'Off') . '</small>';
                            }
                        } else {
                            if (!$debug_log) {
                                echo '<span class="status-not-found">Debug log not configured</span>';
                                if (defined('WP_DEBUG') && WP_DEBUG) {
                                    echo '<br><small>WP_DEBUG: Enabled</small>';
                                    echo '<br><small>WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled') . '</small>';
                                    echo '<br><small>Set WP_DEBUG_LOG to true in wp-config.php to enable logging</small>';
                                }
                            } else {
                                echo '<span class="status-not-found">Debug log not found</span>';
                                if (defined('WP_DEBUG') && WP_DEBUG) {
                                    echo '<br><small>WP_DEBUG: Enabled | Expected at: ' . $debug_log . '</small>';
                                    echo '<br><small>WP_DEBUG_LOG: ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled') . '</small>';
                                    echo '<br><small>Check your wp-config.php settings</small>';
                                }
                            }
                        }
                        ?>
                    </div>
                </div>

                <div class="bugsquasher-filters">
                    <h3>Filter by Error Type</h3>
                    <label><input type="checkbox" class="error-type-filter" value="fatal" checked> Fatal Errors</label>
                    <label><input type="checkbox" class="error-type-filter" value="parse"> Parse Errors</label>
                    <label><input type="checkbox" class="error-type-filter" value="critical" checked> Critical</label>
                    <label><input type="checkbox" class="error-type-filter" value="error" checked> Errors</label>
                    <label><input type="checkbox" class="error-type-filter" value="warning"> Warnings</label>
                    <label><input type="checkbox" class="error-type-filter" value="notice"> Notices</label>
                    <label><input type="checkbox" class="error-type-filter" value="deprecated"> Deprecated</label>
                    <label><input type="checkbox" class="error-type-filter" value="firewall" checked> Firewall</label>
                    <label><input type="checkbox" class="error-type-filter" value="cron"> Cron</label>
                    <label><input type="checkbox" class="error-type-filter" value="info"> Info</label>
                </div>

                <div class="bugsquasher-log-container">
                    <div id="error-count">Click "Load Recent Errors" to start</div>
                    <div id="missing-types-notification" style="display: none;"></div>
                    <div id="log-content" style="display: none;"></div>
                    <div id="loading" style="display: none; text-align: center; padding: 40px;">
                        <p>Loading errors...</p>
                    </div>
                </div>
            </div>
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
     * Get debug log status
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
        $cached_errors = get_transient($cache_key);

        if ($cached_errors !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BugSquasher: Using cached results');
            }
            wp_send_json_success([
                'errors' => $cached_errors,
                'count' => count($cached_errors),
                'file_size' => $this->format_file_size(filesize($log_file)),
                'cached' => true
            ]);
        }

        $errors = $this->parse_debug_log($limit);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Found ' . count($errors) . ' errors');
        }

        // Cache results for 5 minutes
        set_transient($cache_key, $errors, 300);

        wp_send_json_success(array(
            'errors' => $errors,
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
     * Enhanced process_log_entry with timestamp formatting
     */
    private function process_log_entry($entry)
    {
        // Quick check - if it doesn't contain common error keywords, skip it
        if (!preg_match('/(Fatal|Parse|Warning|Notice|Deprecated|CRITICAL|AIOS|Cron)/i', $entry)) {
            return null;
        }

        // Check if entry contains error patterns
        $error_patterns = [
            '/Fatal error|PHP Fatal error/',
            '/Parse error|PHP Parse error/',
            '/Warning|PHP Warning/',
            '/Notice|PHP Notice/',
            '/Deprecated|PHP Deprecated/',
            '/CRITICAL/',
            '/AIOS firewall error/',
            '/Cron .* error/'
        ];

        $is_error = false;
        foreach ($error_patterns as $pattern) {
            if (preg_match($pattern, $entry)) {
                $is_error = true;
                break;
            }
        }

        if (!$is_error) {
            return null;
        }

        // Extract and format timestamp
        $timestamp = '';
        if (preg_match('/^\[([^\]]+)\]/', $entry, $matches)) {
            $raw_timestamp = $matches[1];
            $timestamp = $this->format_timestamp($raw_timestamp);
        }

        return [
            'timestamp' => $timestamp,
            'type' => $this->get_error_type($entry),
            'message' => trim($entry)
        ];
    }

    /**
     * Get error type from log line
     */
    private function get_error_type($line)
    {
        if (preg_match('/Fatal error|PHP Fatal error/', $line)) {
            return 'fatal';
        } elseif (preg_match('/Parse error|PHP Parse error/', $line)) {
            return 'parse';
        } elseif (preg_match('/Warning|PHP Warning/', $line)) {
            return 'warning';
        } elseif (preg_match('/Notice|PHP Notice/', $line)) {
            return 'notice';
        } elseif (preg_match('/Deprecated|PHP Deprecated/', $line)) {
            return 'deprecated';
        } elseif (preg_match('/CRITICAL/', $line)) {
            return 'critical';
        } elseif (preg_match('/AIOS firewall error/', $line)) {
            return 'firewall';
        } elseif (preg_match('/Cron .* error/', $line)) {
            return 'cron';
        } elseif (preg_match('/\[BugSquasher\]/', $line)) {
            return 'info';
        } else {
            return 'error';
        }
    }
}

// Initialize the plugin
new BugSquasher();
