<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Hardening {
	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		if ( $this->settings->get( 'block_enumeration' ) ) {
			add_action( 'template_redirect', array( $this, 'block_author_query' ) );
			add_filter( 'rest_endpoints', array( $this, 'restrict_user_endpoints' ) );
		}
		if ( $this->settings->get( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
		if ( $this->settings->get( 'restrict_rest_api' ) ) {
			add_filter( 'rest_authentication_errors', array( $this, 'restrict_public_rest_api' ) );
		}
		if ( $this->settings->get( 'limit_heartbeat' ) ) {
			add_filter( 'heartbeat_settings', array( $this, 'heartbeat_settings' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_frontend_heartbeat' ), 20 );
		}
		if ( $this->settings->get( 'hide_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( $this->settings->get( 'security_headers' ) ) {
			add_action( 'send_headers', array( $this, 'headers' ) );
		}
		if ( $this->settings->get( 'disable_file_editor' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
	}

	public function block_author_query() {
		if ( ! is_admin() && isset( $_GET['author'] ) && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->logger->record( 'enumeration_blocked', array( 'source' => 'author_query' ) );
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public function restrict_user_endpoints( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public function restrict_public_rest_api( $result ) {
		if ( true === $result || is_wp_error( $result ) || is_user_logged_in() ) {
			return $result;
		}
		$route = $this->current_rest_route();
		if ( 0 === strpos( $route, '/' . CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/' ) ) {
			return $result;
		}
		return new WP_Error( 'shield_monitor_rest_restricted', __( 'Public REST API access is restricted by CodeWP Shield Monitor.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
	}

	private function current_rest_route() {
		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
			$prefix      = trailingslashit( rest_get_url_prefix() );
			$position    = is_string( $path ) ? strpos( $path, '/' . $prefix ) : false;
			if ( false !== $position ) {
				$route = substr( $path, $position + strlen( $prefix ) );
			}
		}
		return '/' . ltrim( (string) $route, '/' );
	}

	public function heartbeat_settings( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	public function disable_frontend_heartbeat() {
		if ( ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	public function headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000' );
		}
	}
}
