<?php
// Destructive to the disposable LAB ONLY: run this after all other checks.
$lab = getenv( 'SHIELDPRESS_LAB_DIR' );
if ( ! $lab ) exit( 'SHIELDPRESS_LAB_DIR is required' );
define( 'WP_INSTALLING', true );
define( 'WP_UNINSTALL_PLUGIN', 'shieldpress/shield-monitor.php' );
$_SERVER['HTTP_HOST'] = '127.0.0.1:18773';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $lab, '/' ) . '/wordpress/wp-load.php';
if ( wp_get_environment_type() !== 'local' || get_bloginfo( 'name' ) !== 'ShieldPress isolated lab' ) exit( 'Lab only' );
if ( class_exists( 'Shield_Monitor' ) ) throw new RuntimeException( 'Main plugin must not be loaded for this test' );
require WP_PLUGIN_DIR . '/shieldpress/uninstall.php';
$results = array(
	array( 'name' => 'uninstall completed with isolated class loading', 'passed' => true ),
	array( 'name' => 'settings removed', 'passed' => get_option( 'shield_monitor_settings', false ) === false ),
	array( 'name' => 'legacy premium migration marker removed', 'passed' => get_option( 'shield_monitor_premium_retired', false ) === false ),
);
$report = array( 'checks' => count( $results ), 'failures' => count( array_filter( $results, function( $r ) { return ! $r['passed']; } ) ), 'results' => $results );
file_put_contents( rtrim( $lab, '/' ) . '/uninstall-checks.json', json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report, JSON_PRETTY_PRINT ) . "\n";
exit( $report['failures'] ? 1 : 0 );
