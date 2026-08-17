<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Status {
	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function collect() {
		$updates  = $this->updates();
		$security = $this->security();

		return array(
			'api_version' => 1,
			'schema_version' => '1.8',
			'generated_at' => gmdate( 'c' ),
			'capabilities' => $this->capabilities(),
			'endpoints' => $this->endpoints(),
			'quick_login' => $this->quick_login_status(),
			'event_catalog' => $this->event_catalog(),
			'event_sync' => array( 'latest_id' => $this->logger->latest_id(), 'retention_days' => 30 ),
			'site' => array(
				'name'       => get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'database_version' => $this->database_version(),
				'shield_monitor_version' => SHIELD_MONITOR_VERSION,
				'plugin_name' => CODEWP_SHIELD_MONITOR_NAME,
				'plugin_slug' => CODEWP_SHIELD_MONITOR_SLUG,
				'api_namespace' => CODEWP_SHIELD_MONITOR_API_NAMESPACE,
				'locale' => get_locale(),
				'timezone' => wp_timezone_string(),
				'is_multisite' => is_multisite(),
				'blog_id' => get_current_blog_id(),
				'network_id' => is_multisite() ? get_current_network_id() : null,
			),
			'inventory'  => $this->inventory(),
			'system'     => $this->system(),
			'configuration' => $this->configuration(),
			'integrations' => $this->integrations(),
			'updates'    => $updates,
			'security'   => $security,
			'health_score' => $this->health_score( $updates, $security ),
			'issues'     => $this->issues( $updates, $security ),
		);
	}

	public function public_score() {
		$updates = $this->updates();
		$score   = 100;
		$score  -= is_ssl() ? 0 : 20;
		foreach ( array( 'login_protection', 'block_enumeration', 'security_headers', 'disable_file_editor' ) as $key ) {
			$score -= $this->settings->get( $key ) ? 0 : 10;
		}
		$score -= $this->settings->get( 'file_monitoring' ) ? 0 : 10;
		$core_status = get_option( Shield_Monitor_File_Monitor::CORE_OPTION, array() );
		$score -= is_array( $core_status ) && 'mismatch' === ( $core_status['status'] ?? '' ) ? 20 : 0;
		$report = get_option( Shield_Monitor_Malware_Scanner::REPORT_OPTION, array() );
		$malware_summary = is_array( $report ) && isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$score -= ! empty( $malware_summary['critical'] ) ? 25 : 0;
		$score -= ! empty( $malware_summary['high'] ) ? min( 20, absint( $malware_summary['high'] ) * 10 ) : 0;
		$score -= ! empty( $malware_summary['medium'] ) ? min( 10, absint( $malware_summary['medium'] ) * 2 ) : 0;
		$score -= $this->settings->get( 'email_alerts' ) ? 0 : 5;
		$score -= $updates['core']['update_available'] ? 15 : 0;
		$score -= min( 15, ( $updates['plugins']['update_count'] + $updates['themes']['update_count'] ) * 2 );
		return max( 0, $score );
	}

	private function capabilities() {
		return array(
			'status' => true,
			'event_cursor' => true,
			'token_scopes' => true,
			'token_expiry' => true,
			'token_rotation' => true,
			'file_integrity' => true,
			'core_checksums' => (bool) $this->settings->get( 'core_checksum_verification' ),
			'content_audit' => true,
			'software_update_audit' => true,
			'software_lifecycle_audit' => true,
			'login_access_control' => true,
			'advanced_hardening' => true,
			'quick_login' => (bool) $this->settings->quick_login_enabled(),
			'malware_scan' => (bool) $this->settings->get( 'malware_scanning' ),
			'threat_intelligence' => true,
			'system_metrics' => true,
			'webhooks' => false,
			'remote_actions' => false,
		);
	}

	private function endpoints() {
		return array(
			'pair_url'        => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/pair' ),
			'status_url'      => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/status' ),
			'events_url'      => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/events' ),
			'public_score_url'=> rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/public-score' ),
			'rotate_url'      => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/token/rotate' ),
			'quick_login_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/quick-login' ),
			'quick_login_method' => 'POST',
			'quick_login_flow' => 'POST quick_login_url with a Bearer token, then open the returned login_url.',
		);
	}

	private function quick_login_status() {
		return array(
			'enabled' => (bool) $this->settings->quick_login_enabled(),
			'user_id' => absint( $this->settings->quick_login_user_id() ),
			'url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/quick-login' ),
			'method' => 'POST',
			'expires_in_seconds' => Shield_Monitor_API::QUICK_LOGIN_TTL,
			'flow' => 'POST url with a Bearer token, then open the returned login_url.',
			'bypasses' => array( 'password', 'captcha', 'admin_pin' ),
		);
	}

	private function event_catalog() {
		return array(
			'login' => array( 'admin_login', 'login_success', 'login_failed', 'login_lockout', 'login_ip_blocked', 'login_ip_unlocked', 'quick_login_link_created', 'quick_login_used' ),
			'admin' => array( 'user_registered', 'role_changed', 'admin_user_created', 'admin_password_changed' ),
			'content' => array( 'post_created', 'post_updated', 'custom_post_type_created' ),
			'notifications' => array( 'contact_form_7_submission', 'woocommerce_order_created' ),
			'files' => array( 'file_added', 'file_modified', 'file_deleted', 'file_baseline_trusted', 'core_checksum_mismatch' ),
			'software' => array( 'software_updated', 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated' ),
			'scan' => array( 'malware_scan_completed', 'malware_scan_suspicious', 'malware_report_cleared' ),
			'app' => array( 'app_paired', 'app_revoked', 'token_rotated' ),
			'database' => array( 'table_prefix_changed' ),
			'system' => array( 'enumeration_blocked' ),
		);
	}

	private function configuration() {
		$auto_core = defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : 'minor';
		$next_scan = wp_next_scheduled( Shield_Monitor_File_Monitor::CRON_HOOK );
		return array(
			'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
			'disallow_file_edit' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'disallow_file_mods' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'automatic_updater_disabled' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
			'wp_auto_update_core' => is_bool( $auto_core ) || is_string( $auto_core ) ? $auto_core : null,
			'auto_update_plugins_count' => count( (array) get_site_option( 'auto_update_plugins', array() ) ),
			'auto_update_themes_count' => count( (array) get_site_option( 'auto_update_themes', array() ) ),
			'wp_cron' => array(
				'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'alternate' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				'next_shield_monitor_scan' => $next_scan ? gmdate( 'c', $next_scan ) : null,
			),
			'login_access' => array(
				'hide_login' => (bool) $this->settings->get( 'hide_login' ),
				'login_slug' => sanitize_title( $this->settings->get( 'login_slug' ) ?: 'codewp-shield-monitor-login' ),
				'login_url'  => home_url( '/' . sanitize_title( $this->settings->get( 'login_slug' ) ?: 'codewp-shield-monitor-login' ) . '/' ),
				'captcha_enabled' => (bool) $this->settings->get( 'captcha_enabled' ),
				'captcha_provider' => sanitize_key( $this->settings->get( 'captcha_provider' ) ?: 'turnstile' ),
				'admin_pin_enabled' => (bool) $this->settings->get( 'admin_pin_enabled' ),
				'admin_pin_configured' => (bool) $this->settings->get( 'admin_pin_hash' ),
				'quick_login_enabled' => (bool) $this->settings->quick_login_enabled(),
				'quick_login_user_id' => absint( $this->settings->quick_login_user_id() ),
				'quick_login_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/quick-login' ),
				'quick_login_method' => 'POST',
				'quick_login_flow' => 'POST quick_login_url with a Bearer token, then open the returned login_url.',
			),
		);
	}

	private function issues( $updates, $security ) {
		$items = array();

		if ( empty( $security['https'] ) ) {
			$items[] = $this->issue( 'https_not_enabled', 'critical', 'hardening', __( 'HTTPS is not active.', 'codewp-shield-monitor' ), __( 'Install a valid SSL certificate and redirect all HTTP traffic to HTTPS.', 'codewp-shield-monitor' ), array( 'setting' => 'ssl' ) );
		}

		if ( ! empty( $updates['core']['update_available'] ) ) {
			$items[] = $this->issue( 'wordpress_core_update_available', 'high', 'updates', __( 'WordPress core update is available.', 'codewp-shield-monitor' ), __( 'Update WordPress core after confirming a recent backup exists.', 'codewp-shield-monitor' ), array( 'new_version' => $updates['core']['new_version'] ?? null ), admin_url( 'update-core.php' ) );
		}

		if ( ! empty( $updates['plugins']['update_count'] ) ) {
			$items[] = $this->issue( 'plugin_updates_available', 'medium', 'updates', __( 'Plugin updates are available.', 'codewp-shield-monitor' ), __( 'Review and update outdated plugins.', 'codewp-shield-monitor' ), array( 'count' => absint( $updates['plugins']['update_count'] ), 'items' => array_slice( (array) ( $updates['plugins']['items'] ?? array() ), 0, 10 ) ), admin_url( 'plugins.php' ) );
		}

		if ( ! empty( $updates['themes']['update_count'] ) ) {
			$items[] = $this->issue( 'theme_updates_available', 'medium', 'updates', __( 'Theme updates are available.', 'codewp-shield-monitor' ), __( 'Review and update outdated themes.', 'codewp-shield-monitor' ), array( 'count' => absint( $updates['themes']['update_count'] ), 'items' => array_slice( (array) ( $updates['themes']['items'] ?? array() ), 0, 10 ) ), admin_url( 'themes.php' ) );
		}

		foreach ( $this->hardening_issue_map() as $key => $issue ) {
			if ( empty( $security[ $key ] ) ) {
				$items[] = $this->issue( $issue['id'], $issue['severity'], 'hardening', $issue['title'], $issue['description'], array( 'setting' => $key ), admin_url( 'admin.php?page=codewp-shield-monitor#settings' ) );
			}
		}

		if ( empty( $security['file_integrity']['enabled'] ) ) {
			$items[] = $this->issue( 'file_monitoring_disabled', 'high', 'hardening', __( 'File integrity monitoring is disabled.', 'codewp-shield-monitor' ), __( 'Enable important-file monitoring to detect unexpected file changes.', 'codewp-shield-monitor' ), array( 'setting' => 'file_monitoring' ), admin_url( 'admin.php?page=codewp-shield-monitor#settings' ) );
		}

		if ( empty( $security['malware_scan']['enabled'] ) ) {
			$items[] = $this->issue( 'malware_scan_disabled', 'high', 'hardening', __( 'Suspicious code scanning is disabled.', 'codewp-shield-monitor' ), __( 'Enable lightweight malware and database scans.', 'codewp-shield-monitor' ), array( 'setting' => 'malware_scanning' ), admin_url( 'admin.php?page=codewp-shield-monitor#settings' ) );
		}

		if ( empty( $security['software_installation_disabled'] ) ) {
			$items[] = $this->issue( 'software_installation_allowed', 'medium', 'hardening', __( 'Plugin and theme installation is still allowed.', 'codewp-shield-monitor' ), __( 'Disable plugin/theme installs and deletes when you are not actively maintaining the site.', 'codewp-shield-monitor' ), array( 'setting' => 'disable_software_installation' ), admin_url( 'admin.php?page=codewp-shield-monitor#advanced-hardening' ) );
		}

		if ( empty( $security['captcha_enabled'] ) ) {
			$items[] = $this->issue( 'login_captcha_disabled', 'medium', 'login', __( 'Login CAPTCHA is not enabled.', 'codewp-shield-monitor' ), __( 'Configure Cloudflare Turnstile or Google reCAPTCHA on the login form.', 'codewp-shield-monitor' ), array( 'provider' => $security['captcha_provider'] ?? 'turnstile' ), admin_url( 'admin.php?page=codewp-shield-monitor#advanced-hardening' ) );
		}

		if ( empty( $security['admin_pin_enabled'] ) ) {
			$items[] = $this->issue( 'admin_pin_disabled', 'low', 'login', __( 'Administrator PIN is not enabled.', 'codewp-shield-monitor' ), __( 'Require a 6-digit PIN for administrator logins when possible.', 'codewp-shield-monitor' ), array( 'setting' => 'admin_pin_enabled' ), admin_url( 'admin.php?page=codewp-shield-monitor#advanced-hardening' ) );
		}

		if ( ! empty( $security['failed_logins_30_days'] ) ) {
			$items[] = $this->issue( 'failed_logins_detected', $security['failed_logins_30_days'] >= 10 ? 'high' : 'medium', 'login', __( 'Failed login attempts were detected.', 'codewp-shield-monitor' ), __( 'Review failed-login IPs and keep login protection enabled.', 'codewp-shield-monitor' ), array( 'count_30_days' => absint( $security['failed_logins_30_days'] ), 'lockouts' => array_slice( (array) ( $security['login_lockouts'] ?? array() ), 0, 10 ) ), admin_url( 'admin.php?page=codewp-shield-monitor#login-access' ) );
		}

		$file_changes = (array) ( $security['recent_file_changes_30_days'] ?? array() );
		if ( ! empty( $file_changes ) ) {
			$items[] = $this->issue( 'file_changes_detected', 'high', 'files', __( 'Important files changed recently.', 'codewp-shield-monitor' ), __( 'Review changed files and trust the baseline only after confirming the changes are legitimate.', 'codewp-shield-monitor' ), array( 'count' => count( $file_changes ), 'items' => array_slice( $file_changes, 0, 20 ) ), admin_url( 'admin.php?page=codewp-shield-monitor#integrity' ) );
		}

		$core_status = $security['file_integrity']['core_checksum']['status'] ?? '';
		if ( 'mismatch' === $core_status ) {
			$items[] = $this->issue( 'core_checksum_mismatch', 'critical', 'files', __( 'WordPress core checksum mismatch.', 'codewp-shield-monitor' ), __( 'Review modified core files and restore clean WordPress core files if needed.', 'codewp-shield-monitor' ), array( 'core_checksum' => $security['file_integrity']['core_checksum'] ?? array() ), admin_url( 'admin.php?page=codewp-shield-monitor#integrity' ) );
		}

		foreach ( array_slice( (array) ( $security['malware_scan']['findings'] ?? array() ), 0, 30 ) as $index => $finding ) {
			$severity = sanitize_key( $finding['severity'] ?? 'medium' );
			$items[] = $this->issue( 'malware_finding_' . $index, $this->normalize_severity( $severity ), 'malware', sanitize_text_field( $finding['message'] ?? __( 'Suspicious scan finding.', 'codewp-shield-monitor' ) ), __( 'Review the suspicious file or database record before removing anything.', 'codewp-shield-monitor' ), $finding, admin_url( 'admin.php?page=codewp-shield-monitor#malware' ) );
		}

		foreach ( array_slice( (array) ( $security['threat_intelligence']['findings'] ?? array() ), 0, 30 ) as $index => $finding ) {
			$severity = sanitize_key( $finding['severity'] ?? 'medium' );
			$items[] = $this->issue( 'threat_finding_' . $index, $this->normalize_severity( $severity ), 'threats', sanitize_text_field( $finding['title'] ?? __( 'Threat intelligence finding.', 'codewp-shield-monitor' ) ), sanitize_text_field( $finding['message'] ?? __( 'Review this local compromise signal.', 'codewp-shield-monitor' ) ), $finding, admin_url( 'admin.php?page=codewp-shield-monitor#threats' ) );
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $this->severity_weight( $b['severity'] ) <=> $this->severity_weight( $a['severity'] );
			}
		);

		return array(
			'generated_at' => gmdate( 'c' ),
			'total'       => count( $items ),
			'by_severity' => $this->issue_counts( $items ),
			'highest_severity' => empty( $items ) ? 'none' : $items[0]['severity'],
			'items'       => $items,
		);
	}

	private function hardening_issue_map() {
		return array(
			'login_protection' => array( 'id' => 'login_protection_disabled', 'severity' => 'high', 'title' => __( 'Login protection is disabled.', 'codewp-shield-monitor' ), 'description' => __( 'Enable failed-login lockouts to slow brute-force attempts.', 'codewp-shield-monitor' ) ),
			'block_enumeration' => array( 'id' => 'user_enumeration_allowed', 'severity' => 'medium', 'title' => __( 'Public user enumeration is not blocked.', 'codewp-shield-monitor' ), 'description' => __( 'Restrict public author and REST user discovery.', 'codewp-shield-monitor' ) ),
			'security_headers' => array( 'id' => 'security_headers_disabled', 'severity' => 'medium', 'title' => __( 'Security headers are disabled.', 'codewp-shield-monitor' ), 'description' => __( 'Enable conservative browser protection headers.', 'codewp-shield-monitor' ) ),
			'file_editor_disabled' => array( 'id' => 'dashboard_file_editor_enabled', 'severity' => 'medium', 'title' => __( 'Dashboard file editor is enabled.', 'codewp-shield-monitor' ), 'description' => __( 'Disable plugin/theme code editing from wp-admin.', 'codewp-shield-monitor' ) ),
			'email_alerts' => array( 'id' => 'email_alerts_disabled', 'severity' => 'low', 'title' => __( 'Security email alerts are disabled.', 'codewp-shield-monitor' ), 'description' => __( 'Enable alerts for admin logins, lockouts, file changes, and suspicious findings.', 'codewp-shield-monitor' ) ),
		);
	}

	private function issue( $id, $severity, $category, $title, $description, $evidence = array(), $admin_url = '' ) {
		$actions = array();
		if ( $admin_url ) {
			$actions[] = array(
				'type' => 'wp_admin',
				'label' => __( 'Open in WordPress admin', 'codewp-shield-monitor' ),
				'url' => esc_url_raw( $admin_url ),
				'quick_login_supported' => (bool) $this->settings->quick_login_enabled(),
			);
		}

		return array(
			'id'          => sanitize_key( $id ),
			'severity'    => $this->normalize_severity( $severity ),
			'category'    => sanitize_key( $category ),
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'evidence'    => map_deep( is_array( $evidence ) ? $evidence : array(), 'sanitize_text_field' ),
			'actions'     => $actions,
		);
	}

	private function issue_counts( $items ) {
		$counts = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $items as $item ) {
			$severity = $this->normalize_severity( $item['severity'] ?? 'low' );
			$counts[ $severity ]++;
		}
		return $counts;
	}

	private function normalize_severity( $severity ) {
		$severity = sanitize_key( $severity );
		return in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ? $severity : 'medium';
	}

	private function severity_weight( $severity ) {
		$weights = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		return $weights[ $this->normalize_severity( $severity ) ] ?? 0;
	}

	private function integrations() {
		$active = $this->active_plugin_files();
		return array(
			'two_factor_authentication' => $this->detect_integration( $active, array( 'two-factor', 'wp-2fa', 'wordfence', 'solid-security', 'better-wp-security', 'google-authenticator', 'duo-wordpress', 'miniorange-2-factor' ) ),
			'backup' => $this->detect_integration( $active, array( 'updraftplus', 'backwpup', 'backupbuddy', 'solid-backups', 'wpvivid', 'duplicator', 'blogvault', 'jetpack' ) ),
			'cache' => $this->detect_integration( $active, array( 'litespeed-cache', 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler', 'autoptimize', 'sg-cachepress', 'breeze', 'hummingbird-performance', 'perfmatters' ) ),
			'contact_form_7' => $this->detect_integration( $active, array( 'contact-form-7' ) ),
			'captcha' => $this->detect_integration( $active, array( 'google-captcha', 'advanced-nocaptcha-recaptcha', 'hcaptcha-for-forms-and-more', 'simple-google-recaptcha', 'cf7-recaptcha', 'recaptcha', 'turnstile', 'friendly-captcha' ) ),
		);
	}

	private function detect_integration( $active, $needles ) {
		$matched = array();
		foreach ( $active as $file ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $file, $needle ) ) {
					$provider  = dirname( $file );
					$matched[] = sanitize_text_field( '.' === $provider ? basename( $file, '.php' ) : $provider );
					break;
				}
			}
		}
		return array( 'detected' => ! empty( $matched ), 'method' => 'active_plugin_heuristic', 'providers' => array_values( array_unique( $matched ) ), 'verified_configuration' => false );
	}

	private function active_plugin_files() {
		return array_values( array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) ) );
	}

	private function database_version() {
		global $wpdb;
		return sanitize_text_field( $wpdb->db_version() );
	}

	private function updates() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data = get_site_transient( 'update_plugins' );
		$theme_data  = get_site_transient( 'update_themes' );
		$core_data   = get_site_transient( 'update_core' );
		$core_update = false;
		$core_version = null;
		if ( isset( $core_data->updates ) && is_array( $core_data->updates ) ) {
			foreach ( $core_data->updates as $update ) {
				if ( isset( $update->response ) && 'upgrade' === $update->response ) {
					$core_update = true;
					$core_version = isset( $update->current ) ? sanitize_text_field( $update->current ) : null;
					break;
				}
			}
		}
		$installed_plugins = get_plugins();
		$plugin_updates    = array();
		foreach ( isset( $plugin_data->response ) ? (array) $plugin_data->response : array() as $file => $update ) {
			$plugin_updates[] = array(
				'file' => sanitize_text_field( $file ),
				'name' => isset( $installed_plugins[ $file ]['Name'] ) ? sanitize_text_field( $installed_plugins[ $file ]['Name'] ) : sanitize_text_field( $file ),
				'current_version' => isset( $installed_plugins[ $file ]['Version'] ) ? sanitize_text_field( $installed_plugins[ $file ]['Version'] ) : null,
				'new_version' => isset( $update->new_version ) ? sanitize_text_field( $update->new_version ) : null,
				'latest_version' => isset( $update->new_version ) ? sanitize_text_field( $update->new_version ) : null,
			);
		}
		$themes        = wp_get_themes();
		$theme_updates = array();
		foreach ( isset( $theme_data->response ) ? (array) $theme_data->response : array() as $slug => $update ) {
			$theme_updates[] = array(
				'slug' => sanitize_key( $slug ),
				'name' => isset( $themes[ $slug ] ) ? sanitize_text_field( $themes[ $slug ]->get( 'Name' ) ) : sanitize_text_field( $slug ),
				'current_version' => isset( $themes[ $slug ] ) ? sanitize_text_field( $themes[ $slug ]->get( 'Version' ) ) : null,
				'new_version' => isset( $update['new_version'] ) ? sanitize_text_field( $update['new_version'] ) : null,
				'latest_version' => isset( $update['new_version'] ) ? sanitize_text_field( $update['new_version'] ) : null,
			);
		}
		return array(
			'core'    => array( 'update_available' => $core_update, 'new_version' => $core_version ),
			'plugins' => array( 'update_count' => count( $plugin_updates ), 'items' => $plugin_updates ),
			'themes'  => array( 'update_count' => count( $theme_updates ), 'items' => $theme_updates ),
		);
	}

	private function inventory() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$user_counts    = count_users();
		$plugins        = get_plugins();
		$active_plugins = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		$themes         = wp_get_themes();
		$active_theme   = wp_get_theme();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$theme_updates  = get_site_transient( 'update_themes' );
		$plugin_items   = array();
		$theme_items    = array();

		foreach ( $plugins as $file => $plugin ) {
			$current = isset( $plugin['Version'] ) ? sanitize_text_field( $plugin['Version'] ) : null;
			$latest  = $this->plugin_latest_version( $file, $current, $plugin_updates );
			$plugin_items[] = array(
				'file'             => sanitize_text_field( $file ),
				'name'             => isset( $plugin['Name'] ) ? sanitize_text_field( $plugin['Name'] ) : sanitize_text_field( $file ),
				'active'           => in_array( $file, $active_plugins, true ),
				'current_version'  => $current,
				'latest_version'   => $latest,
				'update_available' => null !== $current && null !== $latest && version_compare( $latest, $current, '>' ),
			);
		}

		foreach ( $themes as $slug => $theme ) {
			$current = sanitize_text_field( $theme->get( 'Version' ) );
			$latest  = $this->theme_latest_version( $slug, $current, $theme_updates );
			$theme_items[] = array(
				'slug'             => sanitize_key( $slug ),
				'name'             => sanitize_text_field( $theme->get( 'Name' ) ),
				'stylesheet'       => sanitize_key( $theme->get_stylesheet() ),
				'template'         => sanitize_key( $theme->get_template() ),
				'active'           => $slug === get_stylesheet(),
				'current_version'  => $current,
				'latest_version'   => $latest,
				'update_available' => null !== $current && null !== $latest && version_compare( $latest, $current, '>' ),
			);
		}

		$active_theme_current = sanitize_text_field( $active_theme->get( 'Version' ) );
		$active_theme_latest  = $this->theme_latest_version( get_stylesheet(), $active_theme_current, $theme_updates );

		return array(
			'users' => array(
				'total'          => absint( $user_counts['total_users'] ?? 0 ),
				'administrators' => absint( $user_counts['avail_roles']['administrator'] ?? 0 ),
			),
			'posts'    => $this->content_counts( 'post' ),
			'pages'    => $this->content_counts( 'page' ),
			'products' => post_type_exists( 'product' ) ? array_merge( array( 'available' => true ), $this->content_counts( 'product' ) ) : array( 'available' => false, 'total' => 0, 'published' => 0, 'draft' => 0, 'pending' => 0, 'private' => 0 ),
			'plugins' => array(
				'total'    => count( $plugins ),
				'active'   => count( array_intersect( array_keys( $plugins ), $active_plugins ) ),
				'inactive' => max( 0, count( $plugins ) - count( array_intersect( array_keys( $plugins ), $active_plugins ) ) ),
				'items'    => $plugin_items,
			),
			'themes' => array(
				'total' => count( $themes ),
				'items' => $theme_items,
				'active' => array(
					'name'       => sanitize_text_field( $active_theme->get( 'Name' ) ),
					'stylesheet' => sanitize_key( get_stylesheet() ),
					'template'   => sanitize_key( get_template() ),
					'version'    => $active_theme_current,
					'current_version' => $active_theme_current,
					'latest_version' => $active_theme_latest,
					'update_available' => null !== $active_theme_current && null !== $active_theme_latest && version_compare( $active_theme_latest, $active_theme_current, '>' ),
				),
			),
		);
	}

	private function plugin_latest_version( $file, $current, $updates ) {
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( isset( $updates->{$bucket}[ $file ]->new_version ) ) {
				return sanitize_text_field( $updates->{$bucket}[ $file ]->new_version );
			}
		}
		return $current;
	}

	private function theme_latest_version( $slug, $current, $updates ) {
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			if ( isset( $updates->{$bucket}[ $slug ]['new_version'] ) ) {
				return sanitize_text_field( $updates->{$bucket}[ $slug ]['new_version'] );
			}
		}
		return $current;
	}

	private function content_counts( $post_type ) {
		$counts = wp_count_posts( $post_type );
		$values = array(
			'published' => isset( $counts->publish ) ? absint( $counts->publish ) : 0,
			'draft'     => isset( $counts->draft ) ? absint( $counts->draft ) : 0,
			'pending'   => isset( $counts->pending ) ? absint( $counts->pending ) : 0,
			'private'   => isset( $counts->private ) ? absint( $counts->private ) : 0,
			'future'    => isset( $counts->future ) ? absint( $counts->future ) : 0,
		);
		$values['total'] = array_sum( $values );
		return $values;
	}

	private function system() {
		$disk_total = @disk_total_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$disk_free  = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$load       = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : false;
		$cores      = $this->cpu_cores();
		$memory     = $this->server_memory();
		$php_used   = memory_get_usage( true );
		$php_peak   = memory_get_peak_usage( true );
		$php_limit  = $this->bytes_from_ini( ini_get( 'memory_limit' ) );
		$disk_used  = false !== $disk_total && false !== $disk_free ? max( 0, $disk_total - $disk_free ) : null;
		$load_1m    = is_array( $load ) ? round( (float) $load[0], 2 ) : null;
		$host       = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$resolved   = $host ? gethostbyname( $host ) : '';
		$server_ip  = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		return array(
			'cpu' => array(
				'cores'             => $cores,
				'load_1m'           => $load_1m,
				'load_5m'           => is_array( $load ) ? round( (float) $load[1], 2 ) : null,
				'load_15m'          => is_array( $load ) ? round( (float) $load[2], 2 ) : null,
				'usage_percent_estimate' => null !== $load_1m && $cores ? round( min( 100, ( $load_1m / $cores ) * 100 ), 1 ) : null,
			),
			'memory' => array(
				'total_bytes' => $memory['total'],
				'used_bytes'  => $memory['used'],
				'free_bytes'  => $memory['free'],
				'usage_percent' => $memory['total'] ? round( ( $memory['used'] / $memory['total'] ) * 100, 1 ) : null,
				'total_human' => $this->human_bytes( $memory['total'] ),
				'used_human'  => $this->human_bytes( $memory['used'] ),
				'free_human'  => $this->human_bytes( $memory['free'] ),
			),
			'php_memory' => array(
				'used_bytes'  => $php_used,
				'peak_bytes'  => $php_peak,
				'limit_bytes' => $php_limit,
				'used_human'  => $this->human_bytes( $php_used ),
				'peak_human'  => $this->human_bytes( $php_peak ),
				'limit_human' => $this->human_bytes( $php_limit ),
			),
			'disk' => array(
				'total_bytes' => false === $disk_total ? null : (int) $disk_total,
				'used_bytes'  => null === $disk_used ? null : (int) $disk_used,
				'free_bytes'  => false === $disk_free ? null : (int) $disk_free,
				'usage_percent' => $disk_total && null !== $disk_used ? round( ( $disk_used / $disk_total ) * 100, 1 ) : null,
				'total_human' => $this->human_bytes( false === $disk_total ? null : $disk_total ),
				'used_human'  => $this->human_bytes( $disk_used ),
				'free_human'  => $this->human_bytes( false === $disk_free ? null : $disk_free ),
			),
			'network' => array(
				'site_host'  => sanitize_text_field( (string) $host ),
				'site_ip'    => filter_var( $resolved, FILTER_VALIDATE_IP ) ? $resolved : null,
				'server_ip'  => filter_var( $server_ip, FILTER_VALIDATE_IP ) ? $server_ip : null,
			),
		);
	}

	private function cpu_cores() {
		$environment = getenv( 'NUMBER_OF_PROCESSORS' );
		if ( $environment && absint( $environment ) ) {
			return absint( $environment );
		}
		if ( is_readable( '/proc/cpuinfo' ) ) {
			$lines = @file( '/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$count = 0;
			foreach ( (array) $lines as $line ) {
				if ( 0 === strpos( $line, 'processor' ) ) {
					++$count;
				}
			}
			return $count ?: null;
		}
		return null;
	}

	private function server_memory() {
		$result = array( 'total' => null, 'used' => null, 'free' => null );
		if ( ! is_readable( '/proc/meminfo' ) ) {
			return $result;
		}
		$values = array();
		$lines  = @file( '/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches ) ) {
				$values[ $matches[1] ] = absint( $matches[2] ) * 1024;
			}
		}
		$total     = $values['MemTotal'] ?? null;
		$available = $values['MemAvailable'] ?? ( ( $values['MemFree'] ?? 0 ) + ( $values['Buffers'] ?? 0 ) + ( $values['Cached'] ?? 0 ) );
		return array( 'total' => $total, 'used' => $total ? max( 0, $total - $available ) : null, 'free' => $total ? $available : null );
	}

	private function bytes_from_ini( $value ) {
		$value = trim( (string) $value );
		if ( '-1' === $value ) {
			return null;
		}
		$number = (float) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g': $number *= 1024;
			case 'm': $number *= 1024;
			case 'k': $number *= 1024;
		}
		return (int) $number;
	}

	private function human_bytes( $bytes ) {
		return null === $bytes ? null : size_format( (int) $bytes, 2 );
	}

	private function ssl() {
		if ( ! is_ssl() ) {
			return array( 'status' => 'not_https', 'expires_at' => null, 'days_remaining' => null );
		}
		$cached = get_transient( 'shield_monitor_ssl_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$result = array( 'status' => 'unknown', 'expires_at' => null, 'days_remaining' => null );
		if ( $host && function_exists( 'stream_socket_client' ) && function_exists( 'openssl_x509_parse' ) ) {
			$context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host ) ) );
			$socket  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $error, 3, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $socket ) {
				$params = stream_context_get_params( $socket );
				$cert   = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
				fclose( $socket );
				if ( is_array( $cert ) && isset( $cert['validTo_time_t'] ) ) {
					$expiry = absint( $cert['validTo_time_t'] );
					$days   = (int) floor( ( $expiry - time() ) / DAY_IN_SECONDS );
					$result = array( 'status' => $days < 0 ? 'expired' : ( $days < 15 ? 'warning' : 'valid' ), 'expires_at' => gmdate( 'c', $expiry ), 'days_remaining' => $days );
				}
			}
		}
		set_transient( 'shield_monitor_ssl_status', $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	private function security() {
		$events = $this->logger->counts( DAY_IN_SECONDS );
		$files  = ( new Shield_Monitor_File_Monitor( $this->settings, $this->logger ) )->status();
		$malware = ( new Shield_Monitor_Malware_Scanner( $this->settings, $this->logger ) )->status();
		$threats = ( new Shield_Monitor_Threat_Intelligence( $this->settings, $this->logger ) )->status();
		$login_protection = new Shield_Monitor_Login_Protection( $this->settings, $this->logger );
		return array(
			'https'               => is_ssl(),
			'login_protection'    => (bool) $this->settings->get( 'login_protection' ),
			'hide_login'          => (bool) $this->settings->get( 'hide_login' ),
			'login_url'           => home_url( '/' . sanitize_title( $this->settings->get( 'login_slug' ) ?: 'codewp-shield-monitor-login' ) . '/' ),
			'login_lockouts'      => $login_protection->lockout_rows(),
			'block_enumeration'   => (bool) $this->settings->get( 'block_enumeration' ),
			'security_headers'    => (bool) $this->settings->get( 'security_headers' ),
			'file_editor_disabled'=> (bool) $this->settings->get( 'disable_file_editor' ),
			'software_installation_disabled'=> (bool) $this->settings->get( 'disable_software_installation' ),
			'captcha_enabled'     => (bool) $this->settings->get( 'captcha_enabled' ),
			'captcha_provider'    => sanitize_key( $this->settings->get( 'captcha_provider' ) ?: 'turnstile' ),
			'admin_pin_enabled'   => (bool) $this->settings->get( 'admin_pin_enabled' ),
			'quick_login_enabled' => (bool) $this->settings->quick_login_enabled(),
			'quick_login_user_id' => absint( $this->settings->quick_login_user_id() ),
			'xmlrpc_disabled'     => (bool) $this->settings->get( 'disable_xmlrpc' ),
			'rest_api_restricted' => (bool) $this->settings->get( 'restrict_rest_api' ),
			'heartbeat_limited'   => (bool) $this->settings->get( 'limit_heartbeat' ),
			'email_alerts'        => (bool) $this->settings->get( 'email_alerts' ),
			'notifications'       => array(
				'contact_form_7' => (bool) $this->settings->get( 'notify_contact_form_7' ),
				'woocommerce'    => (bool) $this->settings->get( 'notify_woocommerce' ),
				'post_types'     => array_values( array_map( 'sanitize_key', (array) $this->settings->get( 'notify_post_types' ) ) ),
			),
			'events_24h'          => $events,
			'latest_login'        => $this->logger->latest_login(),
			'admin_logins_30_days'=> $this->logger->count_matching( array( 'admin_login' ), 30 * DAY_IN_SECONDS ),
			'new_admin_users_30_days'=> $this->logger->count_matching( array( 'admin_user_created' ), 30 * DAY_IN_SECONDS ),
			'failed_logins_30_days'=> $this->logger->count_matching( array( 'login_failed', 'login_lockout' ), 30 * DAY_IN_SECONDS ),
			'software_events_30_days'=> $this->logger->count_matching( array( 'software_updated', 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated' ), 30 * DAY_IN_SECONDS ),
			'post_updates_30_days' => $this->logger->count_matching( array( 'post_updated' ), 30 * DAY_IN_SECONDS ),
			'post_creations_30_days' => $this->logger->count_matching( array( 'post_created' ), 30 * DAY_IN_SECONDS ),
			'notification_events_30_days' => $this->logger->count_matching( array( 'contact_form_7_submission', 'woocommerce_order_created', 'custom_post_type_created' ), 30 * DAY_IN_SECONDS ),
			'file_integrity'      => $files,
			'malware_scan'        => $malware,
			'threat_intelligence' => $threats,
			'recent_file_changes_30_days' => $this->logger->recent_file_changes( 20, 30 * DAY_IN_SECONDS ),
			'recent_activity_30_days' => $this->logger->recent_activity_groups( 30 ),
		);
	}

	private function woocommerce() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'active' => false, 'orders' => array() );
		}
		$orders = wc_get_orders( array( 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
		$items  = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$items[] = array(
				'id'       => $order->get_id(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'created_at' => $created ? $created->date( DATE_ATOM ) : null,
			);
		}
		return array( 'active' => true, 'orders' => $items );
	}

	private function features() {
		return array(
			'uptime_monitoring' => 'app_managed', 'ssl_monitoring' => 'available', 'domain_monitoring' => 'app_managed',
			'update_monitoring' => 'available', 'system_monitoring' => 'available', 'woocommerce_notifications' => function_exists( 'wc_get_orders' ) ? 'available' : 'inactive',
			'push_notifications' => 'app_managed', 'login_monitoring' => 'available', 'file_monitoring' => 'available',
			'malware_detection' => 'available', 'vulnerability_scanner' => 'planned', 'team_management' => 'planned', 'white_label' => 'planned',
		);
	}

	private function health_score( $updates, $security ) {
		$score = 100;
		$score -= $security['https'] ? 0 : 20;
		foreach ( array( 'login_protection', 'block_enumeration', 'security_headers', 'file_editor_disabled' ) as $key ) {
			$score -= $security[ $key ] ? 0 : 10;
		}
		$score -= $security['file_integrity']['enabled'] ? 0 : 10;
		$score -= 'mismatch' === ( $security['file_integrity']['core_checksum']['status'] ?? '' ) ? 20 : 0;
		$malware_summary = $security['malware_scan']['summary'] ?? array();
		$threat_summary = $security['threat_intelligence']['summary'] ?? array();
		$score -= ! empty( $malware_summary['critical'] ) ? 25 : 0;
		$score -= ! empty( $malware_summary['high'] ) ? min( 20, absint( $malware_summary['high'] ) * 10 ) : 0;
		$score -= ! empty( $malware_summary['medium'] ) ? min( 10, absint( $malware_summary['medium'] ) * 2 ) : 0;
		$score -= ! empty( $threat_summary['critical'] ) ? min( 20, absint( $threat_summary['critical'] ) * 10 ) : 0;
		$score -= ! empty( $threat_summary['high'] ) ? min( 15, absint( $threat_summary['high'] ) * 5 ) : 0;
		$score -= ! empty( $security['file_integrity']['cron_overdue'] ) ? 5 : 0;
		$score -= $this->settings->get( 'email_alerts' ) ? 0 : 5;
		$score -= $updates['core']['update_available'] ? 15 : 0;
		$score -= min( 15, ( $updates['plugins']['update_count'] + $updates['themes']['update_count'] ) * 2 );
		return max( 0, $score );
	}
}
