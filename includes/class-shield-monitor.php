<?php
defined( 'ABSPATH' ) || exit;

final class Shield_Monitor {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run() {
		$settings  = new Shield_Monitor_Settings();
		$logger    = new Shield_Monitor_Audit_Log();
		$license   = new Shield_Monitor_License( $settings );
		$addons    = new Shield_Monitor_Addons( $license );
		$status    = new Shield_Monitor_Status( $settings, $logger );
		$api       = new Shield_Monitor_API( $status, $logger );
		$push      = new Shield_Monitor_Push( $status, $logger, $settings );
		$monitor   = new Shield_Monitor_File_Monitor( $settings, $logger );
		$scanner   = new Shield_Monitor_Malware_Scanner( $settings, $logger );
		$notifications = new Shield_Monitor_Notifications( $settings, $logger );

		$settings->register_hooks();
		$logger->register_hooks();
		$license->register_hooks();
		$addons->register_hooks();
		( new Shield_Monitor_WAF( $settings, $logger ) )->register_hooks();
		Shield_Monitor_Threat_Patterns::instance()->register_hooks();
		( new Shield_Monitor_Login_Protection( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_Hardening( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_Advanced_Hardening( $settings, $logger ) )->register_hooks();
		$monitor->register_hooks();
		$scanner->register_hooks();
		$notifications->register_hooks();
		( new Shield_Monitor_Alerts( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_Backup_Alert( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_Vulnerability_Alert( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_DB_Cleanup( $settings, $logger ) )->register_hooks();
		( new Shield_Monitor_Media_Optimization( $settings ) )->register_hooks();
		add_filter( 'cron_schedules', array( 'Shield_Monitor_Push', 'add_cron_interval' ) );
		$push->register_hooks();
		$api->register_hooks();

		if ( is_admin() ) {
			( new Shield_Monitor_Admin( $settings, $logger, $status, $api ) )->register_hooks();
			( new Shield_Monitor_License_Page( $license, $addons ) )->register_hooks();

			/**
			 * Defer installer + addon pages to plugins_loaded priority 10.
			 * Pro plugin registers its filter at priority 5, so by priority 10
			 * the installer is available.
			 */
			add_action( 'plugins_loaded', function() use ( $license, $addons ) {
				$installer = apply_filters( 'shieldpress_addon_installer', null, $license, $addons );
				if ( $installer && method_exists( $installer, 'register_hooks' ) ) {
					$installer->register_hooks();
				}
				( new Shield_Monitor_Addon_Pages( $license, $addons, $installer ) )->register_hooks();
			}, 10 );
		}
	}

	private function __construct() {}
	private function __clone() {}
}
