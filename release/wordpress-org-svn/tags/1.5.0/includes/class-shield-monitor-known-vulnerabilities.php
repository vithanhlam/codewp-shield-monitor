<?php
defined( 'ABSPATH' ) || exit;

/**
 * A small, reviewed offline advisory list, not a complete vulnerability feed.
 * Version matches indicate potentially affected software, not confirmed exploitation.
 * Reviewed 2026-10-19; sources and update priorities are in docs/SECURITY-REVIEW-2026-10-19.md.
 */
class Shield_Monitor_Known_Vulnerabilities {
	public static function check( $installed, $wordpress_version ) {
		$findings = array();
		$core = array(
			array( 'CVE-2026-87902', 'Conditional RCE via page-template resolution', 'critical', array( '6.4' => '6.4.12', '6.5' => '6.5.12', '6.6' => '6.6.9', '6.7' => '6.7.9', '6.8' => '6.8.10', '6.9' => '6.9.9', '7.0' => '7.0.6', '7.1' => '7.1.2' ), 'https://github.com/WordPress/wordpress-develop/security/advisories/GHSA-7hp8-65ch-5whp' ),
			array( 'CVE-2026-63030', 'REST batch-route confusion and SQL injection leading to RCE', 'critical', array( '6.9' => '6.9.5', '7.0' => '7.0.2' ), 'https://github.com/WordPress/wordpress-develop/security/advisories/GHSA-ff9f-jf42-662q' ),
			array( 'CVE-2026-60137', 'Facilitated SQL injection in WP_Query author__not_in', 'medium', array( '6.8' => '6.8.6', '6.9' => '6.9.5', '7.0' => '7.0.2' ), 'https://github.com/WordPress/wordpress-develop/security/advisories/GHSA-fpp7-x2x2-2mjf' ),
		);
		// Stable versions only. Prereleases need their own advisory ranges.
		if ( preg_match( '/^(\d+\.\d+)(?:\.\d+)*$/', $wordpress_version, $match ) ) {
			foreach ( $core as $advisory ) {
				$fixed = $advisory[3][ $match[1] ] ?? null;
				if ( $fixed && version_compare( $wordpress_version, $fixed, '<' ) ) {
					$findings[] = self::finding( 'wordpress', 'WordPress', $wordpress_version, $advisory[0], $advisory[1], $advisory[2], $fixed, $advisory[4], 'core' );
				}
			}
		}

		// slug, minimum affected version, fixed version, CVE, title, primary source.
		$plugins = array(
			array( 'updraftplus', '1.0', '1.26.5', 'CVE-2026-10795', 'UpdraftCentral authentication bypass', 'https://www.wordfence.com/blog/2026/06/critical-unauthenticated-authentication-bypass-vulnerability-patched-in-updraftplus-wordpress-plugin/' ),
			array( 'updraftplus', '2.0', '2.26.5', 'CVE-2026-10795', 'UpdraftCentral authentication bypass (Premium)', 'https://www.wordfence.com/blog/2026/06/critical-unauthenticated-authentication-bypass-vulnerability-patched-in-updraftplus-wordpress-plugin/' ),
			array( 'burst-statistics', '3.4.0', '3.4.2', 'CVE-2026-8181', 'Authentication bypass to administrator account takeover', 'https://www.wordfence.com/blog/2026/05/200000-wordpress-sites-at-risk-from-critical-authentication-bypass-vulnerability-in-burst-statistics-plugin/' ),
			array( 'translatepress-multilingual', '0', '3.3.2', 'CVE-2026-19632', 'Password reset link disclosure allowing account takeover', 'https://www.wordfence.com/blog/2026/08/400000-wordpress-sites-affected-by-account-takeover-vulnerability-in-translatepress-wordpress-plugin/' ),
			array( 'breeze', '0', '2.4.5', 'CVE-2026-3844', 'Arbitrary file upload via remote gravatar caching', 'https://www.wordfence.com/blog/2026/05/attackers-actively-exploiting-critical-vulnerability-in-breeze-cache-plugin/' ),
			array( 'kali-forms', '0', '2.4.10', 'CVE-2026-3584', 'Unauthenticated remote code execution via form_process', 'https://www.wordfence.com/blog/2026/04/attackers-actively-exploiting-critical-vulnerability-in-kali-forms-plugin/' ),
		);
		foreach ( $installed as $file => $data ) {
			$version = $data['Version'] ?? '';
			if ( ! is_string( $version ) || ! preg_match( '/^\d+(?:\.\d+)*$/', $version ) ) {
				continue;
			}
			foreach ( $plugins as $advisory ) {
				if ( dirname( $file ) !== $advisory[0] || version_compare( $version, $advisory[1], '<' ) || version_compare( $version, $advisory[2], '>=' ) ) {
					continue;
				}
				$findings[] = self::finding( $file, $data['Name'] ?? $advisory[0], $version, $advisory[3], $advisory[4], 'critical', $advisory[2], $advisory[5], 'plugin' );
			}
		}
		return $findings;
	}

	private static function finding( $file, $name, $version, $cve, $title, $severity, $fixed, $reference, $type ) {
		return array(
			'plugin_file' => sanitize_text_field( $file ),
			'plugin_name' => sanitize_text_field( $name ),
			'plugin_version' => sanitize_text_field( $version ),
			'vulnerability' => $title,
			'cve_id' => $cve,
			'severity' => $severity,
			'fixed_in' => $fixed,
			'references' => array( $reference ),
			'component_type' => $type,
			'source' => 'bundled_review_2026_10_19',
		);
	}
}
