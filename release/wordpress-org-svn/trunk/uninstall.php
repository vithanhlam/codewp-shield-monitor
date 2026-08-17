<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * uninstall.php runs in isolation (main plugin bootstrap is not loaded).
 * Load every class referenced by Shield_Monitor_Activator::uninstall().
 */
$shield_monitor_uninstall_deps = array(
	'class-shield-monitor-settings.php',
	'class-shield-monitor-file-monitor.php',
	'class-shield-monitor-login-protection.php',
	'class-shield-monitor-malware-scanner.php',
	'class-shield-monitor-backup-alert.php',
	'class-shield-monitor-vulnerability-alert.php',
	'class-shield-monitor-license.php',
	'class-shield-monitor-push.php',
	'class-shield-monitor-activator.php',
);

foreach ( $shield_monitor_uninstall_deps as $shield_monitor_uninstall_file ) {
	require_once __DIR__ . '/includes/' . $shield_monitor_uninstall_file;
}

Shield_Monitor_Activator::uninstall();
