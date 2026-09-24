<?php
/**
 * Plugin Name:       CodeWP Shield Monitor
 * Plugin URI:        https://shieldpress.net
 * Description:       Lightweight, privacy-first security hardening and login protection for WordPress.
 * Version:           1.4.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            vithanhlam
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codewp-shield-monitor
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SHIELD_MONITOR_VERSION', '1.4.4' );
define( 'CODEWP_SHIELD_MONITOR_NAME', 'Shield Monitor' );
define( 'CODEWP_SHIELD_MONITOR_SLUG', 'codewp-shield-monitor' );
define( 'CODEWP_SHIELD_MONITOR_API_NAMESPACE', 'codewp-shield-monitor/v1' );
define( 'SHIELD_MONITOR_APP_URL', 'https://shieldpress.net' );
define( 'SHIELD_MONITOR_FILE', __FILE__ );
define( 'SHIELD_MONITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHIELD_MONITOR_URL', plugin_dir_url( __FILE__ ) );

require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-activator.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-settings.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-audit-log.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-login-protection.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-hardening.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-advanced-hardening.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-file-monitor.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-malware-scanner.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-alerts.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-notifications.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-status.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-threat-intelligence.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-threat-patterns.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-waf.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-db-cleanup.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-media-optimization.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-backup-alert.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-vulnerability-alert.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-license.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-addons.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-license-page.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-addon-pages.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-api.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-push.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-security-dashboard.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor-admin.php';
require_once SHIELD_MONITOR_PATH . 'includes/class-shield-monitor.php';

register_activation_hook( __FILE__, array( 'Shield_Monitor_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Shield_Monitor_Activator', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Shield_Monitor_Activator', 'uninstall' ) );

Shield_Monitor::instance()->run();
