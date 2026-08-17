<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Alerts {
	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_action( 'wp_login', array( $this, 'admin_login' ), 20, 2 );
		add_action( 'shield_monitor_login_lockout', array( $this, 'login_lockout' ), 10, 1 );
		add_action( 'shield_monitor_files_changed', array( $this, 'files_changed' ), 10, 1 );
		add_action( 'shield_monitor_admin_user_created', array( $this, 'admin_user_created' ), 10, 2 );
	}

	public function admin_login( $user_login, $user ) {
		if ( ! $this->enabled( 'alert_admin_login' ) || ! user_can( $user, 'manage_options' ) ) {
			return;
		}
		$fingerprint = $this->fingerprint();
		$key         = 'shield_monitor_admin_alert_' . md5( $user->ID . $fingerprint );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 15 * MINUTE_IN_SECONDS );
		$subject = sprintf( __( '[CodeWP Shield Monitor] Administrator login on %s', 'codewp-shield-monitor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message = sprintf(
			__( "An administrator logged in.\n\nUser: %1\$s\nTime (UTC): %2\$s\nIP: %3\$s\nBrowser: %4\$s\nWebsite: %5\$s", 'codewp-shield-monitor' ),
			sanitize_user( $user_login ), gmdate( 'Y-m-d H:i:s' ), $this->ip(), $this->user_agent(), home_url( '/' )
		);
		$this->send( $subject, $message );
	}

	public function login_lockout( $attempts ) {
		if ( ! $this->enabled( 'alert_lockouts' ) || get_transient( 'shield_monitor_lockout_email_' . $this->fingerprint() ) ) {
			return;
		}
		set_transient( 'shield_monitor_lockout_email_' . $this->fingerprint(), 1, HOUR_IN_SECONDS );
		$this->send(
			sprintf( __( '[CodeWP Shield Monitor] Login attack blocked on %s', 'codewp-shield-monitor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf( __( "CodeWP Shield Monitor blocked repeated login attempts.\n\nAttempts: %1\$d\nTime (UTC): %2\$s\nIP: %3\$s\nWebsite: %4\$s", 'codewp-shield-monitor' ), absint( $attempts ), gmdate( 'Y-m-d H:i:s' ), $this->ip(), home_url( '/' ) )
		);
	}

	public function files_changed( $changes ) {
		if ( ! $this->enabled( 'alert_file_changes' ) ) {
			return;
		}
		$lines = array();
		foreach ( array_slice( (array) $changes, 0, 20 ) as $change ) {
			$lines[] = strtoupper( sanitize_key( $change['change'] ?? '' ) ) . ': ' . sanitize_text_field( $change['path'] ?? '' );
		}
		$this->send(
			sprintf( __( '[CodeWP Shield Monitor] Important files changed on %s', 'codewp-shield-monitor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf( __( "CodeWP Shield Monitor detected changes to monitored files. Verify that these changes were expected.\n\n%1\$s\n\nTime (UTC): %2\$s\nWebsite: %3\$s", 'codewp-shield-monitor' ), implode( "\n", $lines ), gmdate( 'Y-m-d H:i:s' ), home_url( '/' ) )
		);
	}

	public function admin_user_created( $user_id, $details ) {
		if ( ! $this->enabled( 'alert_admin_user_changes' ) ) {
			return;
		}
		$user     = get_user_by( 'id', absint( $user_id ) );
		$username = $user ? sanitize_user( $user->user_login, true ) : sanitize_text_field( $details['username'] ?? '' );
		$source   = sanitize_key( $details['source'] ?? '' );
		$this->send(
			sprintf( __( '[CodeWP Shield Monitor] New administrator user on %s', 'codewp-shield-monitor' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf( __( "A user now has administrator access. Verify that this change was expected.\n\nUser: %1\$s\nSource: %2\$s\nTime (UTC): %3\$s\nWebsite: %4\$s", 'codewp-shield-monitor' ), $username ?: __( 'Unknown', 'codewp-shield-monitor' ), $source ?: __( 'Unknown', 'codewp-shield-monitor' ), gmdate( 'Y-m-d H:i:s' ), home_url( '/' ) )
		);
	}

	private function enabled( $specific ) {
		return $this->settings->get( 'email_alerts' ) && $this->settings->get( $specific );
	}

	private function send( $subject, $message ) {
		wp_mail( sanitize_email( get_option( 'admin_email' ) ), $subject, $message );
	}

	private function fingerprint() {
		return hash_hmac( 'sha256', $this->ip() . '|' . $this->user_agent(), wp_salt( 'nonce' ) );
	}

	private function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : __( 'Unknown', 'codewp-shield-monitor' );
	}

	private function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 250 ) : __( 'Unknown', 'codewp-shield-monitor' );
	}
}
