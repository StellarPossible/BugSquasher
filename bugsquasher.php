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
        'max_errors_display' => 3000,
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

        // Register an actual Settings page under Tools so the settings button always works
        add_submenu_page(
            'tools.php',                 // parent: Tools
            'BugSquasher Settings',
            'BugSquasher Settings',
            'manage_options',
            'bugsquasher-settings',
            array($this, 'settings_page')
        );
    }

    public function enqueue_admin_assets($hook)
    {
        // Load assets on both the main page and the settings page
        if ($hook !== 'tools_page_bugsquasher' && $hook !== 'tools_page_bugsquasher-settings') {
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
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('bugsquasher_nonce'),
            // Pass export format to the client (detailed | compact | markdown_table | markdown_list)
            'export_format' => isset($current_settings['export_format']) ? $current_settings['export_format'] : 'detailed',
            // New: default load count from settings
            'default_limit' => isset($current_settings['max_errors_display']) ? (int) $current_settings['max_errors_display'] : 25,
            // New: settings page url for use in UI if needed
            'settings_url'  => admin_url('admin.php?page=bugsquasher-settings'),
        ));
    }

    /**
     * Settings page implementation
     */
    public function settings_page()
    {
        // Handle form submission
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['bugsquasher_settings_nonce'], 'bugsquasher_settings')) {
            // Clamp default errors displayed to 25–3000
            $max_errors = isset($_POST['max_errors_display']) ? max(25, min(3000, intval($_POST['max_errors_display']))) : 25;

            $new_settings = [
                'timezone' => sanitize_text_field($_POST['timezone']),
                'date_format' => sanitize_text_field($_POST['date_format']),
                'display_timezone' => isset($_POST['display_timezone']),
                'timestamp_conversion' => isset($_POST['timestamp_conversion']),
                'max_errors_display' => $max_errors,
                'cache_duration' => intval($_POST['cache_duration']),
                'export_format' => sanitize_text_field($_POST['export_format'])
            ];

            BugSquasher_Config::save_settings($new_settings);

            // Redirect back to the main page with a success flag
            wp_safe_redirect( admin_url('tools.php?page=bugsquasher&bs_saved=1') );
            exit;
        }

        $current_settings = get_option('bugsquasher_settings', BugSquasher_Config::get_default_settings());
