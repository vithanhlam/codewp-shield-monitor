<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Login_Protection {
	const REGISTRY_OPTION = 'shield_monitor_login_attempt_registry';

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		if ( $this->settings->get( 'hide_login' ) ) {
			add_action( 'init', array( $this, 'block_hidden_admin_paths' ), 0 );
			add_action( 'init', array( $this, 'handle_custom_login_path' ), 1 );
			add_action( 'login_init', array( $this, 'block_default_login_path' ), 1 );
			add_action( 'admin_init', array( $this, 'block_anonymous_admin_path' ), 1 );
			add_filter( 'login_url', array( $this, 'login_url' ), 10, 3 );
			add_filter( 'site_url', array( $this, 'site_url' ), 10, 4 );
		}
		add_action( 'admin_post_shield_monitor_login_block_ip', array( $this, 'admin_block_ip' ) );
		add_action( 'admin_post_shield_monitor_login_unlock_ip', array( $this, 'admin_unlock_ip' ) );
		add_action( 'wp_login_failed', array( $this, 'failed' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'clear' ), 5, 2 );

		if ( ! $this->settings->get( 'login_protection' ) ) {
			return;
		}
		add_filter( 'authenticate', array( $this, 'check_lockout' ), 5, 3 );
	}

	public function block_hidden_admin_paths() {
		if ( is_user_logged_in() || wp_doing_ajax() || $this->is_allowed_admin_endpoint() ) {
			return;
		}
		$request_path = $this->request_path();
		if ( 'admin' === $request_path || 'wp-admin' === $request_path || 0 === strpos( $request_path, 'wp-admin/' ) ) {
			$this->login_not_found();
		}
	}

	private function key( $ip = null ) {
		$ip = null === $ip ? $this->current_ip() : $ip;
		return 'shield_monitor_login_' . $this->ip_hash( $ip );
	}

	private function ip_hash( $ip ) {
		return hash_hmac( 'sha256', (string) $ip, wp_salt( 'auth' ) );
	}

	private function current_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	public function check_lockout( $user, $username, $password ) {
		$data = get_transient( $this->key() );
		if ( is_array( $data ) && ! empty( $data['locked_until'] ) && time() < absint( $data['locked_until'] ) ) {
			$minutes = max( 1, (int) ceil( ( absint( $data['locked_until'] ) - time() ) / MINUTE_IN_SECONDS ) );
			return new WP_Error( 'shield_monitor_locked', sprintf( _n( 'Too many login attempts. Try again in %d minute.', 'Too many login attempts. Try again in %d minutes.', $minutes, 'codewp-shield-monitor' ), $minutes ) );
		}
		return $user;
	}

	public function failed( $username, $error ) {
		if ( ! $this->settings->get( 'login_protection' ) ) {
			$this->logger->record( 'login_failed', array( 'username' => sanitize_user( (string) $username, true ) ) );
			return;
		}
		$ip       = $this->current_ip();
		$key      = $this->key( $ip );
		$data     = get_transient( $key );
		$data     = is_array( $data ) ? $data : array( 'attempts' => 0, 'locked_until' => 0 );
		$attempts = absint( $data['attempts'] ) + 1;
		$max      = absint( $this->settings->get( 'max_attempts' ) );
		$minutes  = absint( $this->settings->get( 'lockout_minutes' ) );
		$locked   = $attempts >= $max ? time() + ( $minutes * MINUTE_IN_SECONDS ) : 0;
		set_transient( $key, array( 'attempts' => $attempts, 'locked_until' => $locked ), max( HOUR_IN_SECONDS, $minutes * MINUTE_IN_SECONDS ) );
		$this->update_registry( $ip, $attempts, $locked );
		$this->logger->record( $locked ? 'login_lockout' : 'login_failed', array( 'attempt' => $attempts ) );
		if ( $locked ) {
			do_action( 'shield_monitor_login_lockout', $attempts );
		}
	}

	public function clear( $user_login, $user ) {
		$ip = $this->current_ip();
		delete_transient( $this->key( $ip ) );
		$this->clear_registry_lock( $ip );
	}

	public function handle_custom_login_path() {
		if ( is_user_logged_in() ) {
			return;
		}
		$request_path = $this->request_path();
		if ( $request_path && trim( $this->login_slug(), '/' ) === $request_path ) {
			if ( ! defined( 'SHIELD_MONITOR_CUSTOM_LOGIN_REQUEST' ) ) {
				define( 'SHIELD_MONITOR_CUSTOM_LOGIN_REQUEST', true );
			}
			$query = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY );
			$_SERVER['REQUEST_URI'] = '/wp-login.php' . ( $query ? '?' . $query : '' );
			require_once ABSPATH . 'wp-login.php';
			exit;
		}
	}

	public function block_default_login_path() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_user_logged_in() || in_array( $action, array( 'logout', 'postpass' ), true ) || $this->is_custom_login_request() ) {
			return;
		}
		$this->login_not_found();
	}

	public function block_anonymous_admin_path() {
		if ( is_user_logged_in() || wp_doing_ajax() || $this->is_allowed_admin_endpoint() ) {
			return;
		}
		$this->login_not_found();
	}

	public function login_url( $login_url, $redirect = '', $force_reauth = false ) {
		$url = home_url( '/' . $this->login_slug() . '/' );
		if ( $redirect ) {
			$url = add_query_arg( 'redirect_to', $redirect, $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	public function site_url( $url, $path, $scheme, $blog_id ) {
		$path = ltrim( (string) $path, '/' );
		if ( 0 === strpos( $path, 'wp-login.php' ) ) {
			$query = wp_parse_url( $path, PHP_URL_QUERY );
			return home_url( '/' . $this->login_slug() . '/' ) . ( $query ? '?' . $query : '' );
		}
		return $url;
	}

	private function is_custom_login_request() {
		if ( defined( 'SHIELD_MONITOR_CUSTOM_LOGIN_REQUEST' ) && SHIELD_MONITOR_CUSTOM_LOGIN_REQUEST ) {
			return true;
		}
		return trim( $this->login_slug(), '/' ) === $this->request_path();
	}

	private function request_path() {
		return trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	}

	private function is_allowed_admin_endpoint() {
		$allowed_paths = array_filter(
			array_map(
				array( $this, 'url_path' ),
				array(
					admin_url( 'admin-ajax.php' ),
					admin_url( 'admin-post.php' ),
				)
			)
		);

		return in_array( $this->request_path(), $allowed_paths, true );
	}

	private function url_path( $url ) {
		return trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	}

	private function login_slug() {
		$slug = sanitize_title( $this->settings->get( 'login_slug' ) ?: 'codewp-shield-monitor-login' );
		return $slug ?: 'codewp-shield-monitor-login';
	}

	private function login_not_found() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Not found.', 'codewp-shield-monitor' ), esc_html__( 'Not found.', 'codewp-shield-monitor' ), array( 'response' => 404 ) );
	}

	private function registry() {
		$items = get_option( self::REGISTRY_OPTION, array() );
		return is_array( $items ) ? $items : array();
	}

	public function lockout_rows() {
		$items = $this->registry();
		$rows  = array();
		foreach ( $items as $hash => $item ) {
			$locked_until = absint( $item['locked_until'] ?? 0 );
			$rows[] = array(
				'hash'            => sanitize_text_field( $hash ),
				'ip'              => sanitize_text_field( $item['ip'] ?? '' ),
				'attempts'        => absint( $item['attempts'] ?? 0 ),
				'locked'          => $locked_until > time(),
				'locked_until'    => $locked_until ? gmdate( 'Y-m-d H:i:s', $locked_until ) : null,
				'last_failed_at'  => sanitize_text_field( $item['last_failed_at'] ?? '' ),
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $b['last_failed_at'], (string) $a['last_failed_at'] );
			}
		);
		return array_slice( $rows, 0, 50 );
	}

	private function update_registry( $ip, $attempts, $locked_until ) {
		$items = $this->registry();
		$hash  = $this->ip_hash( $ip );
		$items[ $hash ] = array(
			'ip'             => sanitize_text_field( $ip ),
			'attempts'       => absint( $attempts ),
			'locked_until'   => absint( $locked_until ),
			'last_failed_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::REGISTRY_OPTION, array_slice( $items, -100, null, true ), false );
	}

	private function clear_registry_lock( $ip ) {
		$items = $this->registry();
		$hash  = $this->ip_hash( $ip );
		if ( isset( $items[ $hash ] ) ) {
			$items[ $hash ]['attempts']     = 0;
			$items[ $hash ]['locked_until'] = 0;
			update_option( self::REGISTRY_OPTION, $items, false );
		}
	}

	public function admin_block_ip() {
		$this->verify_ip_action( 'shield_monitor_login_block_ip' );
		$hash  = sanitize_text_field( wp_unslash( $_POST['ip_hash'] ?? '' ) );
		$items = $this->registry();
		if ( isset( $items[ $hash ] ) ) {
			$minutes = absint( $this->settings->get( 'lockout_minutes' ) );
			$until   = time() + ( $minutes * MINUTE_IN_SECONDS );
			$items[ $hash ]['locked_until'] = $until;
			set_transient( 'shield_monitor_login_' . $hash, array( 'attempts' => max( 1, absint( $items[ $hash ]['attempts'] ?? 1 ) ), 'locked_until' => $until ), max( HOUR_IN_SECONDS, $minutes * MINUTE_IN_SECONDS ) );
			update_option( self::REGISTRY_OPTION, $items, false );
			$this->logger->record( 'login_ip_blocked', array( 'ip' => sanitize_text_field( $items[ $hash ]['ip'] ?? '' ), 'source' => 'manual' ), get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#login-access' ) );
		exit;
	}

	public function admin_unlock_ip() {
		$this->verify_ip_action( 'shield_monitor_login_unlock_ip' );
		$hash  = sanitize_text_field( wp_unslash( $_POST['ip_hash'] ?? '' ) );
		$items = $this->registry();
		if ( isset( $items[ $hash ] ) ) {
			delete_transient( 'shield_monitor_login_' . $hash );
			$items[ $hash ]['attempts']     = 0;
			$items[ $hash ]['locked_until'] = 0;
			update_option( self::REGISTRY_OPTION, $items, false );
			$this->logger->record( 'login_ip_unlocked', array( 'ip' => sanitize_text_field( $items[ $hash ]['ip'] ?? '' ), 'source' => 'manual' ), get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#login-access' ) );
		exit;
	}

	private function verify_ip_action( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage login lockouts.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( $action );
	}
}
