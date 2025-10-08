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
        'error_types_default' => ['fatal', 'critical', 'error', 'info'],
        'timestamp_conversion' => true,
        'rate_limit_requests' => 10,
        'rate_limit_window' => 60,
        // Security settings
        'enable_debug_toggles' => true,
        'require_development_env' => true,
        'disable_in_production' => true,
        'require_explicit_permission' => false,
        'allowed_environments' => ['development', 'staging'],
        'security_logging' => true
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
        add_action('wp_ajax_bugsquasher_toggle_debug', array($this, 'ajax_toggle_debug'));
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

        // Only enqueue JavaScript - CSS is embedded directly in admin_page() to avoid MIME issues
        wp_enqueue_script(
            'bugsquasher-admin',
            BUGSQUASHER_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            BUGSQUASHER_VERSION,
            true
        );

        // Localize script
        wp_localize_script('bugsquasher-admin', 'bugsquasher_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bugsquasher_nonce')
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
        <div class="bugsquasher-wrap">
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
            :root {
                --bs-main: #80A1BA;
                --bs-second: #91C4C3;
                --bs-accessory-1: #B4DEBD;
                --bs-accessory-2: #FFF7DD;
            }

            /* Dashboard Header Styles */
            .bugsquasher-dashboard-header {
                margin-bottom: 20px;
            }

            .bugsquasher-dashboard-header h1.wp-heading-inline {
                font-size: 23px;
                font-weight: 400;
                margin: 0;
                padding: 9px 0 4px 0;
                line-height: 1.3;
            }

            .bugsquasher-dashboard-header .wp-header-end {
                border: 0;
                height: 0;
                margin: -4px 0 16px;
            }

            /* App Wrapper Styles */
            .bugsquasher-app-wrapper {
                box-shadow: 0 4px 6px rgba(60, 70, 123, 0.1);
                background-color: #B4DEBD;
                padding: 5px;
                border-radius: 5px;
            }

            /* Header and Logo Styles */
            .bugsquasher-header {
                display: flex;
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: flex-start;
                gap: 15px;
                color: white;
                border-radius: 5px;
                margin-bottom: 5px;
                box-shadow: 0 4px 6px rgba(60, 70, 123, 0.1);
            }

            .bugsquasher-logo-container {
                display: flex;
                align-items: center;
                gap: 20px;
                flex: 1;
            }

            .spicon {
                max-width: 2.5rem;
                vertical-align: middle;
            }

            .bugsquasher-logo {
                max-width: 10rem;
                border-radius: 5px;
                object-fit: cover;
            }

            .bugsquasher-subtitle {
                margin: 0;
                font-size: 14px;
                opacity: 0.9;
                font-style: italic;
            }

            .bugsquasher-filters {
                background: white;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                box-shadow: 0 4px 8px rgba(145, 196, 195, 0.3);
            }

            .filter-header {
                margin-bottom: 15px;
            }

            .filter-title-container {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .bugsquasher-filters h3 {
                margin: 0;
                color: #80A1BA;
                font-weight: 600;
            }

            /* Select All/Deselect All Button Styles */
            .select-all-btn {
                position: relative;
                font-weight: 500;
                padding: 5px 12px !important;
                border-radius: 5px !important;
                border: 2px solid !important;
                transition: all 0.3s ease !important;
                cursor: pointer;
                min-width: 100px;
                text-align: center;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            /* Select All State (when some/none are selected) */
            .select-all-btn[data-state="select"] {
                background: #91C4C3 !important;
                border-color: #91C4C3 !important;
                color: white !important;
            }

            .select-all-btn[data-state="select"]:hover {
                background: #80A1BA !important;
                border-color: #80A1BA !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(128, 161, 186, 0.3);
            }

            /* Deselect All State (when all are selected) */
            .select-all-btn[data-state="deselect"] {
                background: #d63638 !important;
                border-color: #d63638 !important;
                color: white !important;
            }

            .select-all-btn[data-state="deselect"]:hover {
                background: #b32d2e !important;
                border-color: #b32d2e !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(214, 54, 56, 0.3);
            }

            /* Partial State (mixed selection) */
            .select-all-btn[data-state="partial"] {
                background: #B4DEBD !important;
                border-color: #B4DEBD !important;
                color: #2c3e50 !important;
            }

            .select-all-btn[data-state="partial"]:hover {
                background: #91C4C3 !important;
                border-color: #91C4C3 !important;
                color: white !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(145, 196, 195, 0.3);
            }

            /* Button text animation */
            .select-all-btn .btn-text {
                display: inline-block;
                transition: all 0.2s ease;
            }

            .filter-checkboxes {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 10px;
            }

            .bugsquasher-filters label {
                display: flex;
                align-items: center;
                margin: 0;
                padding: 5px;
                background: white;
                border-radius: 5px;
                transition: all 0.2s ease;
                color: #80A1BA;
                font-weight: 500;
                box-shadow: 0 4px 8px rgba(145, 196, 195, 0.3);
            }

            .bugsquasher-filters label:hover {
                background: #B4DEBD;
                border-color: #91C4C3;
                transform: translateY(-1px);
            }

            .bugsquasher-filters input[type="checkbox"] {
                margin-right: 8px;
                accent-color: #80A1BA;
                transform: scale(1.1);
            }

            @media (max-width: 768px) {
                .bugsquasher-logo-container {
                    flex-direction: column;
                    text-align: center;
                    gap: 15px;
                }

                .filter-header {
                    flex-direction: column;
                    align-items: stretch;
                    text-align: center;
                }

                .select-all-btn {
                    width: 100%;
                    margin-top: 10px;
                }

                .filter-checkboxes {
                    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                    gap: 8px;
                }
            }

            .bugsquasher-controls {
                background: #fff;
                padding: 15px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .bugsquasher-info {
                margin-left: auto;
                font-size: 13px;
                line-height: 1.3;
                max-width: 400px;
            }

            .bugsquasher-info small {
                font-size: 11px;
                line-height: 1.2;
                display: block;
                margin-top: 2px;
            }

            @media (max-width: 768px) {
                .bugsquasher-info {
                    margin-left: 0;
                    margin-top: 10px;
                    max-width: 100%;
                    font-size: 12px;
                }

                .bugsquasher-info small {
                    font-size: 10px;
                }
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

            .bugsquasher-debug-controls {
                background: #E7F3FF;
                padding: 15px;
                border: 1px solid #3C467B;
                border-radius: 6px;
                margin-bottom: 20px;
            }

            .bugsquasher-debug-controls h3 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #3C467B;
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .debug-toggle-container {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 15px;
            }

            .debug-toggle-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                position: relative;
            }

            .debug-toggle-item.debug-item-disabled {
                opacity: 0.6;
                background: #f5f5f5;
            }

            .debug-toggle-item small {
                position: absolute;
                bottom: -15px;
                left: 0;
                right: 0;
                text-align: center;
            }

            .debug-toggle-item label {
                font-weight: 500;
                color: #3C467B;
                margin: 0;
            }

            .debug-toggle-switch {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
            }

            .debug-toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .debug-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 24px;
            }

            .debug-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }

            input:checked+.debug-toggle-slider {
                background-color: #3C467B;
            }

            input:checked+.debug-toggle-slider:before {
                transform: translateX(26px);
            }

            input:disabled+.debug-toggle-slider {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .debug-confirmation-checkbox {
                margin: 10px 0 !important;
            }

            .debug-confirmation-checkbox input[type="checkbox"] {
                margin-right: 8px !important;
            }

            .permission-info-box {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 13px;
                line-height: 1.4;
            }

            .permission-info-box code {
                background: #f6f8fa;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: 'SFMono-Regular', Monaco, Consolas, monospace;
                font-size: 12px;
            }

            .debug-warning {
                background: #FFF3CD;
                border: 1px solid #FFEAA7;
                border-radius: 4px;
                padding: 10px;
                margin-top: 10px;
                font-size: 12px;
                color: #856404;
            }

            .bugsquasher-filters {
                background: white;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 10px;
            }

            .bugsquasher-filters h3 {
                margin-top: 0;
                margin-bottom: 10px;
                color: #3C467B;
                font-weight: 600;
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

            /* Toast Notification Styles */
            .bugsquasher-toast-container {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                pointer-events: none;
            }

            .bugsquasher-toast {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                padding: 12px 16px;
                margin-bottom: 8px;
                min-width: 280px;
                max-width: 400px;
                opacity: 0;
                transform: translateX(100%);
                transition: all 0.3s ease;
                pointer-events: auto;
                position: relative;
            }

            .bugsquasher-toast.show {
                opacity: 1;
                transform: translateX(0);
            }

            .bugsquasher-toast.success {
                border-left: 4px solid #00a32a;
            }

            .bugsquasher-toast.error {
                border-left: 4px solid #d63638;
            }

            .bugsquasher-toast.info {
                border-left: 4px solid #007cba;
            }

            .bugsquasher-toast-message {
                font-size: 14px;
                line-height: 1.4;
                margin: 0;
            }

            .bugsquasher-toast-close {
                position: absolute;
                top: 8px;
                right: 8px;
                background: none;
                border: none;
                font-size: 16px;
                color: #666;
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }

            .bugsquasher-toast-close:hover {
                color: #000;
            }

            /* Responsive styles for debug toggles */
            @media (max-width: 768px) {
                .debug-toggles-grid {
                    grid-template-columns: 1fr !important;
                }

                .debug-toggle-item {
                    padding: 15px 10px !important;
                }

                .debug-toggles-wrapper {
                    padding: 15px !important;
                }
            }

            @media (max-width: 480px) {
                .debug-toggles-grid {
                    gap: 10px !important;
                }

                .debug-toggle-item {
                    padding: 12px 8px !important;
                }

                .debug-toggle-item label {
                    font-size: 14px;
                }
            }

            /* Security Status Box in Header - Improved layout */
            .bugsquasher-header {
                display: flex;
                flex-direction: row;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: flex-start;
                gap: 15px;
                color: white;
                border-radius: 5px;
                margin-bottom: 5px;
                box-shadow: 0 4px 6px rgba(60, 70, 123, 0.1);
            }

            .bugsquasher-logo-container {
                display: flex;
                align-items: center;
                gap: 20px;
                flex: 1;
            }

            .bugsquasher-security-status {
                margin: 0;
                padding: 10px;
                background: rgba(240, 246, 252, 0.9);
                border-radius: 5px;
                font-size: 13px;
                line-height: 1.4;
                color: #333;
                flex: 1;
            }

            .bugsquasher-security-status strong {
                color: #0969da;
                display: block;
                margin-bottom: 5px;
            }

            .bugsquasher-security-status .status-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 3px;
                flex-wrap: wrap;
                gap: 5px;
            }

            .bugsquasher-security-status .status-label {
                color: #666;
                margin-right: 10px;
                min-width: 110px;
                font-weight: 500;
            }

            .bugsquasher-security-status .status-value {
                font-family: monospace;
                flex: 1;
            }

            .bugsquasher-security-status .security-badge {
                background: #f6f8fa;
                padding: 1px 5px;
                border-radius: 3px;
                font-family: monospace;
                font-size: 11px;
                display: inline-block;
                margin-right: 5px;
            }

            /* Responsive layout improvements */
            @media (max-width: 992px) {
                .bugsquasher-header {
                    flex-direction: column;
                }

                .bugsquasher-security-status .status-row {
                    margin-bottom: 5px;
                }

                .bugsquasher-security-status .status-label {
                    min-width: 100px;
                }
            }

            @media (max-width: 768px) {
                .bugsquasher-logo-container {
                    flex-direction: column;
                    text-align: center;
                    gap: 15px;
                }

                .bugsquasher-security-status {
                    font-size: 12px;
                }

                .bugsquasher-security-status .status-row {
                    flex-direction: column;
                    gap: 2px;
                }

                .bugsquasher-security-status .status-label {
                    font-weight: 600;
                    margin-bottom: 0;
                }

                .bugsquasher-security-status .status-value {
                    padding-left: 8px;
                }
            }
        </style>

        <!-- BugSquasher App Wrapper -->
        <div class="bugsquasher-app-wrapper">
            <!-- Toast Container -->
            <div class="bugsquasher-toast-container" id="toast-container"></div>

            <div class="bugsquasher-container">
                <div class="bugsquasher-header">
                    <div class="bugsquasher-logo-container">
                        <img src="<?php echo BUGSQUASHER_PLUGIN_URL; ?>assets/images/BugSquasherLogo.png" alt="BugSquasher Logo" class="bugsquasher-logo">
                        <div class="bugsquasher-title-section">
                            <p class="bugsquasher-subtitle">WordPress Debug Log Analyzer by <a title="StellarPossible" href="https://stellarpossible.com" target="_blank"><img class="spicon" src="<?php echo BUGSQUASHER_PLUGIN_URL; ?>assets/images/spicon.png" alt="SPiCon Logo"></a></p>
                        </div>
                    </div>

                    <?php
                    $config_info = $this->get_wp_config_security_info();
                    $is_production = $this->is_production_environment();
                    $is_development = $this->is_development_environment();
                    $is_staging = $this->is_staging_environment();
                    $environment_label = $is_production ? 'Production' : ($is_staging ? 'Staging' : ($is_development ? 'Development' : 'Unknown'));
                    $debug_log = $this->get_debug_log_path();
                    ?>

                    <!-- Security Status Information (moved to header) -->
                    <div class="bugsquasher-security-status">
                        <strong>📁 wp-config.php & Debug Log Security Status</strong>
                        <div class="status-row">
                            <span class="status-label">File:</span>
                            <span class="status-value"><?php echo $config_info['path']; ?></span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">Permissions:</span>
                            <span class="status-value">
                                <span class="security-badge"><?php echo $config_info['octal']; ?></span>
                                <span style="color: <?php echo $config_info['security_level'] === 'secure' ? '#00a32a' : ($config_info['security_level'] === 'unsafe' ? '#d63638' : '#dba617'); ?>;">
                                    <?php
                                    switch ($config_info['security_level']) {
                                        case 'secure':
                                            echo '🔒 Secure (Production)';
                                            break;
                                        case 'very_secure':
                                            echo '🔐 Very Secure (Production)';
                                            break;
                                        case 'development':
                                            echo '🔧 Development Mode';
                                            break;
                                        case 'unsafe':
                                            echo '⚠️ Unsafe Permissions';
                                            break;
                                        default:
                                            echo '❓ Unknown';
                                            break;
                                    }
                                    ?>
                                </span>
                            </span>
                        </div>
                        <div class="status-row">
                            <span class="status-label">Environment:</span>
                            <span class="status-value">
                                <span class="security-badge"><?php echo $environment_label; ?></span>
                                <?php if ($is_development): ?>
                                    <span style="color: #00a32a;">✓ Safe for debug modification</span>
                                <?php elseif ($is_staging): ?>
                                    <span style="color: #dba617;">⚠ Use caution</span>
                                <?php else: ?>
                                    <span style="color: #d63638;">🔒 Modification restricted</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="status-row" style="margin-top: 8px;">
                            <span class="status-label" style="color: #0969da;"><strong>🐛 Debug Log:</strong></span>
                            <span class="status-value">
                                <?php
                                if ($debug_log && file_exists($debug_log)) {
                                    $size = filesize($debug_log);
                                    $size_formatted = size_format($size);
                                    echo '<span style="color: #00a32a; font-weight: bold;">Found (' . $size_formatted . ')</span>';
                                } else {
                                    echo '<span style="color: #d63638; font-weight: bold;">' . ($debug_log ? 'Not found' : 'Not configured') . '</span>';
                                }
                                ?>
                            </span>
                        </div>
                        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                            <div class="status-row">
                                <span class="status-label">WP_DEBUG:</span>
                                <span class="status-value">
                                    <span style="color: #00a32a;">Enabled</span>
                                    <?php if ($debug_log): ?>
                                        | Path: <code style="background: #f6f8fa; padding: 1px 4px; border-radius: 2px;"><?php echo $debug_log; ?></code>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bugsquasher-debug-controls">
                    <h3>
                        <span>WordPress Debug Settings</span>
                        <small style="font-weight: normal; font-size: 12px; opacity: 0.8;">(Modifies wp-config.php)</small>
                    </h3>

                    <?php
                    // Security: Disable toggles based on file permissions and environment
                    $security_disabled = $is_production || $config_info['likely_production'];
                    $disabled_attr = (!$config_info['writable'] || $security_disabled) ? 'disabled' : '';
                    $disabled_class = (!$config_info['writable'] || $security_disabled) ? 'debug-item-disabled' : '';

                    // Add the warning at the very top, before any other content
                    ?>
                    <div class="bugsquasher-debug-warning" style="background-color: #FFF3CD; border: 2px solid #FFD700; border-radius: 6px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); color: #7d6608; font-size: 14px; position: relative;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 24px; color: #c59615; line-height: 1;">⚠️</span>
                            <div>
                                <strong style="font-size: 16px; display: block; margin-bottom: 5px;">WARNING: DEVELOPER ONLY ZONE</strong>
                                <span><strong>These settings modify your wp-config.php file. </strong></span>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Warning banners - now after the main warning
                    if ($security_disabled): ?>
                        <div class="bugsquasher-production-warning">
                            <strong>🔒 Production Environment Detected</strong><br>
                            <?php if ($config_info['octal'] === '644' || $config_info['octal'] === '600'): ?>
                                wp-config.php has secure permissions (<?php echo $config_info['octal']; ?>), indicating this is a production environment.<br>
                                <small>Debug setting modification is disabled for security reasons.</small>
                            <?php else: ?>
                                Debug setting modification is disabled for security reasons in production environments.<br>
                                <small>To modify debug settings, please use staging/development environment or edit wp-config.php manually.</small>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($config_info['writable'] && !$security_disabled): ?>
                        <div class="bugsquasher-development-warning">
                            <strong>⚠️ Development Environment Confirmation Required</strong><br>
                            wp-config.php is writable (<?php echo $config_info['octal']; ?>). Before enabling debug settings, please confirm:<br>
                            <div style="margin: 10px 0;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: normal;">
                                    <input type="checkbox" id="dev-environment-confirm" style="margin: 0;">
                                    <span>I confirm this is a development environment and it's safe to modify debug settings</span>
                                </label>
                            </div>
                            <small style="display: block; margin-top: 8px;">
                                <strong>If this is a development environment but you see permission errors:</strong><br>
                                Try: <code>chmod 664 <?php echo $config_info['path']; ?></code>
                            </small>
                        </div>
                    <?php elseif (!$config_info['writable']): ?>
                        <div class="bugsquasher-production-warning">
                            <strong>🔒 wp-config.php Not Writable</strong><br>
                            File permissions (<?php echo $config_info['octal']; ?>) prevent modification.
                            <?php if ($this->is_docker_environment()): ?>
                                <br><small>Docker detected. Try: <code>docker exec -it &lt;container&gt; chmod 664 <?php echo $config_info['path']; ?></code></small>
                            <?php else: ?>
                                <br><small>For development: <code>chmod 664 <?php echo $config_info['path']; ?></code></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="debug-toggle-container">
                        <!-- Debug Toggles Wrapper Box -->
                        <div class="debug-toggles-wrapper" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-top: 15px;">
                            <h4 style="margin: 0 0 15px 0; color: #3C467B; font-weight: 600;">Debug Settings Controls</h4>

                            <div class="debug-toggles-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
                                <div class="debug-toggle-item <?php echo $disabled_class; ?>">
                                    <label for="wp-debug-toggle">WP_DEBUG</label>
                                    <label class="debug-toggle-switch">
                                        <input type="checkbox" id="wp-debug-toggle"
                                            <?php echo (defined('WP_DEBUG') && WP_DEBUG) ? 'checked' : ''; ?>
                                            <?php echo $disabled_attr; ?>>
                                        <span class="debug-toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="debug-toggle-item <?php echo $disabled_class; ?>">
                                    <label for="wp-debug-log-toggle">WP_DEBUG_LOG</label>
                                    <label class="debug-toggle-switch">
                                        <input type="checkbox" id="wp-debug-log-toggle"
                                            <?php echo (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) ? 'checked' : ''; ?>
                                            <?php echo $disabled_attr; ?>>
                                        <span class="debug-toggle-slider"></span>
                                    </label>
                                </div>

                                <div class="debug-toggle-item <?php echo (!defined('WP_DEBUG') || !WP_DEBUG || !$config_info['writable']) ? 'debug-item-disabled' : ''; ?>">
                                    <label for="wp-debug-display-toggle">WP_DEBUG_DISPLAY</label>
                                    <label class="debug-toggle-switch">
                                        <input type="checkbox" id="wp-debug-display-toggle"
                                            <?php echo (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) ? 'checked' : ''; ?>
                                            <?php echo (!defined('WP_DEBUG') || !WP_DEBUG || !$config_info['writable']) ? 'disabled' : ''; ?>>
                                        <span class="debug-toggle-slider"></span>
                                    </label>
                                    <?php if (!$config_info['writable']): ?>
                                        <small style="color: #666; font-size: 11px;">wp-config.php not writable</small>
                                    <?php elseif (!defined('WP_DEBUG') || !WP_DEBUG): ?>
                                        <small style="color: #666; font-size: 11px;">Requires WP_DEBUG to be enabled</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php
                    $wp_config_path = ABSPATH . 'wp-config.php';
                    $config_writable = file_exists($wp_config_path) && is_writable($wp_config_path);
                    ?>

                </div>

                <div class="bugsquasher-filters">
                    <div class="filter-header">
                        <div class="filter-title-container">
                            <h3>Filter by Error Type</h3>
                            <button id="toggle-all-filters" class="button button-secondary select-all-btn" data-state="partial">
                                <span class="btn-text">Select All</span>
                            </button>
                        </div>
                    </div>
                    <div class="filter-checkboxes">
                        <label><input type="checkbox" class="error-type-filter" value="fatal" checked> Fatal Errors</label>
                        <label><input type="checkbox" class="error-type-filter" value="parse"> Parse Errors</label>
                        <label><input type="checkbox" class="error-type-filter" value="critical" checked> Critical</label>
                        <label><input type="checkbox" class="error-type-filter" value="error" checked> Errors</label>
                        <label><input type="checkbox" class="error-type-filter" value="warning"> Warnings</label>
                        <label><input type="checkbox" class="error-type-filter" value="notice"> Notices</label>
                        <label><input type="checkbox" class="error-type-filter" value="deprecated"> Deprecated</label>
                        <label><input type="checkbox" class="error-type-filter" value="firewall"> Firewall</label>
                        <label><input type="checkbox" class="error-type-filter" value="cron"> Cron</label>
                        <label><input type="checkbox" class="error-type-filter" value="info" checked> Info</label>
                    </div>
                </div>

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
            <!-- End BugSquasher App Wrapper -->
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
     * AJAX handler to toggle debug settings
     */
    public function ajax_toggle_debug()
    {
        check_ajax_referer('bugsquasher_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Enhanced security checks for production
        if ($this->is_production_environment()) {
            wp_send_json_error('Debug settings modification is disabled in production for security reasons. Please modify wp-config.php manually or use a staging environment.');
        }

        $setting = sanitize_text_field($_POST['setting']);
        $value = $_POST['value'] === 'true';

        // Additional security: Only allow debug enabling in development/staging
        if ($value && $this->is_staging_environment() && !$this->is_development_environment()) {
            error_log("BugSquasher: Warning - Debug enabled in staging environment by user: " . wp_get_current_user()->user_login);
        }

        // Log the request for debugging
        error_log("BugSquasher: Toggle request - Setting: $setting, Value: " . ($value ? 'true' : 'false'));

        $allowed_settings = ['WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY'];
        if (!in_array($setting, $allowed_settings)) {
            error_log("BugSquasher: Invalid setting attempted: $setting");
            wp_send_json_error('Invalid setting: ' . $setting);
        }

        $result = $this->update_wp_config_setting($setting, $value);

        error_log("BugSquasher: Update result - " . json_encode($result));

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'setting' => $setting,
                'value' => $value
            ]);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Update wp-config.php setting
     */
    private function update_wp_config_setting($setting, $value)
    {
        $wp_config_path = ABSPATH . 'wp-config.php';

        error_log("BugSquasher: Attempting to update $setting in wp-config.php at: $wp_config_path");

        if (!file_exists($wp_config_path)) {
            error_log("BugSquasher: wp-config.php not found at: $wp_config_path");
            return ['success' => false, 'message' => 'wp-config.php not found at expected location: ' . $wp_config_path];
        }

        // Get detailed file information for better error reporting
        $file_perms = fileperms($wp_config_path);
        $file_owner = fileowner($wp_config_path);
        $file_group = filegroup($wp_config_path);
        $current_user = get_current_user();

        if (!is_writable($wp_config_path)) {
            error_log("BugSquasher: wp-config.php is not writable");

            $error_message = 'wp-config.php is not writable. ';
            $error_message .= 'File permissions: ' . substr(sprintf('%o', $file_perms), -4) . '. ';

            // Provide specific suggestions based on environment
            if (defined('WP_ENV') && WP_ENV === 'development') {
                $error_message .= 'For development, try: chmod 664 ' . $wp_config_path;
            } elseif ($this->is_docker_environment()) {
                $error_message .= 'In Docker, try: docker exec -it <container> chown www-data:www-data ' . $wp_config_path . ' && docker exec -it <container> chmod 664 ' . $wp_config_path;
            } else {
                $error_message .= 'Contact your hosting provider or system administrator to adjust file permissions.';
            }

            return ['success' => false, 'message' => $error_message];
        }

        $config_content = file_get_contents($wp_config_path);
        if ($config_content === false) {
            error_log("BugSquasher: Could not read wp-config.php");
            return ['success' => false, 'message' => 'Could not read wp-config.php - file may be corrupted or access denied'];
        }

        $value_string = $value ? 'true' : 'false';
        $define_pattern = "/define\s*\(\s*['\"]" . preg_quote($setting, '/') . "['\"]\s*,\s*[^)]+\s*\);/";
        $new_define = "define('" . $setting . "', " . $value_string . ");";

        error_log("BugSquasher: Looking for pattern: $define_pattern");
        error_log("BugSquasher: Will replace with: $new_define");

        if (preg_match($define_pattern, $config_content)) {
            // Setting exists, replace it
            error_log("BugSquasher: Found existing $setting definition, replacing");
            $config_content = preg_replace($define_pattern, $new_define, $config_content);
        } else {
            // Setting doesn't exist, add it
            error_log("BugSquasher: $setting not found, adding new definition");
            // Find the line with the database settings and add after it
            $insertion_point = "/* That's all, stop editing! Happy publishing. */";
            if (strpos($config_content, $insertion_point) !== false) {
                $config_content = str_replace(
                    $insertion_point,
                    $new_define . "\n\n" . $insertion_point,
                    $config_content
                );
            } else {
                // Fallback: add before the closing PHP tag or at the end
                if (strpos($config_content, '?>') !== false) {
                    $config_content = str_replace('?>', $new_define . "\n?>", $config_content);
                } else {
                    $config_content .= "\n" . $new_define . "\n";
                }
            }
        }

        $backup_result = $this->backup_wp_config();
        if (!$backup_result['success']) {
            return $backup_result;
        }

        if (file_put_contents($wp_config_path, $config_content) === false) {
            return ['success' => false, 'message' => 'Could not write to wp-config.php'];
        }

        return [
            'success' => true,
            'message' => $setting . ' has been ' . ($value ? 'enabled' : 'disabled') . '. Changes will take effect on next page load.'
        ];
    }

    /**
     * Backup wp-config.php before making changes
     */
    private function backup_wp_config()
    {
        $wp_config_path = ABSPATH . 'wp-config.php';
        $backup_path = ABSPATH . 'wp-config-backup-' . date('Y-m-d-H-i-s') . '.php';

        if (!copy($wp_config_path, $backup_path)) {
            return ['success' => false, 'message' => 'Could not create backup of wp-config.php'];
        }

        return ['success' => true, 'message' => 'Backup created at ' . basename($backup_path)];
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
        // More flexible timestamp pattern to catch various formats
        $timestamp_pattern = '/^\[[\d\-\w\s:]+\]|^\d{4}-\d{2}-\d{2}|^\[\d{2}-[A-Za-z]{3}-\d{4}/';
        $processed_count = 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Parsing ' . count($lines) . ' lines, max errors: ' . $max_errors);
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Check if this line starts a new log entry (has timestamp) or if it's the first line
            if (preg_match($timestamp_pattern, $line) || empty($current_entry)) {
                // Process previous entry if it exists
                if (!empty($current_entry)) {
                    $processed = $this->process_log_entry($current_entry);
                    if ($processed) {
                        $errors[] = $processed;
                    }
                    $processed_count++;
                }

                // Start new entry
                $current_entry = $line;
            } else {
                // Continue previous entry (multi-line)
                $current_entry .= "\n" . $line;
            }
        }

        // Process the last entry
        if (!empty($current_entry)) {
            $processed = $this->process_log_entry($current_entry);
            if ($processed) {
                $errors[] = $processed;
            }
            $processed_count++;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Processed ' . $processed_count . ' entries, found ' . count($errors) . ' total entries');
        }

        // Reverse to show most recent first, then limit to max_errors
        $reversed_errors = array_reverse($errors);
        return array_slice($reversed_errors, 0, $max_errors);
    }

    /**
     * Enhanced process_log_entry with timestamp formatting
     */
    private function process_log_entry($entry)
    {
        // Don't filter out any entries - let them all be categorized
        // Extract and format timestamp - try multiple patterns
        $timestamp = '';

        // Try different timestamp patterns
        if (preg_match('/^\[([^\]]+)\]/', $entry, $matches)) {
            // Format: [timestamp]
            $raw_timestamp = $matches[1];
            $timestamp = $this->format_timestamp($raw_timestamp);
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $entry, $matches)) {
            // Format: YYYY-MM-DD HH:MM:SS
            $timestamp = $matches[1];
        } elseif (preg_match('/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $entry, $matches)) {
            // Format: Mon DD HH:MM:SS
            $timestamp = date('Y') . ' ' . $matches[1];
        } else {
            // No timestamp found, use current time
            $timestamp = date('Y-m-d H:i:s');
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
        } elseif (preg_match('/\berror\b|\bfailed\b|\bexception\b|\binvalid\b|\bunable\b/i', $line)) {
            // Look for actual error keywords in the message
            return 'error';
        } else {
            // All other logs default to info
            return 'info';
        }
    }

    /**
     * Check if running in Docker environment
     */
    private function is_docker_environment()
    {
        // Check for common Docker indicators
        return file_exists('/.dockerenv') ||
            (function_exists('gethostname') && strpos(gethostname(), 'docker') !== false) ||
            isset($_ENV['DOCKER_CONTAINER']) ||
            (function_exists('shell_exec') && strpos(shell_exec('cat /proc/1/cgroup 2>/dev/null'), 'docker') !== false);
    }

    /**
     * Get wp-config.php file permissions and security assessment
     */
    private function get_wp_config_security_info()
    {
        $wp_config_path = ABSPATH . 'wp-config.php';

        if (!file_exists($wp_config_path)) {
            return [
                'exists' => false,
                'permissions' => null,
                'octal' => null,
                'writable' => false,
                'likely_production' => true,
                'security_level' => 'unknown'
            ];
        }

        $perms = fileperms($wp_config_path);
        $octal = substr(sprintf('%o', $perms), -3);
        $writable = is_writable($wp_config_path);

        // Determine security level and production likelihood based on permissions
        $likely_production = false;
        $security_level = 'unknown';

        switch ($octal) {
            case '644':
                $likely_production = true;
                $security_level = 'secure';
                break;
            case '664':
                $likely_production = false;
                $security_level = 'development';
                break;
            case '666':
                $likely_production = false;
                $security_level = 'unsafe';
                break;
            case '600':
                $likely_production = true;
                $security_level = 'very_secure';
                break;
            default:
                $likely_production = !$writable;
                $security_level = $writable ? 'development' : 'secure';
        }

        return [
            'exists' => true,
            'permissions' => $perms,
            'octal' => $octal,
            'writable' => $writable,
            'likely_production' => $likely_production,
            'security_level' => $security_level,
            'path' => $wp_config_path
        ];
    }

    /**
     * Check if running in production environment
     */
    private function is_production_environment()
    {
        $config_info = $this->get_wp_config_security_info();

        // Primary indicator: wp-config.php permissions
        if ($config_info['likely_production']) {
            return true;
        }

        // Secondary checks for production environment
        $indicators = [
            // Environment variables
            (defined('WP_ENV') && WP_ENV === 'production'),
            (isset($_ENV['WP_ENV']) && $_ENV['WP_ENV'] === 'production'),
            (isset($_ENV['ENVIRONMENT']) && $_ENV['ENVIRONMENT'] === 'production'),

            // Domain-based detection
            (isset($_SERVER['HTTP_HOST']) && !preg_match('/localhost|127\.0\.0\.1|\.local|\.test|\.dev/i', $_SERVER['HTTP_HOST'])),

            // SSL in production assumption
            (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),

            // WP_DEBUG disabled (common in production)
            (!defined('WP_DEBUG') || !WP_DEBUG),
        ];

        // Require multiple indicators for production detection when file permissions are not secure
        $production_score = array_sum($indicators);
        return $production_score >= 3;
    }

    /**
     * Check if running in development environment
     */
    private function is_development_environment()
    {
        $dev_indicators = [
            (defined('WP_ENV') && WP_ENV === 'development'),
            (isset($_ENV['WP_ENV']) && $_ENV['WP_ENV'] === 'development'),
            (defined('WP_DEBUG') && WP_DEBUG === true),
            (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY === true),
            (isset($_SERVER['HTTP_HOST']) && preg_match('/localhost|127\.0\.0\.1|\.local|\.test|\.dev/i', $_SERVER['HTTP_HOST'])),
            file_exists(ABSPATH . 'composer.json'),
            is_plugin_active('query-monitor/query-monitor.php'),
        ];

        return array_sum($dev_indicators) >= 2;
    }

    /**
     * Check if running in staging environment
     */
    private function is_staging_environment()
    {
        $staging_indicators = [
            (defined('WP_ENV') && WP_ENV === 'staging'),
            (isset($_ENV['WP_ENV']) && $_ENV['WP_ENV'] === 'staging'),
            (isset($_SERVER['HTTP_HOST']) && preg_match('/staging|stage|test/i', $_SERVER['HTTP_HOST'])),
            (defined('WP_DEBUG') && WP_DEBUG === true && (!defined('WP_DEBUG_DISPLAY') || !WP_DEBUG_DISPLAY)),
        ];

        return array_sum($staging_indicators) >= 1;
    }
}

// Initialize the plugin
new BugSquasher();
