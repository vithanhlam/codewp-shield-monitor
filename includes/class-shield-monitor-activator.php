<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Activator {
	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( SHIELD_MONITOR_FILE ) );
			wp_die( esc_html__( 'CodeWP Shield Monitor requires PHP 7.4 or newer.', 'codewp-shield-monitor' ) );
		}

		if ( false === get_option( Shield_Monitor_Settings::OPTION, false ) ) {
			$settings = new Shield_Monitor_Settings();
			add_option( Shield_Monitor_Settings::OPTION, $settings->defaults(), '', false );
		}
		if ( false === get_option( 'shield_monitor_api_tokens', false ) ) {
			add_option( 'shield_monitor_api_tokens', array(), '', false );
		}
		if ( false === get_option( Shield_Monitor_File_Monitor::HASHES_OPTION, false ) ) {
			add_option( Shield_Monitor_File_Monitor::HASHES_OPTION, array(), '', false );
		}
		if ( false === get_option( Shield_Monitor_File_Monitor::SCOPE_OPTION, false ) ) {
			add_option( Shield_Monitor_File_Monitor::SCOPE_OPTION, Shield_Monitor_File_Monitor::SCOPE_VERSION, '', false );
		}
		if ( false === get_option( Shield_Monitor_Login_Protection::REGISTRY_OPTION, false ) ) {
			add_option( Shield_Monitor_Login_Protection::REGISTRY_OPTION, array(), '', false );
		}
		if ( false === get_option( 'shield_monitor_file_last_scan', false ) ) {
			add_option( 'shield_monitor_file_last_scan', '', '', false );
		}
		foreach ( array( 'shield_monitor_file_baseline_created', 'shield_monitor_file_last_result', Shield_Monitor_File_Monitor::CORE_OPTION, Shield_Monitor_Malware_Scanner::REPORT_OPTION ) as $option ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, '', '', false );
			}
		}
		self::create_log_table();
		Shield_Monitor_File_Monitor::create_baseline_table();
		Shield_Monitor_Malware_Scanner::create_cache_table();
		Shield_Monitor_File_Monitor::activate();
	}

	private static function create_log_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'shield_monitor_events';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(50) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(64) NOT NULL DEFAULT '',
			details text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'shield_monitor_daily_cleanup' );
		wp_clear_scheduled_hook( Shield_Monitor_File_Monitor::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Malware_Scanner::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Malware_Scanner::CRON_HOOK, array( 'login' ) );
		wp_clear_scheduled_hook( Shield_Monitor_Backup_Alert::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Vulnerability_Alert::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Push::CRON_HOOK );
	}

	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}
		delete_option( Shield_Monitor_Settings::OPTION );
		delete_option( 'shield_monitor_db_version' );
		delete_option( 'shield_monitor_api_tokens' );
		delete_option( Shield_Monitor_File_Monitor::HASHES_OPTION );
		delete_option( Shield_Monitor_File_Monitor::SCOPE_OPTION );
		delete_option( Shield_Monitor_Login_Protection::REGISTRY_OPTION );
		delete_option( 'shield_monitor_file_baseline_db_version' );
		delete_option( 'shield_monitor_file_last_scan' );
		delete_option( 'shield_monitor_file_baseline_created' );
		delete_option( 'shield_monitor_file_last_result' );
		delete_option( Shield_Monitor_File_Monitor::CORE_OPTION );
		delete_option( Shield_Monitor_Malware_Scanner::REPORT_OPTION );
		delete_option( 'shield_monitor_malware_cache_db_version' );
		delete_transient( 'shield_monitor_core_checksum_cooldown' );
		delete_transient( 'shield_monitor_malware_login_scan_cooldown' );
		delete_transient( 'shield_monitor_ssl_status' );
		$pair_key = get_transient( 'shield_monitor_active_pair_key' );
		if ( is_string( $pair_key ) && $pair_key ) {
			delete_transient( $pair_key );
		}
		delete_transient( 'shield_monitor_active_pair_key' );
		wp_clear_scheduled_hook( 'shield_monitor_daily_cleanup' );
		wp_clear_scheduled_hook( Shield_Monitor_File_Monitor::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Malware_Scanner::CRON_HOOK );
		wp_clear_scheduled_hook( Shield_Monitor_Malware_Scanner::CRON_HOOK, array( 'login' ) );
		wp_clear_scheduled_hook( Shield_Monitor_Backup_Alert::CRON_HOOK );
		delete_option( Shield_Monitor_Backup_Alert::LAST_BACKUP_OPTION );
		delete_option( Shield_Monitor_Backup_Alert::ALERT_SENT_OPTION );
		delete_option( Shield_Monitor_Vulnerability_Alert::CACHE_OPTION );
		delete_option( Shield_Monitor_Vulnerability_Alert::ALERT_SENT_OPTION );
		wp_clear_scheduled_hook( Shield_Monitor_Vulnerability_Alert::CRON_HOOK );
		delete_option( Shield_Monitor_License::OPTION_KEY );
		delete_option( Shield_Monitor_License::STATUS_OPTION );
		delete_option( Shield_Monitor_License::NETWORK_FAIL_OPTION );
		delete_transient( Shield_Monitor_License::DATA_TRANSIENT );
		delete_option( Shield_Monitor_Push::LAST_PUSH_OPT );
		delete_option( Shield_Monitor_Push::PUSH_FAIL_OPT );
		wp_clear_scheduled_hook( Shield_Monitor_Push::CRON_HOOK );
		delete_option( 'shield_monitor_media_pro_settings' );
		delete_option( 'shield_monitor_media_pro_logs' );
		delete_option( 'shield_monitor_media_scan_result' );
		delete_option( 'shield_monitor_media_pro_total_saved' );
		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$report = trailingslashit( $upload['basedir'] ) . Shield_Monitor_Malware_Scanner::REPORT_DIR . '/' . Shield_Monitor_Malware_Scanner::report_filename();
			if ( is_file( $report ) ) {
				wp_delete_file( $report );
			}
		}
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$baseline_table = $wpdb->prefix . 'shield_monitor_file_baseline';
		$wpdb->query( "DROP TABLE IF EXISTS {$baseline_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$malware_cache_table = $wpdb->prefix . 'shield_monitor_malware_cache';
		$wpdb->query( "DROP TABLE IF EXISTS {$malware_cache_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
