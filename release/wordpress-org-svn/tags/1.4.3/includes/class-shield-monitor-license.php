<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_License {
	const OPTION_KEY    = 'shieldpress_license_key';
	const STATUS_OPTION = 'shieldpress_license_status';
	const DATA_TRANSIENT = 'shieldpress_license_data';

	const API_BASE       = 'https://api.shieldpress.net/api/licenses';
	const ACTIVATE_URL   = self::API_BASE . '/activate';
	const VERIFY_URL     = self::API_BASE . '/verify';
	const DEACTIVATE_URL = self::API_BASE . '/deactivate';
	const ADDONS_URL     = self::API_BASE . '/addons';

	const PLANS          = array( 'free', 'pro', 'business', 'agency' );
	const GRACE_DAYS     = 7;

	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks() {
		add_action( 'admin_post_shield_monitor_activate_license', array( $this, 'handle_activate' ) );
		add_action( 'admin_post_shield_monitor_deactivate_license', array( $this, 'handle_deactivate' ) );
		add_action( 'shield_monitor_daily_cleanup', array( $this, 'daily_verify' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the license-verify callback endpoint.
	 * The ShieldPress API server calls this to verify site ownership.
	 */
	public function register_routes() {
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/license-verify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_callback_verify' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'challenge' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/**
	 * Handle callback verification from ShieldPress API server.
	 * Proves this site has the plugin installed and the license key.
	 */
	public function handle_callback_verify( WP_REST_Request $request ) {
		$challenge   = $request->get_param( 'challenge' );
		$license_key = get_option( self::OPTION_KEY, '' );

		if ( empty( $license_key ) || empty( $challenge ) ) {
			return new WP_Error( 'shield_monitor_no_license', __( 'No license key configured.', 'codewp-shield-monitor' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array(
			'response_hash'  => hash( 'sha256', $license_key . $challenge ),
			'server_ip'      => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( $_SERVER['SERVER_ADDR'] ) : null,
			'plugin_version' => SHIELD_MONITOR_VERSION,
		) );
	}

	/**
	 * Get current license data.
	 */
	public function get_license() {
		$default = array(
			'key'              => '',
			'plan'             => 'free',
			'status'           => 'inactive',
			'billing_type'     => '',
			'source'           => '',
			'expires_at'       => null,
			'days_until_expiry' => null,
			'activated_at'     => null,
			'site_limit'       => 1,
			'activated_count'  => 0,
			'features'         => array(),
		);
		$data = get_option( self::STATUS_OPTION, array() );
		return wp_parse_args( is_array( $data ) ? $data : array(), $default );
	}

	/**
	 * Check if a specific feature/addon is allowed by the current plan.
	 *
	 * 'unverified' status (API unreachable) still allows features — we give
	 * customers the benefit of the doubt rather than punishing them for our
	 * infrastructure issues.
	 */
	public function is_feature_allowed( $feature_slug ) {
		$license = $this->get_license();
		if ( 'free' === $license['plan'] || 'inactive' === $license['status'] ) {
			return false;
		}
		if ( 'expired' === $license['status'] ) {
			return false;
		}
		// 'active' and 'unverified' both allow features.
		// If features array is empty, all features are allowed for the plan.
		if ( empty( $license['features'] ) ) {
			return true;
		}
		// Features must be addon slugs (lowercase, with dashes).
		// If the array contains display names (e.g. "Vulnerability scanning (CVE)")
		// instead of slugs, treat as "all allowed" — this is a legacy API response.
		$first = reset( $license['features'] );
		if ( $first && preg_match( '/[A-Z\s\(\)]/', $first ) ) {
			return true;
		}
		return in_array( $feature_slug, (array) $license['features'], true );
	}

	/**
	 * Get current plan name.
	 */
	public function current_plan() {
		$license = $this->get_license();
		return $license['plan'];
	}

	/**
	 * Check if license is active (not free, not expired).
	 * 'unverified' is treated as active — features keep working when API is down.
	 */
	public function is_active() {
		$license = $this->get_license();
		return in_array( $license['status'], array( 'active', 'unverified' ), true ) && 'free' !== $license['plan'];
	}

	/**
	 * Generate site fingerprint for anti-piracy verification.
	 */
	public function site_hash() {
		global $table_prefix;
		return hash( 'sha256', home_url( '/' ) . '|' . $table_prefix . '|' . AUTH_KEY );
	}

	/**
	 * Build the standard request body sent with every license API call.
	 */
	private function request_body( $license_key ) {
		return array(
			'license_key'    => $license_key,
			'site_url'       => home_url( '/' ),
			'site_hash'      => $this->site_hash(),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => SHIELD_MONITOR_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
		);
	}

	/**
	 * Handle license activation form submission.
	 */
	public function handle_activate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_activate_license' );

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$key = strtoupper( trim( $key ) );

		if ( empty( $key ) ) {
			$this->redirect_with_message( 'error', __( 'Please enter a license key.', 'codewp-shield-monitor' ) );
			return;
		}

		// Validate key format: SPMP-XXXX-XXXX-XXXX-XXXX (alphanumeric + dashes).
		if ( ! preg_match( '/^SPMP-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $key ) ) {
			$this->redirect_with_message( 'error', __( 'Invalid license key format. Expected: SPMP-XXXX-XXXX-XXXX-XXXX', 'codewp-shield-monitor' ) );
			return;
		}

		// Activate with remote API.
		$result = $this->remote_activate( $key );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', $result->get_error_message() );
			return;
		}

		// Save license data.
		$license_data = array(
			'key'              => $this->mask_key( $key ),
			'plan'             => strtolower( sanitize_text_field( $result['plan'] ?? 'pro' ) ),
			'status'           => 'active',
			'billing_type'     => sanitize_key( $result['billing_type'] ?? '' ),
			'source'           => sanitize_key( $result['source'] ?? 'standalone' ),
			'expires_at'       => sanitize_text_field( $result['expires_at'] ?? '' ),
			'days_until_expiry' => isset( $result['days_until_expiry'] ) ? absint( $result['days_until_expiry'] ) : null,
			'activated_at'     => gmdate( 'c' ),
			'site_limit'       => absint( $result['site_limit'] ?? 1 ),
			'activated_count'  => absint( $result['activated_count'] ?? 1 ),
			'features'         => isset( $result['features'] ) && is_array( $result['features'] ) ? array_map( 'sanitize_text_field', $result['features'] ) : array(),
		);
		update_option( self::STATUS_OPTION, $license_data, false );
		update_option( self::OPTION_KEY, $key, false );
		delete_transient( self::DATA_TRANSIENT );

		$this->redirect_with_message( 'success', sprintf(
			/* translators: %s: plan name */
			__( 'License activated successfully! Plan: %s', 'codewp-shield-monitor' ),
			ucfirst( $license_data['plan'] )
		) );
	}

	/**
	 * Handle license deactivation.
	 */
	public function handle_deactivate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_deactivate_license' );

		$key = get_option( self::OPTION_KEY, '' );
		if ( $key ) {
			$this->remote_deactivate( $key );
		}

		delete_option( self::OPTION_KEY );
		update_option( self::STATUS_OPTION, array(
			'key'              => '',
			'plan'             => 'free',
			'status'           => 'inactive',
			'billing_type'     => '',
			'source'           => '',
			'expires_at'       => null,
			'days_until_expiry' => null,
			'activated_at'     => null,
			'site_limit'       => 1,
			'activated_count'  => 0,
			'features'         => array(),
		), false );
		delete_transient( self::DATA_TRANSIENT );

		$this->redirect_with_message( 'success', __( 'License deactivated. Plan reverted to Free.', 'codewp-shield-monitor' ) );
	}

	/**
	 * Maximum consecutive network failures before forcing expired status.
	 * At one check per day, 3 failures = 3 days of API downtime tolerance.
	 */
	const MAX_NETWORK_FAILURES = 3;
	const NETWORK_FAIL_OPTION  = 'shieldpress_license_net_failures';

	/**
	 * Daily license verification via cron.
	 *
	 * IMPORTANT: Network errors (timeout, DNS failure, server down) must NOT
	 * invalidate the license. Only an explicit rejection from the API server
	 * (valid=false) should change the license status. This prevents a DDoS
	 * attack on api.shieldpress.net from disabling all customer Pro features.
	 */
	public function daily_verify() {
		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			return;
		}

		$result = $this->remote_verify( $key );

		// Network / connectivity error — keep current license status unchanged.
		if ( is_wp_error( $result ) ) {
			$failures = absint( get_option( self::NETWORK_FAIL_OPTION, 0 ) ) + 1;
			update_option( self::NETWORK_FAIL_OPTION, $failures, false );

			if ( $failures >= self::MAX_NETWORK_FAILURES ) {
				// After 3 consecutive days of failures, mark as unverified (not expired).
				// Features remain active but admin sees a warning.
				$license = $this->get_license();
				$license['status'] = 'unverified';
				update_option( self::STATUS_OPTION, $license, false );
			}

			return;
		}

		// API responded successfully — reset failure counter.
		delete_option( self::NETWORK_FAIL_OPTION );

		// API explicitly says license is not valid — this is the ONLY path to expired.
		if ( empty( $result['valid'] ) ) {
			$license = $this->get_license();
			$license['status'] = sanitize_key( $result['status'] ?? 'expired' );
			if ( 'expired' === $license['status'] ) {
				$license['days_until_expiry'] = isset( $result['days_until_expiry'] ) ? intval( $result['days_until_expiry'] ) : -999;
			}
			update_option( self::STATUS_OPTION, $license, false );
			delete_transient( self::DATA_TRANSIENT );
			return;
		}

		// License is valid — update all data from server response.
		$license = $this->get_license();
		$license['plan']             = strtolower( sanitize_text_field( $result['plan'] ?? $license['plan'] ) );
		$license['status']           = 'active';
		$license['billing_type']     = sanitize_key( $result['billing_type'] ?? $license['billing_type'] );
		$license['source']           = sanitize_key( $result['source'] ?? $license['source'] );
		$license['expires_at']       = sanitize_text_field( $result['expires_at'] ?? $license['expires_at'] );
		$license['days_until_expiry'] = isset( $result['days_until_expiry'] ) ? intval( $result['days_until_expiry'] ) : null;
		$license['site_limit']       = absint( $result['site_limit'] ?? $license['site_limit'] );
		$license['activated_count']  = absint( $result['activated_count'] ?? $license['activated_count'] );
		$license['features']         = isset( $result['features'] ) && is_array( $result['features'] ) ? array_map( 'sanitize_text_field', $result['features'] ) : $license['features'];
		update_option( self::STATUS_OPTION, $license, false );

		// Cache license data in transient for 24 hours.
		set_transient( self::DATA_TRANSIENT, $result, DAY_IN_SECONDS );
	}

	/**
	 * Activate license with remote API.
	 *
	 * POST https://api.shieldpress.net/api/licenses/activate
	 */
	private function remote_activate( $key ) {
		$response = wp_remote_post( self::ACTIVATE_URL, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $this->request_body( $key ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'shield_monitor_license_error', __( 'Could not connect to the license server. Please try again later.', 'codewp-shield-monitor' ) );
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$body     = json_decode( $raw_body, true );

		// Server error (500, 502, 503).
		if ( $code >= 500 ) {
			$server_msg = is_array( $body ) && ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : '';
			return new WP_Error(
				'shield_monitor_license_server_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'License server returned error %d. Please try again in a few minutes.', 'codewp-shield-monitor' ),
					$code
				) . ( $server_msg ? ' (' . $server_msg . ')' : '' )
			);
		}

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'shield_monitor_license_invalid', __( 'Invalid response from license server.', 'codewp-shield-monitor' ) );
		}

		if ( ! empty( $body['error'] ) ) {
			return new WP_Error( 'shield_monitor_license_rejected', sanitize_text_field( $body['error'] ) );
		}

		if ( 200 !== $code || empty( $body['activated'] ) ) {
			$message = ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'License activation failed. Please check your key.', 'codewp-shield-monitor' );
			return new WP_Error( 'shield_monitor_license_invalid', $message );
		}

		return $body;
	}

	/**
	 * Verify license with remote API.
	 *
	 * POST https://api.shieldpress.net/api/licenses/verify
	 *
	 * Returns:
	 * - array  on success (API responded with valid JSON)
	 * - WP_Error with code 'network_error'  when server is unreachable (timeout, DNS, 502/503)
	 * - WP_Error with code 'license_error'  when API explicitly rejected the request (400/401/403)
	 */
	private function remote_verify( $key ) {
		$response = wp_remote_post( self::VERIFY_URL, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $this->request_body( $key ) ),
		) );

		// Connection failed entirely (timeout, DNS failure, SSL error).
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Server-side errors (502, 503, 500) — treat as network/infrastructure issue.
		if ( $code >= 500 ) {
			return new WP_Error( 'network_error', sprintf( 'License server returned HTTP %d', $code ) );
		}

		// Client errors (400, 401, 403) — API explicitly rejected the request.
		if ( $code >= 400 || ! is_array( $body ) ) {
			return is_array( $body ) ? $body : new WP_Error( 'license_error', __( 'License verification failed.', 'codewp-shield-monitor' ) );
		}

		return $body;
	}

	/**
	 * Deactivate license with remote API.
	 *
	 * POST https://api.shieldpress.net/api/licenses/deactivate
	 */
	private function remote_deactivate( $key ) {
		wp_remote_post( self::DEACTIVATE_URL, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'license_key' => $key,
				'site_url'    => home_url( '/' ),
			) ),
		) );
	}

	/**
	 * Get expiry warning level for admin UI display.
	 *
	 * status = unverified         → unverified (API connectivity issue)
	 * days_until_expiry > 30      → none
	 * days_until_expiry ≤ 30      → yellow
	 * days_until_expiry ≤ 7       → red
	 * days_until_expiry ≤ 0       → grace
	 * days_until_expiry < -7      → expired
	 */
	public function expiry_warning_level() {
		$license = $this->get_license();
		$days    = $license['days_until_expiry'];

		if ( null === $days || 'inactive' === $license['status'] ) {
			return 'none';
		}
		if ( 'unverified' === $license['status'] ) {
			return 'unverified';
		}
		if ( $days < -self::GRACE_DAYS ) {
			return 'expired';
		}
		if ( $days <= 0 ) {
			return 'grace';
		}
		if ( $days <= 7 ) {
			return 'red';
		}
		if ( $days <= 30 ) {
			return 'yellow';
		}
		return 'none';
	}

	/**
	 * Mask license key for display (show prefix + last 4 chars).
	 * SPMP-A1B2-C3D4-E5F6-G7H8 → SPMP-****-****-****-G7H8
	 */
	private function mask_key( $key ) {
		$len = strlen( $key );
		if ( $len <= 9 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 5 ) . '****-****-****-' . substr( $key, -4 );
	}

	/**
	 * Redirect back to license page with message.
	 */
	private function redirect_with_message( $type, $message ) {
		set_transient( 'shield_monitor_license_message', array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor-license' ) );
		exit;
	}
}
