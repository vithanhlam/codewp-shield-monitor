<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_API {
	const TOKENS_OPTION = 'shield_monitor_api_tokens';
	const PAIR_TTL      = 600;
	const TOKEN_TTL     = 31536000;
	const QUICK_LOGIN_TTL = 60;

	private $status;
	private $logger;
	private $settings;
	private $authenticated_token_id;

	public function __construct( $status, $logger ) {
		$this->status   = $status;
		$this->logger   = $logger;
		$this->settings = new Shield_Monitor_Settings();
	}

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_shield_monitor_revoke_token', array( $this, 'revoke_token' ) );
		add_action( 'admin_post_shield_monitor_toggle_token_scope', array( $this, 'toggle_token_scope' ) );
		add_action( 'admin_post_nopriv_shield_monitor_quick_login', array( $this, 'consume_quick_login' ) );
		add_action( 'admin_post_shield_monitor_quick_login', array( $this, 'consume_quick_login' ) );
		add_action( 'shield_monitor_daily_cleanup', array( $this, 'cleanup_expired_tokens' ) );
	}

	public function routes() {
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/pair', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'pair' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'code' => array( 'required' => true, 'type' => 'string', 'minLength' => 8, 'maxLength' => 32, 'sanitize_callback' => 'sanitize_text_field' ),
				'client_name' => array( 'required' => false, 'type' => 'string', 'maxLength' => 50, 'sanitize_callback' => 'sanitize_text_field' ),
				'client_type' => array( 'required' => false, 'type' => 'string', 'enum' => array( 'app', 'web' ), 'default' => 'app' ),
			),
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'status' ),
			'permission_callback' => array( $this, 'authorize_status' ),
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/public-score', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'public_score' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/public/scan', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'public_scan' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'events' ),
			'permission_callback' => array( $this, 'authorize_events' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'sanitize_callback' => 'absint' ),
				'after_id' => array( 'type' => 'integer', 'minimum' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/token/rotate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rotate_token' ),
			'permission_callback' => array( $this, 'authorize_rotation' ),
		) );
		register_rest_route( CODEWP_SHIELD_MONITOR_API_NAMESPACE, '/quick-login', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'quick_login' ),
			'permission_callback' => array( $this, 'authorize_quick_login' ),
		) );
	}

	public function create_pairing_payload() {
		$previous_key = get_transient( 'shield_monitor_active_pair_key' );
		if ( is_string( $previous_key ) && $previous_key ) {
			delete_transient( $previous_key );
		}
		$code = $this->manual_code();
		$key  = $this->pair_key( $code );
		set_transient( $key, array( 'created_by' => get_current_user_id(), 'created_at' => time() ), self::PAIR_TTL );
		set_transient( 'shield_monitor_active_pair_key', $key, self::PAIR_TTL );
		return array(
			'api_version' => 1,
			'schema_version' => '1.8',
			'type'       => 'codewp_shield_monitor_pairing',
			'version'    => 1,
			'site_name'  => get_bloginfo( 'name' ),
			'site_url'   => home_url( '/' ),
			'pair_url'   => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/pair' ),
			'code'       => $code,
			'expires_at' => gmdate( 'c', time() + self::PAIR_TTL ),
		);
	}

	private function pair_key( $code ) {
		$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $code ) );
		return 'shield_monitor_pair_' . hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) );
	}

	private function rate_key() {
		$ip = $this->current_ip();
		return 'shield_monitor_pair_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	public function pair( WP_REST_Request $request ) {
		$rate_key = $this->rate_key();
		$attempts = absint( get_transient( $rate_key ) );
		if ( $attempts >= 10 ) {
			return new WP_Error( 'shield_monitor_rate_limited', __( 'Too many pairing attempts. Try again later.', 'codewp-shield-monitor' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
		$code = (string) $request->get_param( 'code' );
		$key  = $this->pair_key( $code );
		$pair = get_transient( $key );
		if ( ! is_array( $pair ) ) {
			return new WP_Error( 'shield_monitor_invalid_pairing', __( 'The pairing code is invalid or has expired.', 'codewp-shield-monitor' ), array( 'status' => 403 ) );
		}
		delete_transient( $key );
		delete_transient( 'shield_monitor_active_pair_key' );

		$token    = $this->base64url( random_bytes( 32 ) );
		$token_id = wp_generate_uuid4();
		$tokens   = $this->tokens();
		$type     = 'web' === $request->get_param( 'client_type' ) ? 'Web' : 'App';
		$name     = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
		$tokens[ $token_id ] = array(
			'hash'       => $this->token_hash( $token ),
			'label'      => $name ? $name : CODEWP_SHIELD_MONITOR_NAME . ' ' . $type,
			'type'       => strtolower( $type ),
			'created_at' => gmdate( 'c' ),
			'last_used'  => null,
			'expires_at' => gmdate( 'c', time() + self::TOKEN_TTL ),
			'scopes'     => $this->default_scopes(),
		);
		update_option( self::TOKENS_OPTION, $tokens, false );
		delete_transient( $rate_key );
		$this->logger->record( 'app_paired', array( 'token_id' => $token_id ), absint( $pair['created_by'] ) );

		$response = rest_ensure_response( array(
			'api_version' => 1,
			'schema_version' => '1.8',
			'token'      => $token,
			'token_type' => 'Bearer',
			'status_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/status' ),
			'events_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/events' ),
			'rotate_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/token/rotate' ),
			'quick_login_url' => rest_url( CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/quick-login' ),
			'site_url'   => home_url( '/' ),
			'expires_at' => $tokens[ $token_id ]['expires_at'],
			'scopes'     => $tokens[ $token_id ]['scopes'],
		) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public function authorize_status( WP_REST_Request $request ) {
		return $this->authorize( $request, 'status:read' );
	}

	public function authorize_events( WP_REST_Request $request ) {
		return $this->authorize( $request, 'events:read' );
	}

	public function authorize_rotation( WP_REST_Request $request ) {
		return $this->authorize( $request, 'token:rotate' );
	}

	public function authorize_quick_login( WP_REST_Request $request ) {
		return $this->authorize( $request, 'quick-login:create' );
	}

	private function authorize( WP_REST_Request $request, $required_scope ) {
		$token = $request->get_header( 'x-codewp-shield-monitor-token' );
		if ( ! $token ) {
			$token = $request->get_header( 'x-shield-monitor-token' );
		}
		if ( ! $token ) {
			$authorization = $request->get_header( 'authorization' );
			if ( preg_match( '/^Bearer\s+(.+)$/i', (string) $authorization, $matches ) ) {
				$token = trim( $matches[1] );
			}
		}
		if ( ! is_string( $token ) || strlen( $token ) < 32 || strlen( $token ) > 128 ) {
			return new WP_Error( 'shield_monitor_unauthorized', __( 'A valid CodeWP Shield Monitor API token is required.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
		}
		$provided = $this->token_hash( $token );
		$tokens   = $this->tokens();
		foreach ( $tokens as $id => $saved ) {
			if ( isset( $saved['hash'] ) && hash_equals( $saved['hash'], $provided ) ) {
				$expires = ! empty( $saved['expires_at'] ) ? strtotime( $saved['expires_at'] ) : 0;
				if ( $expires && $expires <= time() ) {
					return new WP_Error( 'shield_monitor_token_expired', __( 'This CodeWP Shield Monitor API token has expired.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
				}
				// Defaults apply only to legacy tokens without scope metadata. Never
				// silently grant default scopes that an explicitly scoped token lacks.
				$scopes = isset( $saved['scopes'] ) && is_array( $saved['scopes'] ) ? $saved['scopes'] : $this->default_scopes();
				if ( ! in_array( $required_scope, $scopes, true ) ) {
					if ( 'quick-login:create' === $required_scope ) {
						$this->logger->record( 'quick_login_failed', array( 'reason' => 'scope_forbidden', 'token_id' => $id ) );
					}
					return new WP_Error( 'shield_monitor_forbidden_scope', __( 'The API token does not allow this action.', 'codewp-shield-monitor' ), array( 'status' => 403 ) );
				}
				if ( ! $this->allow_api_request( $id ) ) {
					return new WP_Error( 'shield_monitor_rate_limited', __( 'Too many API requests. Try again shortly.', 'codewp-shield-monitor' ), array( 'status' => 429 ) );
				}
				$this->authenticated_token_id = $id;
				$this->touch_token( $id, $tokens );
				return true;
			}
		}
		return new WP_Error( 'shield_monitor_unauthorized', __( 'A valid CodeWP Shield Monitor API token is required.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
	}

	public function status() {
		$response = rest_ensure_response( $this->status->collect() );
		return $this->private_response( $response );
	}

	public function quick_login() {
		if ( ! $this->settings->quick_login_enabled() ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'disabled', 'token_id' => $this->authenticated_token_id ) );
			return new WP_Error( 'shield_monitor_quick_login_disabled', __( 'Quick login is disabled for this site.', 'codewp-shield-monitor' ), array( 'status' => 403 ) );
		}
		if ( ! $this->allow_quick_login_request( $this->authenticated_token_id ) ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'rate_limited', 'token_id' => $this->authenticated_token_id ) );
			return new WP_Error( 'shield_monitor_rate_limited', __( 'Too many quick-login requests. Try again shortly.', 'codewp-shield-monitor' ), array( 'status' => 429 ) );
		}

		$user_id = $this->settings->quick_login_user_id();
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'user_missing', 'token_id' => $this->authenticated_token_id, 'user_id' => $user_id ) );
			return new WP_Error( 'shield_monitor_quick_login_user_missing', __( 'No valid administrator is configured for quick login.', 'codewp-shield-monitor' ), array( 'status' => 409 ) );
		}

		$token = $this->base64url( random_bytes( 32 ) );
		$key   = $this->quick_login_key( $token );
		set_transient(
			$key,
			array(
				'user_id'    => absint( $user_id ),
				'token_id'   => sanitize_text_field( (string) $this->authenticated_token_id ),
				'created_at' => time(),
			),
			self::QUICK_LOGIN_TTL
		);

		$this->logger->record( 'quick_login_link_created', array( 'token_id' => $this->authenticated_token_id, 'user_id' => $user_id ), $user_id );
		$response = rest_ensure_response(
			array(
				'api_version' => 1,
				'schema_version' => '1.8',
				'login_url'  => add_query_arg( 'shield_monitor_quick_login_token', $token, admin_url( 'admin-post.php?action=shield_monitor_quick_login' ) ),
				'expires_at' => gmdate( 'c', time() + self::QUICK_LOGIN_TTL ),
				'user_id'    => absint( $user_id ),
				'username'   => sanitize_user( $user->user_login, true ),
			)
		);
		return $this->private_response( $response );
	}

	public function consume_quick_login() {
		$token = isset( $_GET['shield_monitor_quick_login_token'] ) ? sanitize_text_field( wp_unslash( $_GET['shield_monitor_quick_login_token'] ) ) : '';
		if ( ! is_string( $token ) || strlen( $token ) < 32 || strlen( $token ) > 128 ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'invalid_token_format' ) );
			wp_die( esc_html__( 'Invalid quick-login link.', 'codewp-shield-monitor' ), esc_html__( 'Quick login failed', 'codewp-shield-monitor' ), array( 'response' => 403 ) );
		}

		$key  = $this->quick_login_key( $token );
		$data = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'expired_or_missing' ) );
			wp_die( esc_html__( 'This quick-login link is invalid or expired.', 'codewp-shield-monitor' ), esc_html__( 'Quick login failed', 'codewp-shield-monitor' ), array( 'response' => 403 ) );
		}

		$user_id = absint( $data['user_id'] );
		$user    = get_user_by( 'id', $user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) || ! $this->settings->quick_login_enabled() ) {
			$this->logger->record( 'quick_login_failed', array( 'reason' => 'user_unavailable', 'token_id' => sanitize_text_field( $data['token_id'] ?? '' ), 'user_id' => $user_id ), $user_id );
			wp_die( esc_html__( 'Quick login is not available for this administrator.', 'codewp-shield-monitor' ), esc_html__( 'Quick login failed', 'codewp-shield-monitor' ), array( 'response' => 403 ) );
		}

		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		$this->logger->record( 'quick_login_used', array( 'token_id' => sanitize_text_field( $data['token_id'] ?? '' ) ), $user_id );
		wp_safe_redirect( admin_url() );
		exit;
	}

	public function public_score() {
		if ( ! $this->allow_public_score_request() ) {
			return new WP_Error( 'shield_monitor_rate_limited', __( 'Too many score requests. Try again shortly.', 'codewp-shield-monitor' ), array( 'status' => 429 ) );
		}
		$cached = get_transient( 'shield_monitor_public_score' );
		if ( is_array( $cached ) ) {
			$response = rest_ensure_response( $cached );
			return $this->public_response( $response );
		}
		$score    = absint( $this->status->public_score() );
		$payload  = array(
			'api_version'      => 1,
			'schema_version'   => '1.0',
			'generated_at'     => gmdate( 'c' ),
			'score'            => $score,
			'status'           => $this->score_status( $score ),
		);
		set_transient( 'shield_monitor_public_score', $payload, 5 * MINUTE_IN_SECONDS );
		$response = rest_ensure_response( $payload );
		return $this->public_response( $response );
	}

	public function public_scan() {
		if ( ! $this->allow_public_score_request() ) {
			return new WP_Error( 'shield_monitor_rate_limited', __( 'Too many score requests. Try again shortly.', 'codewp-shield-monitor' ), array( 'status' => 429 ) );
		}
		$cached = get_transient( 'shield_monitor_public_scan' );
		if ( is_array( $cached ) ) {
			$response = rest_ensure_response( $cached );
			return $this->public_response( $response );
		}
		$score   = absint( $this->status->public_score() );
		$payload = array(
			'api_version'    => 1,
			'schema_version' => '1.0',
			'generated_at'   => gmdate( 'c' ),
			'score'          => $score,
			'status'         => $this->score_status( $score ),
			'scan_type'      => 'public_summary',
			'scan_started'   => false,
			'warnings'       => $this->public_scan_warnings(),
		);
		set_transient( 'shield_monitor_public_scan', $payload, 5 * MINUTE_IN_SECONDS );
		$response = rest_ensure_response( $payload );
		return $this->public_response( $response );
	}

	private function allow_public_score_request() {
		$ip    = $this->current_ip();
		$key   = 'shield_monitor_public_score_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$count = absint( get_transient( $key ) );
		if ( $count >= 60 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public function events( WP_REST_Request $request ) {
		$limit       = absint( $request->get_param( 'limit' ) );
		$has_cursor  = null !== $request->get_param( 'after_id' );
		$after_id    = absint( $request->get_param( 'after_id' ) );
		$rows        = $has_cursor ? $this->logger->after_id( $after_id, $limit + 1 ) : $this->logger->recent( $limit );
		$has_more    = $has_cursor && count( $rows ) > $limit;
		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		$data = array();
		$next_cursor = $after_id;
		foreach ( $rows as $row ) {
			$data[] = $this->logger->event_payload( $row );
			$next_cursor = max( $next_cursor, absint( $row->id ) );
		}
		$response = rest_ensure_response(
			array(
				'api_version' => 1,
				'schema_version' => '1.8',
				'generated_at' => gmdate( 'c' ),
				'events'      => $data,
				'activity'    => $this->logger->recent_activity_groups( 30 ),
				'next_cursor' => $next_cursor,
				'latest_id'   => $this->logger->latest_id(),
				'has_more'    => $has_more,
			)
		);
		return $this->private_response( $response );
	}

	private function score_status( $score ) {
		if ( $score >= 85 ) {
			return 'protected';
		}
		if ( $score >= 65 ) {
			return 'needs_review';
		}
		return 'at_risk';
	}

	private function public_scan_warnings() {
		$warnings = array();

		if ( ! is_ssl() ) {
			$warnings[] = $this->public_warning( 'https_not_enabled', 'critical', 'hardening', __( 'HTTPS is not active.', 'codewp-shield-monitor' ), __( 'Install a valid SSL certificate and redirect HTTP traffic to HTTPS.', 'codewp-shield-monitor' ) );
		}
		if ( ! $this->settings->get( 'login_protection' ) ) {
			$warnings[] = $this->public_warning( 'login_protection_disabled', 'high', 'login', __( 'Login protection is disabled.', 'codewp-shield-monitor' ), __( 'Enable failed-login lockouts to slow brute-force attempts.', 'codewp-shield-monitor' ) );
		}
		if ( ! $this->settings->get( 'file_monitoring' ) ) {
			$warnings[] = $this->public_warning( 'file_monitoring_disabled', 'high', 'hardening', __( 'File integrity monitoring is disabled.', 'codewp-shield-monitor' ), __( 'Enable important-file monitoring to detect unexpected file changes.', 'codewp-shield-monitor' ) );
		}
		if ( ! $this->settings->get( 'malware_scanning' ) ) {
			$warnings[] = $this->public_warning( 'malware_scan_disabled', 'high', 'hardening', __( 'Suspicious code scanning is disabled.', 'codewp-shield-monitor' ), __( 'Enable lightweight malware and database scans.', 'codewp-shield-monitor' ) );
		}

		$core_status = get_option( Shield_Monitor_File_Monitor::CORE_OPTION, array() );
		if ( is_array( $core_status ) && 'mismatch' === ( $core_status['status'] ?? '' ) ) {
			$warnings[] = $this->public_warning( 'core_checksum_mismatch', 'critical', 'files', __( 'WordPress core checksum mismatch.', 'codewp-shield-monitor' ), __( 'Review modified core files and restore clean WordPress core files if needed.', 'codewp-shield-monitor' ) );
		}

		$report          = get_option( Shield_Monitor_Malware_Scanner::REPORT_OPTION, array() );
		$malware_summary = is_array( $report ) && isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		if ( ! empty( $malware_summary['critical'] ) || ! empty( $malware_summary['high'] ) ) {
			$warnings[] = $this->public_warning( 'review_malware_findings', 'critical', 'malware', __( 'Review suspicious scan findings.', 'codewp-shield-monitor' ), __( 'High or critical suspicious items were found by the scanner.', 'codewp-shield-monitor' ) );
		}

		foreach ( array(
			'block_enumeration' => array( 'user_enumeration_allowed', 'medium', 'hardening', __( 'Public user enumeration is not blocked.', 'codewp-shield-monitor' ), __( 'Restrict public author and REST user discovery.', 'codewp-shield-monitor' ) ),
			'security_headers' => array( 'security_headers_disabled', 'medium', 'hardening', __( 'Security headers are disabled.', 'codewp-shield-monitor' ), __( 'Enable conservative browser protection headers.', 'codewp-shield-monitor' ) ),
			'disable_file_editor' => array( 'dashboard_file_editor_enabled', 'medium', 'hardening', __( 'Dashboard file editor is enabled.', 'codewp-shield-monitor' ), __( 'Disable plugin/theme code editing from wp-admin.', 'codewp-shield-monitor' ) ),
			'email_alerts' => array( 'email_alerts_disabled', 'low', 'notifications', __( 'Security email alerts are disabled.', 'codewp-shield-monitor' ), __( 'Enable alerts for admin logins, lockouts, file changes, and suspicious findings.', 'codewp-shield-monitor' ) ),
		) as $setting => $warning ) {
			if ( ! $this->settings->get( $setting ) ) {
				$warnings[] = $this->public_warning( $warning[0], $warning[1], $warning[2], $warning[3], $warning[4] );
			}
		}

		usort(
			$warnings,
			function ( $a, $b ) {
				return $this->severity_weight( $b['severity'] ) <=> $this->severity_weight( $a['severity'] );
			}
		);

		return array_slice( $warnings, 0, 5 );
	}

	private function public_warning( $id, $severity, $category, $title, $description ) {
		return array(
			'id'          => sanitize_key( $id ),
			'severity'    => $this->normalize_severity( $severity ),
			'category'    => sanitize_key( $category ),
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
		);
	}

	private function normalize_severity( $severity ) {
		$severity = sanitize_key( $severity );
		return in_array( $severity, array( 'critical', 'high', 'medium', 'low' ), true ) ? $severity : 'medium';
	}

	private function severity_weight( $severity ) {
		$weights = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		return $weights[ $this->normalize_severity( $severity ) ] ?? 0;
	}

	private function incomplete_tasks( $data ) {
		$security     = isset( $data['security'] ) && is_array( $data['security'] ) ? $data['security'] : array();
		$updates      = isset( $data['updates'] ) && is_array( $data['updates'] ) ? $data['updates'] : array();
		$integrations = isset( $data['integrations'] ) && is_array( $data['integrations'] ) ? $data['integrations'] : array();
		$tasks        = array();
		$update_count = absint( $updates['plugins']['update_count'] ?? 0 ) + absint( $updates['themes']['update_count'] ?? 0 ) + ( ! empty( $updates['core']['update_available'] ) ? 1 : 0 );

		if ( empty( $security['https'] ) ) {
			$tasks[] = $this->task( 'enable_https', __( 'Use HTTPS everywhere', 'codewp-shield-monitor' ), __( 'Install a valid SSL certificate and redirect HTTP traffic to HTTPS.', 'codewp-shield-monitor' ), 'critical' );
		}
		if ( $update_count ) {
			$tasks[] = $this->task( 'update_software', __( 'Keep WordPress software updated', 'codewp-shield-monitor' ), sprintf( __( '%d WordPress, plugin, or theme updates are pending.', 'codewp-shield-monitor' ), $update_count ), 'high' );
		}
		if ( empty( $security['login_protection'] ) ) {
			$tasks[] = $this->task( 'enable_login_protection', __( 'Protect the login form', 'codewp-shield-monitor' ), __( 'Limit repeated login attempts.', 'codewp-shield-monitor' ), 'high' );
		}
		if ( empty( $security['file_integrity']['enabled'] ) ) {
			$tasks[] = $this->task( 'enable_file_monitoring', __( 'Monitor important files', 'codewp-shield-monitor' ), __( 'Detect unexpected edits, additions, and deletions.', 'codewp-shield-monitor' ), 'high' );
		}
		if ( empty( $security['malware_scan']['enabled'] ) ) {
			$tasks[] = $this->task( 'enable_malware_scan', __( 'Enable suspicious code scanning', 'codewp-shield-monitor' ), __( 'Run lightweight heuristic scans for suspicious files and database records.', 'codewp-shield-monitor' ), 'high' );
		}
		if ( ! empty( $security['malware_scan']['summary']['critical'] ) || ! empty( $security['malware_scan']['summary']['high'] ) ) {
			$tasks[] = $this->task( 'review_malware_findings', __( 'Review suspicious scan findings', 'codewp-shield-monitor' ), __( 'High or critical suspicious items were found by the scanner.', 'codewp-shield-monitor' ), 'critical' );
		}
		if ( empty( $security['block_enumeration'] ) ) {
			$tasks[] = $this->task( 'block_user_enumeration', __( 'Block user enumeration', 'codewp-shield-monitor' ), __( 'Restrict public author and REST user discovery.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $security['security_headers'] ) ) {
			$tasks[] = $this->task( 'enable_security_headers', __( 'Enable security headers', 'codewp-shield-monitor' ), __( 'Add safe browser protection headers.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $security['file_editor_disabled'] ) ) {
			$tasks[] = $this->task( 'disable_file_editor', __( 'Disable dashboard file editor', 'codewp-shield-monitor' ), __( 'Prevent code editing from the WordPress dashboard.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $security['file_integrity']['core_checksum']['status'] ) || 'mismatch' === ( $security['file_integrity']['core_checksum']['status'] ?? '' ) ) {
			$tasks[] = $this->task( 'review_core_checksums', __( 'Review WordPress core checksums', 'codewp-shield-monitor' ), __( 'Core checksum verification has not passed cleanly.', 'codewp-shield-monitor' ), 'high' );
		}
		if ( empty( $integrations['two_factor_authentication']['detected'] ) ) {
			$tasks[] = $this->task( 'enable_2fa', __( 'Enable two-factor authentication', 'codewp-shield-monitor' ), __( 'Require a second authentication factor for administrator accounts.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $integrations['backup']['detected'] ) ) {
			$tasks[] = $this->task( 'install_backup', __( 'Install a backup solution', 'codewp-shield-monitor' ), __( 'Keep automatic off-site backups and test restoration.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $integrations['cache']['detected'] ) ) {
			$tasks[] = $this->task( 'install_cache', __( 'Install a cache plugin', 'codewp-shield-monitor' ), __( 'Reduce server load during traffic spikes and automated attacks.', 'codewp-shield-monitor' ), 'low' );
		}
		if ( ! empty( $integrations['contact_form_7']['detected'] ) && empty( $integrations['captcha']['detected'] ) ) {
			$tasks[] = $this->task( 'configure_contact_form_captcha', __( 'Protect Contact Form 7 with CAPTCHA', 'codewp-shield-monitor' ), __( 'Configure reCAPTCHA, hCaptcha, Turnstile, or another anti-spam challenge.', 'codewp-shield-monitor' ), 'medium' );
		}
		if ( empty( $security['xmlrpc_disabled'] ) ) {
			$tasks[] = $this->task( 'disable_xmlrpc', __( 'Disable XML-RPC when unused', 'codewp-shield-monitor' ), __( 'Turn off XML-RPC if the site does not use Jetpack, mobile apps, or remote publishing.', 'codewp-shield-monitor' ), 'low' );
		}
		if ( empty( $security['heartbeat_limited'] ) ) {
			$tasks[] = $this->task( 'limit_heartbeat_xhr', __( 'Limit WordPress Heartbeat XHR', 'codewp-shield-monitor' ), __( 'Reduce background admin-ajax polling to lower server load.', 'codewp-shield-monitor' ), 'low' );
		}
		if ( empty( $security['rest_api_restricted'] ) ) {
			$tasks[] = $this->task( 'restrict_public_rest_api', __( 'Restrict public REST API if possible', 'codewp-shield-monitor' ), __( 'Block anonymous REST API access when the site does not need public REST endpoints.', 'codewp-shield-monitor' ), 'low' );
		}
		if ( empty( $security['email_alerts'] ) ) {
			$tasks[] = $this->task( 'enable_email_alerts', __( 'Enable security email alerts', 'codewp-shield-monitor' ), __( 'Receive alerts for administrator logins, blocked attacks, file changes, and suspicious scan findings.', 'codewp-shield-monitor' ), 'low' );
		}

		return $tasks;
	}

	private function task( $id, $title, $description, $severity ) {
		return array(
			'id'          => sanitize_key( $id ),
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'severity'    => sanitize_key( $severity ),
		);
	}

	public function rotate_token() {
		$id     = $this->authenticated_token_id;
		$tokens = $this->tokens();
		if ( ! $id || empty( $tokens[ $id ] ) ) {
			return new WP_Error( 'shield_monitor_unauthorized', __( 'A valid CodeWP Shield Monitor API token is required.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
		}
		$token                    = $this->base64url( random_bytes( 32 ) );
		$tokens[ $id ]['hash']    = $this->token_hash( $token );
		$tokens[ $id ]['rotated_at'] = gmdate( 'c' );
		$tokens[ $id ]['expires_at'] = gmdate( 'c', time() + self::TOKEN_TTL );
		$tokens[ $id ]['scopes']  = $this->normalize_scopes( ! empty( $tokens[ $id ]['scopes'] ) ? $tokens[ $id ]['scopes'] : array() );
		update_option( self::TOKENS_OPTION, $tokens, false );
		$this->logger->record( 'token_rotated', array( 'token_id' => $id ) );
		$response = rest_ensure_response( array( 'token' => $token, 'token_type' => 'Bearer', 'expires_at' => $tokens[ $id ]['expires_at'], 'scopes' => $tokens[ $id ]['scopes'] ) );
		return $this->private_response( $response );
	}

	public function revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_revoke_token' );
		$id     = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$tokens = $this->tokens();
		if ( isset( $tokens[ $id ] ) ) {
			unset( $tokens[ $id ] );
			update_option( self::TOKENS_OPTION, $tokens, false );
			$this->logger->record( 'app_revoked', array( 'token_id' => $id ), get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#connect' ) );
		exit;
	}

	public function toggle_token_scope() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage API tokens.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_toggle_token_scope' );
		$id      = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$scope   = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : '';
		$enabled = ! empty( $_POST['enabled'] );
		$tokens  = $this->tokens();
		if ( 'quick-login:create' === $scope && isset( $tokens[ $id ] ) ) {
			$scopes = $this->normalize_scopes( ! empty( $tokens[ $id ]['scopes'] ) ? $tokens[ $id ]['scopes'] : array() );
			if ( $enabled && ! in_array( $scope, $scopes, true ) ) {
				$scopes[] = $scope;
			}
			if ( ! $enabled ) {
				$scopes = array_values( array_diff( $scopes, array( $scope ) ) );
			}
			$tokens[ $id ]['scopes'] = $scopes;
			update_option( self::TOKENS_OPTION, $tokens, false );
			$this->logger->record( 'token_scope_changed', array( 'token_id' => $id, 'scope' => $scope, 'enabled' => $enabled ? 'yes' : 'no' ), get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#connect' ) );
		exit;
	}

	public function tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	public function cleanup_expired_tokens() {
		$tokens  = $this->tokens();
		$changed = false;
		foreach ( $tokens as $id => $token ) {
			$expires = ! empty( $token['expires_at'] ) ? strtotime( $token['expires_at'] ) : 0;
			if ( $expires && $expires < time() - ( 30 * DAY_IN_SECONDS ) ) {
				unset( $tokens[ $id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::TOKENS_OPTION, $tokens, false );
		}
	}

	private function touch_token( $id, $tokens ) {
		$last = isset( $tokens[ $id ]['last_used'] ) ? strtotime( $tokens[ $id ]['last_used'] ) : 0;
		if ( ! $last || time() - $last > HOUR_IN_SECONDS ) {
			$tokens[ $id ]['last_used'] = gmdate( 'c' );
			if ( empty( $tokens[ $id ]['expires_at'] ) ) {
				$tokens[ $id ]['expires_at'] = gmdate( 'c', time() + ( 90 * DAY_IN_SECONDS ) );
			}
			if ( empty( $tokens[ $id ]['scopes'] ) ) {
				$tokens[ $id ]['scopes'] = $this->default_scopes();
			} else {
				$tokens[ $id ]['scopes'] = $this->normalize_scopes( $tokens[ $id ]['scopes'] );
			}
			update_option( self::TOKENS_OPTION, $tokens, false );
		}
	}

	private function allow_api_request( $id ) {
		$key   = 'shield_monitor_api_rate_' . md5( (string) $id );
		$count = absint( get_transient( $key ) );
		if ( $count >= 120 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function allow_quick_login_request( $id ) {
		$key   = 'shield_monitor_quick_login_rate_' . md5( (string) $id );
		$count = absint( get_transient( $key ) );
		if ( $count >= 5 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function quick_login_key( $token ) {
		return 'shield_monitor_quick_login_' . hash_hmac( 'sha256', (string) $token, wp_salt( 'secure_auth' ) );
	}

	private function current_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && ! empty( $_SERVER['HTTP_CF_RAY'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}
		return sanitize_text_field( apply_filters( 'shield_monitor_request_ip', $ip ) );
	}

	private function default_scopes() {
		return array( 'status:read', 'events:read', 'token:rotate' );
	}

	private function normalize_scopes( $scopes ) {
		return array_values( array_unique( array_merge( $this->default_scopes(), array_map( 'sanitize_text_field', (array) $scopes ) ) ) );
	}

	private function token_hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	private function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private function public_response( WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}

	private function private_response( WP_REST_Response $response ) {
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		return $response;
	}

	private function manual_code() {
		$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$bytes    = random_bytes( 10 );
		$code     = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$code .= $alphabet[ ord( $bytes[ $i ] ) % strlen( $alphabet ) ];
		}
		return substr( $code, 0, 5 ) . '-' . substr( $code, 5 );
	}
}
