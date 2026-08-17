<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-shield-monitor-settings.php';
require_once __DIR__ . '/includes/class-shield-monitor-activator.php';
Shield_Monitor_Activator::uninstall();
