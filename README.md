=== BugSquasher ===
Contributors: StellarPossible, Marine Valentonis
Tags: debug, log, errors, debugging, development, bugsquasher
Requires at least: 6.8.3
Tested up to: 6.8.3
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple plugin to filter WordPress debug.log files and show only errors, excluding notices, warnings, and deprecated messages.

== Description ==

BugSquasher is a lightweight WordPress plugin designed to help developers quickly identify and fix critical errors in their WordPress debug logs. Instead of sifting through hundreds of notices, warnings, and deprecated function calls, BugSquasher filters your debug.log to show only the errors that matter:

* Fatal Errors
* Parse Errors  
* Critical Errors
* General Errors

== Features ==

* **Clean Interface**: View filtered errors in a clean, organized admin interface
* **Real-time Filtering**: Toggle different error types on/off
* **Export Functionality**: Export filtered errors to a text file
* **Log Management**: Clear debug logs directly from the interface
* **File Size Monitoring**: See debug log file size at a glance
* **No Database Storage**: Works directly with your debug.log file

== Installation ==

1. Upload the `bugsquasher` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > BugSquasher to start using the plugin

== Requirements ==

* WordPress 6.8.3 or higher
* PHP 8.1 or higher  
* WordPress debug logging must be enabled (WP_DEBUG_LOG = true)
* User must have 'manage_options' capability

== Developer Information ==

**Published by:** StellarPossible LLC  
**Developed by:** Marine Valentonis  
**License:** GPL v2 or later  
**Plugin URI:** https://stellarpossible.com

== Usage ==

1. Go to Tools > BugSquasher in your WordPress admin
2. Click "Refresh Log" to load the latest errors
3. Use the filter checkboxes to show/hide different error types
4. Click "Export Errors" to download a text file of current errors
5. Use "Clear Log" to empty your debug.log file

== Changelog ==

= 1.0.1 =
* Enhanced UX with streamlined color palette design
* Added missing bug type notifications with expansion suggestions  
* Improved filtering logic with real-time feedback
* Updated default filters to focus on critical errors (Fatal, Critical, Errors, Firewall)
* Added visual indicators for missing error types in search results
* Enhanced CSS styling with modern color scheme

= 1.0.0 =
* Initial release
* Error filtering functionality
* Export and clear log features
* Real-time filtering interface