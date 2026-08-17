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
		// Virtual patch: CVE-2026-60137 — sanitize author__not_in to prevent SQLi (always active).
		add_action( 'parse_query', array( $this, 'sanitize_author_not_in' ) );

		// Virtual patch: CVE-2026-63030 — block unauthenticated REST API batch endpoint (always active).
		add_action( 'rest_api_init', array( $this, 'block_batch_endpoint' ), 1 );

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

		// Core REST API endpoint controls (always active — each setting checked inside).
		add_filter( 'rest_endpoints', array( $this, 'filter_core_rest_endpoints' ) );

		// Invalidate namespace cache when plugins change.
		add_action( 'activated_plugin', array( $this, 'clear_rest_namespace_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_rest_namespace_cache' ) );
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
		if ( $this->settings->get( 'honeypot_enabled' ) ) {
			add_action( 'login_form', array( $this, 'honeypot_field' ) );
			add_action( 'register_form', array( $this, 'honeypot_field' ) );
			add_action( 'comment_form_after_fields', array( $this, 'honeypot_field' ) );
			add_action( 'comment_form_logged_in_after', array( $this, 'honeypot_field' ) );
			add_action( 'woocommerce_login_form', array( $this, 'honeypot_field' ) );
			add_action( 'woocommerce_register_form', array( $this, 'honeypot_field' ) );
			add_filter( 'authenticate', array( $this, 'check_honeypot_login' ), 5, 3 );
			add_filter( 'registration_errors', array( $this, 'check_honeypot_register' ), 5, 3 );
			add_filter( 'preprocess_comment', array( $this, 'check_honeypot_comment' ), 5 );
			add_action( 'woocommerce_register_post', array( $this, 'check_honeypot_woo_register' ), 5, 3 );
		}
		if ( $this->settings->get( 'block_php_uploads' ) ) {
			add_action( 'admin_init', array( $this, 'protect_uploads_directory' ) );
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'block_dangerous_uploads' ) );
		}
	}

	// ── wp2shell Virtual Patches (always active) ────────────────────────

	/**
	 * CVE-2026-60137: author__not_in passed as scalar string causes SQL injection in WP_Query.
	 * Fix: coerce to array of integers, which is the documented API contract.
	 */
	public function sanitize_author_not_in( $query ) {
		if ( ! isset( $query->query_vars['author__not_in'] ) ) {
			return;
		}
		$value = $query->query_vars['author__not_in'];
		if ( ! is_array( $value ) ) {
			$this->logger->record( 'wp2shell_blocked', array(
				'vector' => 'author__not_in_sqli',
				'value'  => sanitize_text_field( substr( (string) $value, 0, 200 ) ),
			) );
			$value = array( $value );
		}
		$query->query_vars['author__not_in'] = array_map( 'absint', $value );
	}

	/**
	 * CVE-2026-63030: REST API batch-route confusion allows unauthenticated RCE.
	 * Fix: block batch endpoint for unauthenticated requests.
	 * Safe because batch API is only used by Gutenberg (requires login).
	 */
	public function block_batch_endpoint() {
		if ( is_user_logged_in() ) {
			return;
		}

		$route = $this->current_rest_route();

		if ( preg_match( '#/batch/v\d#i', $route ) ) {
			$this->logger->record( 'wp2shell_blocked', array(
				'vector' => 'batch_route_confusion',
				'route'  => sanitize_text_field( $route ),
			) );
			status_header( 403 );
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json; charset=utf-8' );
			}
			echo wp_json_encode( array(
				'code'    => 'shield_batch_blocked',
				'message' => 'Unauthenticated batch API access is blocked for security.',
				'data'    => array( 'status' => 403 ),
			) );
			exit;
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

		// Always allow ShieldPress own endpoints and oEmbed.
		if ( 0 === strpos( $route, '/' . CODEWP_SHIELD_MONITOR_API_NAMESPACE . '/' ) ) {
			return $result;
		}
		if ( 0 === strpos( $route, '/oembed/' ) ) {
			return $result;
		}

		// Allow user-configured plugin namespaces.
		$allowed = $this->settings->get( 'rest_api_public_namespaces' );
		if ( is_array( $allowed ) ) {
			foreach ( $allowed as $ns ) {
				if ( 0 === strpos( $route, '/' . $ns . '/' ) || '/' . $ns === $route ) {
					return $result;
				}
			}
		}

		return new WP_Error( 'shield_monitor_rest_restricted', __( 'Public REST API access is restricted by CodeWP Shield Monitor.', 'codewp-shield-monitor' ), array( 'status' => 401 ) );
	}

	// ── Core REST API endpoint controls ─────────────────────────────

	/**
	 * Remove dangerous WP core REST endpoints for non-admin users.
	 * Each control is independent — works regardless of the master restrict_rest_api toggle.
	 */
	public function filter_core_rest_endpoints( $endpoints ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $endpoints;
		}

		$blocks = array(
			'rest_api_block_user_creation'     => array( '/wp/v2/users' ),
			'rest_api_block_post_creation'     => array( '/wp/v2/posts', '/wp/v2/pages' ),
			'rest_api_block_media_upload'      => array( '/wp/v2/media' ),
			'rest_api_block_settings_access'   => array( '/wp/v2/settings' ),
			'rest_api_block_export'            => array( '/wp/v2/export' ),
		);

		foreach ( $blocks as $setting => $routes ) {
			if ( ! $this->settings->get( $setting ) ) {
				continue;
			}
			foreach ( array_keys( $endpoints ) as $endpoint ) {
				foreach ( $routes as $route ) {
					if ( $endpoint === $route || 0 === strpos( $endpoint, $route . '/' ) || 0 === strpos( $endpoint, $route . '/(?P' ) ) {
						$this->remove_methods_from_endpoint( $endpoints, $endpoint, array( 'POST', 'PUT', 'PATCH', 'DELETE' ) );
					}
				}
			}
		}

		// Block plugin management entirely (GET + write).
		if ( $this->settings->get( 'rest_api_block_plugin_management' ) ) {
			foreach ( array_keys( $endpoints ) as $endpoint ) {
				if ( 0 === strpos( $endpoint, '/wp/v2/plugins' ) ) {
					unset( $endpoints[ $endpoint ] );
				}
			}
		}

		// Block application passwords.
		if ( $this->settings->get( 'rest_api_block_app_passwords' ) ) {
			foreach ( array_keys( $endpoints ) as $endpoint ) {
				if ( false !== strpos( $endpoint, 'application-passwords' ) ) {
					unset( $endpoints[ $endpoint ] );
				}
			}
		}

		return $endpoints;
	}

	/**
	 * Remove specific HTTP methods from an endpoint, keeping read-only (GET) access.
	 */
	private function remove_methods_from_endpoint( &$endpoints, $endpoint, $methods_to_remove ) {
		if ( ! isset( $endpoints[ $endpoint ] ) || ! is_array( $endpoints[ $endpoint ] ) ) {
			return;
		}
		foreach ( $endpoints[ $endpoint ] as $index => $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
				continue;
			}
			$handler_methods = is_array( $handler['methods'] ) ? $handler['methods'] : array( $handler['methods'] => true );
			foreach ( $methods_to_remove as $method ) {
				unset( $handler_methods[ $method ] );
			}
			if ( empty( $handler_methods ) ) {
				unset( $endpoints[ $endpoint ][ $index ] );
			} else {
				$endpoints[ $endpoint ][ $index ]['methods'] = $handler_methods;
			}
		}
		if ( empty( $endpoints[ $endpoint ] ) ) {
			unset( $endpoints[ $endpoint ] );
		}
	}

	public function clear_rest_namespace_cache() {
		delete_transient( 'shield_monitor_rest_ns_map' );
	}

	private function current_rest_route() {
		$route = '';
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// wp2shell bypass: attacker can send rest_route in POST body to evade URL-only checks.
		if ( ! $route && isset( $_POST['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$route = sanitize_text_field( wp_unslash( $_POST['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

	// ── Honeypot ─────────────────────────────────────────────────────────

	public function honeypot_field() {
		// Field hidden via inline CSS. Bots fill it; humans never see it.
		echo '<div style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;" aria-hidden="true">';
		echo '<label for="shield_monitor_hp_field">' . esc_html__( 'Leave this field empty', 'codewp-shield-monitor' ) . '</label>';
		echo '<input type="text" name="shield_monitor_hp_field" id="shield_monitor_hp_field" value="" tabindex="-1" autocomplete="off">';
		echo '</div>';
	}

	public function check_honeypot_login( $user, $username, $password ) {
		if ( $this->honeypot_triggered() ) {
			$this->logger->record( 'honeypot_blocked', array( 'context' => 'login' ) );
			return new WP_Error( 'shield_monitor_honeypot', __( 'Bot detected. Access denied.', 'codewp-shield-monitor' ) );
		}
		return $user;
	}

	public function check_honeypot_register( $errors, $sanitized_user_login, $user_email ) {
		if ( $this->honeypot_triggered() ) {
			$this->logger->record( 'honeypot_blocked', array( 'context' => 'register' ) );
			$errors->add( 'shield_monitor_honeypot', __( 'Bot detected. Registration blocked.', 'codewp-shield-monitor' ) );
		}
		return $errors;
	}

	public function check_honeypot_comment( $commentdata ) {
		if ( $this->honeypot_triggered() ) {
			$this->logger->record( 'honeypot_blocked', array( 'context' => 'comment' ) );
			wp_die( esc_html__( 'Bot detected. Comment blocked.', 'codewp-shield-monitor' ), esc_html__( 'Spam Blocked', 'codewp-shield-monitor' ), array( 'back_link' => true, 'response' => 403 ) );
		}
		return $commentdata;
	}

	public function check_honeypot_woo_register( $username, $email, $validation_errors ) {
		if ( $this->honeypot_triggered() ) {
			$this->logger->record( 'honeypot_blocked', array( 'context' => 'woo_register' ) );
			$validation_errors->add( 'shield_monitor_honeypot', __( 'Bot detected. Registration blocked.', 'codewp-shield-monitor' ) );
		}
	}

	private function honeypot_triggered() {
		return ! empty( $_POST['shield_monitor_hp_field'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	// ── Block PHP in uploads ─────────────────────────────────────────────

	public function protect_uploads_directory() {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] ?? '';
		if ( ! $base_dir || ! is_dir( $base_dir ) ) {
			return;
		}

		$htaccess = trailingslashit( $base_dir ) . '.htaccess';
		$marker   = '# BEGIN ShieldPress Block PHP';
		$rules    = $marker . "\n"
			. "<Files *.php>\n"
			. "  <IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "  </IfModule>\n"
			. "  <IfModule !mod_authz_core.c>\n"
			. "    Order deny,allow\n"
			. "    Deny from all\n"
			. "  </IfModule>\n"
			. "</Files>\n"
			. "<Files *.phtml>\n"
			. "  <IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "  </IfModule>\n"
			. "  <IfModule !mod_authz_core.c>\n"
			. "    Order deny,allow\n"
			. "    Deny from all\n"
			. "  </IfModule>\n"
			. "</Files>\n"
			. "# END ShieldPress Block PHP";

		if ( file_exists( $htaccess ) ) {
			$contents = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== strpos( $contents, $marker ) ) {
				return; // Already protected.
			}
			$rules = $contents . "\n\n" . $rules;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $htaccess, $rules ) ) {
			$this->logger->record( 'hardening_error', array( 'message' => 'Failed to write uploads .htaccess' ) );
		}
	}

	public function block_dangerous_uploads( $file ) {
		$dangerous = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'bash' );
		$ext       = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $dangerous, true ) ) {
			$file['error'] = __( 'This file type is not allowed for security reasons.', 'codewp-shield-monitor' );
			$this->logger->record( 'dangerous_upload_blocked', array( 'filename' => sanitize_file_name( $file['name'] ) ) );
		}
		return $file;
	}
}
