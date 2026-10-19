<?php
// Standalone boundary checks; no WordPress site or database is loaded.
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
require dirname( __DIR__, 2 ) . '/includes/class-shield-monitor-known-vulnerabilities.php';
$failures = array();
$checks = 0;
function expect_cve( $plugins, $core, $cve, $expected, $fixed = null ) {
	global $checks, $failures;
	++$checks;
	$matches = array_values( array_filter( Shield_Monitor_Known_Vulnerabilities::check( $plugins, $core ), function( $finding ) use ( $cve ) { return $finding['cve_id'] === $cve; } ) );
	if ( count( $matches ) !== ( $expected ? 1 : 0 ) || ( $expected && $fixed && $matches[0]['fixed_in'] !== $fixed ) ) {
		$failures[] = array( 'plugins' => $plugins, 'core' => $core, 'cve' => $cve, 'expected' => $expected, 'actual' => $matches );
	}
}
$core_cases = array(
	array( '6.4.11', '6.4.12' ), array( '6.5.11', '6.5.12' ), array( '6.6.8', '6.6.9' ), array( '6.7.8', '6.7.9' ),
	array( '6.8.9', '6.8.10' ), array( '6.9.8', '6.9.9' ), array( '7.0.5', '7.0.6' ), array( '7.1.1', '7.1.2' ),
);
foreach ( $core_cases as $case ) {
	expect_cve( array(), $case[0], 'CVE-2026-87902', true, $case[1] );
	expect_cve( array(), $case[1], 'CVE-2026-87902', false );
}
foreach ( array( '7.1.3', '7.2', '7.1-beta1', '' ) as $core ) {
	expect_cve( array(), $core, 'CVE-2026-87902', false );
}
foreach ( array( array( '6.9.4', '6.9.5' ), array( '7.0.1', '7.0.2' ) ) as $case ) {
	expect_cve( array(), $case[0], 'CVE-2026-63030', true, $case[1] );
	expect_cve( array(), $case[1], 'CVE-2026-63030', false );
}
expect_cve( array(), '6.8.5', 'CVE-2026-63030', false );
expect_cve( array(), '6.8.5', 'CVE-2026-60137', true, '6.8.6' );
expect_cve( array(), '6.8.6', 'CVE-2026-60137', false );
$plugin_cases = array(
	array( 'updraftplus', '1.26.4', '1.26.5', 'CVE-2026-10795' ),
	array( 'updraftplus', '2.26.4.1', '2.26.5', 'CVE-2026-10795' ),
	array( 'burst-statistics', '3.4.1.1', '3.4.2', 'CVE-2026-8181' ),
	array( 'translatepress-multilingual', '3.3.1', '3.3.2', 'CVE-2026-19632' ),
	array( 'breeze', '2.4.4', '2.4.5', 'CVE-2026-3844' ),
	array( 'kali-forms', '2.4.9', '2.4.10', 'CVE-2026-3584' ),
);
foreach ( $plugin_cases as $case ) {
	$file = $case[0] . '/plugin.php';
	expect_cve( array( $file => array( 'Name' => $case[0], 'Version' => $case[1] ) ), '7.1.3', $case[3], true, $case[2] );
	expect_cve( array( $file => array( 'Name' => $case[0], 'Version' => $case[2] ) ), '7.1.3', $case[3], false );
}
expect_cve( array( 'updraftplus/updraftplus.php' => array( 'Version' => '1.26.11' ) ), '7.1.3', 'CVE-2026-10795', false );
expect_cve( array( 'updraftplus/updraftplus.php' => array( 'Version' => '2.26.8.26' ) ), '7.1.3', 'CVE-2026-10795', false );
expect_cve( array( 'burst-statistics/burst.php' => array( 'Version' => '3.3.9' ) ), '7.1.3', 'CVE-2026-8181', false );
expect_cve( array( 'burst-statistics/burst.php' => array( 'Version' => '3.4.0' ) ), '7.1.3', 'CVE-2026-8181', true, '3.4.2' );
expect_cve( array( 'unrelated/plugin.php' => array( 'Version' => '1.0' ) ), '7.1.3', 'CVE-2026-10795', false );
echo json_encode( array( 'checks' => $checks, 'failures' => $failures ), JSON_PRETTY_PRINT ) . "\n";
exit( $failures ? 1 : 0 );
