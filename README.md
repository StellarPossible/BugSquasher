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
* **Smart Debug Settings Control**: Toggle WP_DEBUG, WP_DEBUG_LOG, and WP_DEBUG_DISPLAY with environment-aware security
* **Production Security**: Automatically disables debug modification in production environments
* **Environment Detection**: Automatically detects development, staging, and production environments
* **Export Functionality**: Export filtered errors to a text file
* **Log Management**: Clear debug logs directly from the interface
* **File Size Monitoring**: See debug log file size at a glance
* **Comprehensive Log Categorization**: All logs are categorized (Fatal, Parse, Warning, Notice, Deprecated, Critical, Firewall, Cron, Error, Info)
* **No Database Storage**: Works directly with your debug.log file
* **Docker Support**: Automatic detection and helpful commands for Docker environments
* **Security Hardening**: Multiple layers of security protection for production environments

== Security Features ==

### Environment-Aware Protection
* **Permission-Based Detection**: Uses wp-config.php file permissions as primary production indicator
* **Secure by Default**: 644 permissions automatically indicate production environment
* **Development Confirmation**: Requires explicit confirmation for writable wp-config.php files
* **Multi-factor Environment Detection**: Combines file permissions with domain patterns, SSL status, and debug settings

### Debug Toggle Security
* **Production Lockdown**: Debug setting modification automatically disabled when wp-config.php has secure permissions (644/600)
* **Development Confirmation**: Requires user confirmation before allowing debug modifications on writable files
* **Permission Validation**: Real-time display of current file permissions and security status
* **Clear Instructions**: Provides specific chmod commands for proper permission setup
* **Audit Logging**: All debug setting changes are logged with user information
* **Backup Protection**: Automatic backup creation before modifying wp-config.php

### Permission-Based Security Levels
* **644 (Secure)**: Production environment - debug toggles disabled
* **600 (Very Secure)**: High-security production - debug toggles disabled  
* **664 (Development)**: Development mode - debug toggles available with confirmation
* **666 (Unsafe)**: Requires user confirmation and warnings about security risks

### Access Control
* **Admin-Only Access**: Requires 'manage_options' capability
* **Nonce Protection**: All AJAX requests protected with WordPress nonces
* **Rate Limiting**: Prevents abuse with configurable rate limiting
* **Environment Validation**: Multiple checks to ensure safe operation context

== Installation ==

1. Upload the `bugsquasher` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Tools > BugSquasher to start using the plugin

== Requirements ==

* WordPress 6.8.3 or higher
* PHP 8.1 or higher  
* WordPress debug logging must be enabled (WP_DEBUG_LOG = true)
* User must have 'manage_options' capability
* For debug toggle functionality: wp-config.php must be writable

## **Production Deployment Best Practices:**

### For Maximum Security Hardening:

1. **Set Secure wp-config.php Permissions (Recommended for Production):**
   ```bash
   chmod 644 wp-config.php
   chown root:www-data wp-config.php  # If possible
   ```
   This automatically disables debug toggles and indicates production environment.

2. **Development Environment Setup:**
   ```bash
   chmod 664 wp-config.php  # Makes file writable for debug toggles
   ```

3. **Set Environment Variables:**
   ```php
   // In wp-config.php for production
   define('WP_ENV', 'production');
   define('WP_DEBUG', false);
   define('WP_DEBUG_LOG', false);
   define('WP_DEBUG_DISPLAY', false);
   ```

4. **Development/Staging Setup:**
   ```php
   // In wp-config.php for development
   define('WP_ENV', 'development');
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', true);
   ```

### Security Recommendations:

- **Use 644 permissions in production** to automatically disable debug toggles
- **Use 664 permissions in development** to enable debug toggle functionality
- **Always confirm environment type** when prompted by the plugin
- **Monitor file permissions** - the plugin displays current wp-config.php permissions
- **Use staging environments** for debug testing and development
- **Keep file permissions restrictive** (644 for files, 755 for directories) in production

### Permission Quick Reference:
- **644**: Read-only, production-safe, debug toggles disabled
- **664**: Writable, development-friendly, debug toggles available (with confirmation)
- **600**: Very secure, production-safe, debug toggles disabled  
- **666**: Unsafe, requires explicit confirmation

## **Troubleshooting**

### Debug Toggles Not Working

If you see "wp-config.php is not writable" in the debug controls section:

**For Docker Environments:**
```bash
# Find your container name
docker ps

# Fix permissions (replace <container> with your actual container name)
docker exec -it <container> chown www-data:www-data /var/www/html/wp-config.php
docker exec -it <container> chmod 664 /var/www/html/wp-config.php
```

**For Standard Hosting:**
```bash
# Via SSH (if you have access)
chmod 664 /path/to/your/wp-config.php

# Or contact your hosting provider to adjust file permissions
```

**For Local Development:**
```bash
chmod 664 /path/to/your/wordpress/wp-config.php
```

### No Logs Appearing

1. Ensure WP_DEBUG and WP_DEBUG_LOG are enabled in wp-config.php:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

2. Check that the debug.log file exists and has content:
   ```
   /wp-content/debug.log
   ```

3. Generate some errors to test:
   - Try accessing a non-existent page
   - Temporarily add invalid PHP code to a theme file

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