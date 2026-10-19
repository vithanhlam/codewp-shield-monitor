<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Backup_Alert {
	const LAST_BACKUP_OPTION = 'shield_monitor_last_backup_detected';
	const ALERT_SENT_OPTION  = 'shield_monitor_backup_alert_sent';
	const CRON_HOOK          = 'shield_monitor_backup_check';

	private $settings;
	private $logger;

	/**
	 * Backup plugins whose activity we detect.
	 */
	private $backup_plugins = array(
		'updraftplus'  => 'updraftplus',
		'backwpup'     => 'backwpup',
		'backupbuddy'  => 'backupbuddy',
		'solid-backups' => 'solid-backups',
		'wpvivid'      => 'wpvivid',
		'duplicator'   => 'duplicator',
		'blogvault'    => 'blogvault',
		'jetpack'      => 'jetpack',
	);

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		// Schedule daily check.
		add_action( self::CRON_HOOK, array( $this, 'check_backup_status' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		// Listen for backup completion from popular plugins.
		add_action( 'updraftplus_backup_completed', array( $this, 'record_backup' ) );
		add_action( 'backwpup_after_backup', array( $this, 'record_backup' ) );
		add_action( 'wpvivid_backup_completed', array( $this, 'record_backup' ) );
		add_action( 'duplicator_after_backup', array( $this, 'record_backup' ) );
		add_action( 'duplicator_pro_after_backup', array( $this, 'record_backup' ) );
		add_action( 'blogvault_backup_completed', array( $this, 'record_backup' ) );
		add_filter( 'jetpack_backup_complete', array( $this, 'record_backup_filter' ) );

		// All-in-One WP Migration.
		add_action( 'ai1wm_status_export_done', array( $this, 'record_backup' ) );

		// BackupGuard.
		add_action( 'backup_guard_after_backup', array( $this, 'record_backup' ) );

		// XCloner.
		add_action( 'xcloner_backup_finished', array( $this, 'record_backup' ) );

		// MainWP child.
		add_action( 'mainwp_child_after_backup', array( $this, 'record_backup' ) );

		// WP JEERO / Total Upkeep (BoldGrid).
		add_action( 'boldgrid_backup_complete', array( $this, 'record_backup' ) );

		// Jeero / ManageWP Worker.
		add_action( 'mwp_backup_complete', array( $this, 'record_backup' ) );

		// Jeero / InfiniteWP.
		add_action( 'iwp_backup_completed', array( $this, 'record_backup' ) );

		// Manual mark as backed up.
		add_action( 'admin_post_shield_monitor_mark_backup', array( $this, 'handle_mark_backup' ) );

		// Admin notice for overdue backups.
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );

		// REST API endpoint for backup alert status.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST route for backup alert status.
	 */
	public function register_routes() {
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/backup-status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'api_backup_status' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * API endpoint: return backup alert status.
	 */
	public function api_backup_status( WP_REST_Request $request ) {
		$last_backup = get_option( self::LAST_BACKUP_OPTION, 0 );
		$now         = time();
		$days_since  = $last_backup ? floor( ( $now - absint( $last_backup ) ) / DAY_IN_SECONDS ) : null;
		$thresholds  = $this->get_thresholds();
		$alert_level = $this->determine_alert_level( $days_since );

		$response = rest_ensure_response( array(
			'api_version'    => 1,
			'generated_at'   => gmdate( 'c' ),
			'last_backup_at' => $last_backup ? gmdate( 'c', absint( $last_backup ) ) : null,
			'days_since'     => $days_since,
			'thresholds'     => $thresholds,
			'alert_level'    => $alert_level,
			'alert_message'  => $this->alert_message( $alert_level, $days_since ),
		) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Record that a backup was completed.
	 */
	public function record_backup() {
		update_option( self::LAST_BACKUP_OPTION, time(), false );
		delete_option( self::ALERT_SENT_OPTION );
	}

	/**
	 * Filter variant for plugins that use filters instead of actions.
	 */
	public function record_backup_filter( $value ) {
		$this->record_backup();
		return $value;
	}

	/**
	 * Handle manual "Mark as backed up" from the dashboard.
	 */
	public function handle_mark_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_mark_backup' );

		$this->record_backup();
		$this->logger->record( 'backup_marked_manual', array( 'source' => 'manual' ), get_current_user_id() );

		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#settings' ) );
		exit;
	}

	/**
	 * Daily cron: check if backup is overdue and send alerts.
	 */
	public function check_backup_status() {
		if ( ! $this->settings->get( 'email_alerts' ) ) {
			return;
		}
		if ( ! $this->settings->get( 'alert_backup_overdue' ) ) {
			return;
		}

		$last_backup = get_option( self::LAST_BACKUP_OPTION, 0 );

		// If we've never recorded a backup, try to detect from backup plugin options.
		if ( ! $last_backup ) {
			$last_backup = $this->detect_last_backup_time();
			if ( $last_backup ) {
				update_option( self::LAST_BACKUP_OPTION, $last_backup, false );
			}
		}

		// If still no record, skip (we can't determine overdue status).
		if ( ! $last_backup ) {
			return;
		}

		$days_since  = floor( ( time() - absint( $last_backup ) ) / DAY_IN_SECONDS );
		$alert_level = $this->determine_alert_level( $days_since );

		if ( ! $alert_level ) {
			return;
		}

		// Check if we already sent an alert for this level.
		$sent = get_option( self::ALERT_SENT_OPTION, array() );
		if ( ! is_array( $sent ) ) {
			$sent = array();
		}

		if ( ! empty( $sent[ $alert_level ] ) ) {
			return;
		}

		// Send email alert.
		$this->send_email_alert( $alert_level, $days_since );

		// Log the event.
		$this->logger->record( 'backup_overdue_alert', array(
			'alert_level' => $alert_level,
			'days_since'  => $days_since,
			'last_backup' => gmdate( 'c', absint( $last_backup ) ),
		) );

		// Push notification via API (fire action for external consumers).
		do_action( 'shield_monitor_backup_overdue', array(
			'alert_level' => $alert_level,
			'days_since'  => $days_since,
			'last_backup' => gmdate( 'c', absint( $last_backup ) ),
			'site_url'    => home_url( '/' ),
			'site_name'   => get_bloginfo( 'name' ),
		) );

		// Mark this level as sent.
		$sent[ $alert_level ] = time();
		update_option( self::ALERT_SENT_OPTION, $sent, false );
	}

	/**
	 * Show admin notice when backup is overdue.
	 */
	public function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->settings->get( 'alert_backup_overdue' ) ) {
			return;
		}

		$last_backup = get_option( self::LAST_BACKUP_OPTION, 0 );
		if ( ! $last_backup ) {
			return;
		}

		$days_since  = floor( ( time() - absint( $last_backup ) ) / DAY_IN_SECONDS );
		$alert_level = $this->determine_alert_level( $days_since );

		if ( ! $alert_level ) {
			return;
		}

		$class   = 'critical' === $alert_level ? 'notice-error' : ( 'warning' === $alert_level ? 'notice-warning' : 'notice-info' );
		$message = $this->alert_message( $alert_level, $days_since );
		printf(
			'<div class="notice %s is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_attr( $class ),
			esc_html__( '[Shield Monitor]', 'codewp-shield-monitor' ),
			esc_html( $message )
		);
	}

	/**
	 * Get alert thresholds in days.
	 */
	private function get_thresholds() {
		return array(
			'notice'   => 3,
			'warning'  => 7,
			'critical' => 30,
		);
	}

	/**
	 * Determine alert level based on days since last backup.
	 */
	private function determine_alert_level( $days_since ) {
		if ( null === $days_since ) {
			return null;
		}

		$thresholds = $this->get_thresholds();

		if ( $days_since >= $thresholds['critical'] ) {
			return 'critical';
		}
		if ( $days_since >= $thresholds['warning'] ) {
			return 'warning';
		}
		if ( $days_since >= $thresholds['notice'] ) {
			return 'notice';
		}

		return null;
	}

	/**
	 * Generate human-readable alert message.
	 */
	private function alert_message( $alert_level, $days_since ) {
		if ( ! $alert_level || null === $days_since ) {
			return '';
		}

		switch ( $alert_level ) {
			case 'critical':
				return sprintf(
					/* translators: %d: number of days */
					__( 'CRITICAL: No backup detected for %d days (over 1 month). Your website data is at serious risk. Please run a backup immediately.', 'codewp-shield-monitor' ),
					absint( $days_since )
				);
			case 'warning':
				return sprintf(
					/* translators: %d: number of days */
					__( 'WARNING: No backup detected for %d days. It is recommended to back up your website at least weekly.', 'codewp-shield-monitor' ),
					absint( $days_since )
				);
			case 'notice':
				return sprintf(
					/* translators: %d: number of days */
					__( 'NOTICE: No backup detected for %d days. Consider running a backup soon to protect your data.', 'codewp-shield-monitor' ),
					absint( $days_since )
				);
		}

		return '';
	}

	/**
	 * Send email alert for overdue backup.
	 */
	private function send_email_alert( $alert_level, $days_since ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: 1: site name, 2: alert level */
			__( '[CodeWP Shield Monitor] Backup %1$s on %2$s', 'codewp-shield-monitor' ),
			strtoupper( $alert_level ),
			$site_name
		);

		$message = sprintf(
			/* translators: 1: alert message, 2: site URL, 3: current UTC time */
			__( "Backup Alert\n\n%1\$s\n\nWebsite: %2\$s\nTime (UTC): %3\$s\n\nPlease log in to your hosting panel or backup plugin and create a full backup as soon as possible.", 'codewp-shield-monitor' ),
			$this->alert_message( $alert_level, $days_since ),
			home_url( '/' ),
			gmdate( 'Y-m-d H:i:s' )
		);

		wp_mail( sanitize_email( get_option( 'admin_email' ) ), $subject, $message );
	}

	/**
	 * Try to detect last backup time from popular backup plugins.
	 *
	 * Returns the most recent timestamp found across all supported plugins.
	 */
	private function detect_last_backup_time() {
		$timestamps = array();

		// UpdraftPlus.
		$updraft = get_option( 'updraft_last_backup', array() );
		if ( is_array( $updraft ) && ! empty( $updraft['backup_time'] ) ) {
			$timestamps[] = absint( $updraft['backup_time'] );
		}

		// BackWPup.
		$backwpup = get_site_option( 'backwpup_last_backup_time', 0 );
		if ( $backwpup ) {
			$timestamps[] = absint( $backwpup );
		}

		// WPvivid.
		$wpvivid = get_option( 'wpvivid_last_backup_time', 0 );
		if ( $wpvivid ) {
			$timestamps[] = absint( $wpvivid );
		}

		// Duplicator Pro — last backup package timestamp.
		$dup_data = get_option( 'duplicator_pro_package_active', '' );
		if ( ! empty( $dup_data ) && is_object( $dup_data ) && ! empty( $dup_data->Created ) ) {
			$ts = strtotime( $dup_data->Created );
			if ( $ts ) {
				$timestamps[] = absint( $ts );
			}
		}

		// All-in-One WP Migration — last export timestamp.
		$ai1wm = get_option( 'ai1wm_backups', array() );
		if ( is_array( $ai1wm ) && ! empty( $ai1wm ) ) {
			foreach ( $ai1wm as $entry ) {
				if ( ! empty( $entry['timestamp'] ) ) {
					$timestamps[] = absint( $entry['timestamp'] );
				}
			}
		}

		// BackupBuddy.
		$bb = get_option( 'backupbuddy_last_backup_serial', '' );
		if ( ! empty( $bb ) ) {
			$bb_recent = get_option( 'backupbuddy_recent_backup_' . $bb, array() );
			if ( is_array( $bb_recent ) && ! empty( $bb_recent['finish_time'] ) ) {
				$timestamps[] = absint( $bb_recent['finish_time'] );
			}
		}

		// BlogVault — last backup timestamp.
		$bv = get_option( 'bv_last_backup_time', 0 );
		if ( $bv ) {
			$timestamps[] = absint( $bv );
		}

		// XCloner.
		$xcloner = get_option( 'xcloner_last_backup_timestamp', 0 );
		if ( $xcloner ) {
			$timestamps[] = absint( $xcloner );
		}

		// BackupGuard.
		$bg = get_option( 'backup_guard_last_backup_time', 0 );
		if ( $bg ) {
			$timestamps[] = absint( $bg );
		}

		// BoldGrid Total Upkeep.
		$boldgrid = get_option( 'boldgrid_backup_last_backup', 0 );
		if ( $boldgrid ) {
			$timestamps[] = absint( $boldgrid );
		}

		return ! empty( $timestamps ) ? max( $timestamps ) : 0;
	}
}