?>
        <div class="wrap">
            <!-- Small logo that links back to the main page -->
            <div style="margin: 10px 0;">
                <a href="<?php echo esc_url( admin_url('tools.php?page=bugsquasher') ); ?>" title="Back to BugSquasher">
                    <img src="<?php echo esc_url( BUGSQUASHER_PLUGIN_URL . 'assets/images/BugSquasherLogo.png' ); ?>" alt="BugSquasher" style="height:40px; vertical-align:middle;">
                </a>
            </div>

            <?php
            // Debug Status (moved from header, excluding the "Debug log: Found" chip)
            $debug_log   = is_string(WP_DEBUG_LOG ?? null) ? WP_DEBUG_LOG : (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? WP_CONTENT_DIR . '/debug.log' : false);
            $wp_debug_enabled   = (defined('WP_DEBUG') && WP_DEBUG);
            $log_errors_enabled = (bool) ini_get('log_errors');
            $php_log            = (string) ini_get('error_log');
            ?>
            <div class="bugsquasher-security-status" style="margin-bottom:12px;">
                <strong>Debug Status</strong>
                <div class="debug-status-container">
                    <!-- Excluded: Debug log: Found (remains in header) -->
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
                            <input type="number" name="max_errors_display" value="<?php echo esc_attr($current_settings['max_errors_display']); ?>" min="25" max="3000">
                            <p class="description">Number of errors to display by default (25-3000).</p>
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
            <?php
            // Show success notice after saving settings and redirecting back
            if (!empty($_GET['bs_saved'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>';
            }
            ?>
            <div class="bugsquasher-header">
                <div class="bugsquasher-logo-container">
                    <!-- Mobile-only powered by shown above the logo in column layout -->
                    <div class="bugsquasher-powered-by bugsquasher-powered-by--mobile">
                        Powered by
                        <a href="https://stellarpossible.com" target="_blank" rel="noopener">
                            <img src="<?php echo BUGSQUASHER_PLUGIN_URL . 'assets/images/spicon.png'; ?>" alt="StellarPossible" class="spicon" />
                        </a>
                    </div>
                    <!-- Make the header logo link back to the main page -->
                    <a href="<?php echo esc_url( admin_url('tools.php?page=bugsquasher') ); ?>" title="BugSquasher Home">
                        <img
                            src="<?php echo BUGSQUASHER_PLUGIN_URL . 'assets/images/BugSquasherLogo.png'; ?>"
                            alt="BugSquasher Logo"
                            class="bugsquasher-logo" />
                    </a>
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

                <!-- New: bottom row in header for debug card (left) and icons (right) -->
                <div class="bugsquasher-header-bottom-row">
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
                        </div>
                    </div>
                    <div class="bugsquasher-header-actions">
                        <a href="<?php echo esc_url( admin_url('admin.php?page=bugsquasher-settings') ); ?>" class="icon-btn" title="Settings" aria-label="Settings">
                            <span class="dashicons dashicons-admin-generic"></span>
                        </a>
                        <a href="https://stellarpossible.com/products/bugsquasher/" target="_blank" rel="noopener" class="icon-btn" title="Help" aria-label="Help">
                            <span class="dashicons dashicons-editor-help"></span>
                        </a>
                    </div>
                </div>
                <?php // ...existing code... ?>
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
                                <!-- Removed settings and help icons from here -->
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
                            <button id="load-errors" class="button button-primary">Load Recent</button>
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

                    function renderFilterButtons(types) {
                        var container = $('#filter-buttons-container');
                        if (!container.length) return;
                        var html = '';
                        types.forEach(function(type) {
                            html += '<button type="button" class="error-type-filter-btn" data-type="' + type + '">' + type + '</button>';
                        });
                        container.html(html);
                        $(document).trigger('bugsquasher:filtersRendered');
                    }

                    function renderErrors(errors) {
                        var container = $('#log-content');
                        if (!container.length) return;
                        var html = '';
                        errors.forEach(function(err) {
                            html += '<div class="log-entry ' + err.type + '">' +
                                '<div class="log-entry-header">' +
                                (err.timestamp ? '<span class="log-entry-timestamp">' + err.timestamp + '</span>' : '') +
                                '<span class="log-entry-type ' + err.type + '">' + err.type + '</span>' +
                                '</div>' +
                                '<div class="log-entry-message">' + err.message + '</div>' +
                                '</div>';
                        });
                        container.html(html);
                        container.show();
                    }

                    function updateCardsAndFilters() {
                        var limitEl = document.getElementById('error-limit');
                        var limit = limitEl ? parseInt(limitEl.value, 10) || 25 : 25;
                        fetchErrors(limit).done(function(resp) {
                            if (resp && resp.success && resp.data) {
                                allTypes = resp.data.error_types || [];
                                allErrors = resp.data.errors || [];
                                renderStatCards(allTypes, allErrors);
                                renderFilterButtons(allTypes);
                                renderErrors(allErrors);

                                // NEW: Update the header "Debug log: Found (size)" chip using returned file_size
                                try {
                                    var size = resp.data.file_size;
                                    var chip = document.querySelector('.debug-status-container .status-chip');
                                    if (chip && size) {
                                        chip.textContent = 'Debug log: Found (' + size + ')';
                                        chip.classList.remove('bad');
                                        chip.classList.add('ok');
                                    }
                                } catch (e) {}
                            }
                        });
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
                        var filtered = [];
                        if (activeTypes.length === 0) {
                            filtered = allErrors;
                        } else {
                            filtered = allErrors.filter(function(err) {
                                return activeTypes.indexOf(err.type) !== -1;
                            });
                        }
                        renderErrors(filtered);
                        $('#error-count').text(filtered.length + ' errors shown');
                    }

                    // NEW: Clear Log handler -> clear server-side then refresh everything client-side
                    $(document).on('click', '#clear-log', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        $btn.prop('disabled', true);

                        $.post(bugsquasher_ajax.ajax_url, {
                            action: 'bugsquasher_clear_log',
                            nonce: bugsquasher_ajax.nonce
                        }).done(function(resp) {
                            if (resp && resp.success) {
                                // Reset visible areas immediately
                                $('#log-content').empty();
                                $('#missing-types-notification').empty();
                                $('#error-count').text('Log cleared.');
                                $('#filter-buttons-container').html('<p>Load errors to see available filters.</p>');

                                // Refresh stat cards and any listeners bound to load-errors
                                updateCardsAndFilters();
                                $('#load-errors').trigger('click');

                                // Let any external script know
                                $(document).trigger('bugsquasher:logCleared');

                                // Toast success
                                try { showToast('success', 'Debug log cleared.'); } catch (e) {}
                            } else {
                                // Toast failure
                                try { showToast('error', (resp && resp.data) ? resp.data : 'Failed to clear debug log'); } catch (e) {}
                            }
                        }).fail(function() {
                            try { showToast('error', 'Failed to clear debug log'); } catch (e) {}
                        }).always(function() {
                            $btn.prop('disabled', false);
                        });
                    });

                    // NEW: Minimal toast helper leveraging existing CSS
                    function showToast(type, message) {
                        var container = document.getElementById('toast-container');
                        if (!container) return;

                        var toast = document.createElement('div');
                        toast.className = 'bugsquasher-toast ' + (type || 'info');

                        var msg = document.createElement('p');
                        msg.className = 'bugsquasher-toast-message';
                        msg.textContent = message;

                        var close = document.createElement('button');
                        close.className = 'bugsquasher-toast-close';
                        close.setAttribute('aria-label', 'Close');
                        close.innerHTML = '×';
                        close.addEventListener('click', function() {
                            if (toast.parentNode === container) container.removeChild(toast);
                        });

                        toast.appendChild(msg);
                        toast.appendChild(close);
                        container.appendChild(toast);

                        requestAnimationFrame(function() {
                            toast.classList.add('show');
                        });

                        setTimeout(function() {
                            if (toast.parentNode === container) container.removeChild(toast);
                        }, 3000);
                    }

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

        // Get limit from request (fallback to saved setting)
        $default_limit = (int) BugSquasher_Config::get_setting('max_errors_display', 25);
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : $default_limit;
        // Clamp to supported range
        $limit = max(25, min(3000, $limit));

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

        // FIX: Collect all error types from parsed errors
        $error_types = [];
        foreach ($errors as $err) {
            if (isset($err['type']) && !in_array($err['type'], $error_types, true)) {
                $error_types[] = $err['type'];
            }
        }
        $sorted_error_types = $this->sort_error_types($error_types);

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
        $timestamp_pattern = '/^\[\d{2}-[A-Zael]{3}-\d{4} \d{2}:\d{2}:\d{2} [A-Z]{3,4}\]/';
        $processed_count = 0;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BugSquasher: Parsing ' . count($lines) . ' lines, max errors: ' . $max_errors);
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

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
                // If there is no current entry, treat this line as a standalone entry
                if (empty($current_entry)) {
                    $processed = $this->process_log_entry($line);
                    if ($processed && count($errors) < $max_errors) {
                        $errors[] = $processed;
                    }
                    $processed_count++;
                    $current_entry = '';
                } else {
                    // Continue previous entry
                    $current_entry .= "\n" . $line;
                }
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
