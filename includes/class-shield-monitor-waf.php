<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_WAF {
	const LOG_OPTION = 'shield_monitor_waf_blocked_log';

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		if ( ! $this->settings->get( 'waf_enabled' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'inspect_request' ), 1 );
	}

	public function inspect_request() {
		// Skip admin users and AJAX/cron/REST internal requests from logged-in admins.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip WordPress cron.
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Collect raw input for WAF inspection — sanitize only for logging after match.
		$inputs = array(
			'uri'   => $request_uri,
			'query' => $query_string,
		);

		// Check GET params.
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$inputs[ 'get_' . sanitize_key( $key ) ] = is_array( $value ) ? wp_json_encode( $value ) : wp_unslash( $value ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		// Check POST params (only on non-admin pages to avoid blocking legitimate admin forms).
		if ( ! empty( $_POST ) && ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$inputs[ 'post_' . sanitize_key( $key ) ] = is_array( $value ) ? wp_json_encode( $value ) : wp_unslash( $value ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		// wp2shell bypass: always inspect rest_route in POST body regardless of is_admin(),
		// because WordPress resolves this parameter before the URL — attackers use POST body to evade URL-only WAF.
		if ( ! empty( $_POST['rest_route'] ) && ! isset( $inputs['post_rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$inputs['post_rest_route'] = is_string( $_POST['rest_route'] ) ? wp_unslash( $_POST['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$rules = $this->rules();
		foreach ( $inputs as $source => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( preg_match( $rule['regex'], $value ) ) {
					$this->block( $rule, $source, $value );
				}
			}
		}
	}

	private function rules() {
		$rules = array(
			// SQL Injection.
			array(
				'id'       => 'sqli_union',
				'category' => 'sqli',
				'severity' => 'critical',
				'regex'    => '/\bunion\b[\s\/\*]+\bselect\b/i',
				'message'  => 'SQL Injection: UNION SELECT',
			),
			array(
				'id'       => 'sqli_select_from',
				'category' => 'sqli',
				'severity' => 'high',
				'regex'    => '/\bselect\b.+\bfrom\b.+\b(information_schema|wp_users|wp_options|wp_usermeta)\b/i',
				'message'  => 'SQL Injection: SELECT FROM system tables',
			),
			array(
				'id'       => 'sqli_boolean',
				'category' => 'sqli',
				'severity' => 'high',
				'regex'    => '/[\'"]\s*(or|and)\s+[\'"0-9][\'"0-9\s]*=\s*[\'"0-9]/i',
				'message'  => 'SQL Injection: Boolean-based',
			),
			array(
				'id'       => 'sqli_comment',
				'category' => 'sqli',
				'severity' => 'high',
				'regex'    => '/(--|#|\/\*)\s*$/m',
				'message'  => 'SQL Injection: Comment termination',
			),
			array(
				'id'       => 'sqli_stacked',
				'category' => 'sqli',
				'severity' => 'critical',
				'regex'    => '/;\s*(drop|alter|truncate|delete|insert|update)\b/i',
				'message'  => 'SQL Injection: Stacked query',
			),
			array(
				'id'       => 'sqli_sleep_benchmark',
				'category' => 'sqli',
				'severity' => 'high',
				'regex'    => '/\b(sleep|benchmark|waitfor\s+delay)\s*\(/i',
				'message'  => 'SQL Injection: Time-based blind',
			),

			// XSS (Cross-Site Scripting).
			array(
				'id'       => 'xss_script_tag',
				'category' => 'xss',
				'severity' => 'high',
				'regex'    => '/<\s*script\b[^>]*>/i',
				'message'  => 'XSS: Script tag injection',
			),
			array(
				'id'       => 'xss_event_handler',
				'category' => 'xss',
				'severity' => 'high',
				'regex'    => '/\bon(error|load|mouseover|click|focus|blur|submit|change|keyup|keydown|mouseenter)\s*=/i',
				'message'  => 'XSS: Event handler injection',
			),
			array(
				'id'       => 'xss_javascript_uri',
				'category' => 'xss',
				'severity' => 'high',
				'regex'    => '/javascript\s*:/i',
				'message'  => 'XSS: JavaScript URI',
			),
			array(
				'id'       => 'xss_data_uri',
				'category' => 'xss',
				'severity' => 'medium',
				'regex'    => '/data\s*:\s*text\/html/i',
				'message'  => 'XSS: Data URI with HTML',
			),
			array(
				'id'       => 'xss_vbscript',
				'category' => 'xss',
				'severity' => 'high',
				'regex'    => '/vbscript\s*:/i',
				'message'  => 'XSS: VBScript URI',
			),

			// Path Traversal.
			array(
				'id'       => 'traversal_dotdot',
				'category' => 'traversal',
				'severity' => 'critical',
				'regex'    => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\.\.%2f|%2e%2e%5c)/i',
				'message'  => 'Path Traversal: Directory traversal attempt',
			),
			array(
				'id'       => 'traversal_sensitive_files',
				'category' => 'traversal',
				'severity' => 'critical',
				'regex'    => '/(\/etc\/passwd|\/etc\/shadow|\/proc\/self|wp-config\.php)/i',
				'message'  => 'Path Traversal: Sensitive file access',
			),

			// Remote File Include (RFI).
			array(
				'id'       => 'rfi_http_param',
				'category' => 'rfi',
				'severity' => 'critical',
				'regex'    => '/=(https?|ftp):\/\/[^\s]+\.(php|asp|jsp|cgi|pl)\b/i',
				'message'  => 'Remote File Include: External script reference',
			),

			// Command Injection.
			array(
				'id'       => 'cmdi_semicolon',
				'category' => 'cmdi',
				'severity' => 'critical',
				'regex'    => '/;\s*(ls|cat|rm|wget|curl|chmod|chown|nc|netcat|python|perl|bash|sh|powershell)\b/i',
				'message'  => 'Command Injection: Shell command',
			),
			array(
				'id'       => 'cmdi_backtick',
				'category' => 'cmdi',
				'severity' => 'critical',
				'regex'    => '/`[^`]*(ls|cat|rm|wget|curl|id|whoami|uname)[^`]*`/i',
				'message'  => 'Command Injection: Backtick execution',
			),
			array(
				'id'       => 'cmdi_pipe',
				'category' => 'cmdi',
				'severity' => 'high',
				'regex'    => '/\|\s*(ls|cat|rm|wget|curl|id|whoami|uname|bash|sh)\b/i',
				'message'  => 'Command Injection: Pipe execution',
			),

			// Null Byte.
			array(
				'id'       => 'null_byte',
				'category' => 'null_byte',
				'severity' => 'high',
				'regex'    => '/%00|\\x00/',
				'message'  => 'Null byte injection attempt',
			),

			// PHP Wrappers.
			array(
				'id'       => 'php_wrapper',
				'category' => 'rfi',
				'severity' => 'critical',
				'regex'    => '/php:\/\/(input|filter|data|expect)/i',
				'message'  => 'PHP wrapper: Stream injection',
			),

			// WordPress-specific attacks.
			array(
				'id'       => 'wp_admin_ajax_abuse',
				'category' => 'wp',
				'severity' => 'medium',
				'regex'    => '/admin-ajax\.php.*action=.*\.\.\//i',
				'message'  => 'WordPress: Admin AJAX path traversal',
			),

			// wp2shell (CVE-2026-63030): REST API batch-route confusion RCE.
			array(
				'id'       => 'wp2shell_batch_route',
				'category' => 'wp2shell',
				'severity' => 'critical',
				'regex'    => '/\/batch\/v\d/i',
				'message'  => 'wp2shell: REST API batch endpoint (CVE-2026-63030)',
			),
		);

		// Merge custom WAF rules from Threat Patterns module.
		$custom_rules = $this->custom_waf_rules();
		if ( ! empty( $custom_rules ) ) {
			$rules = array_merge( $rules, $custom_rules );
		}

		return $rules;
	}

	private function custom_waf_rules() {
		if ( ! class_exists( 'Shield_Monitor_Threat_Patterns' ) ) {
			return array();
		}
		$patterns = Shield_Monitor_Threat_Patterns::instance();
		return $patterns->get_waf_rules();
	}

	private function block( $rule, $source, $value ) {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$this->logger->record( 'waf_blocked', array(
			'rule_id'  => sanitize_key( $rule['id'] ),
			'category' => sanitize_key( $rule['category'] ),
			'severity' => sanitize_key( $rule['severity'] ),
			'message'  => sanitize_text_field( $rule['message'] ),
			'source'   => sanitize_key( $source ),
			'ip'       => sanitize_text_field( $ip ),
		) );

		// Store last 50 blocked requests for admin review.
		// Use transient (auto-expires) instead of option (persists forever)
		// to prevent unbounded growth that can exceed MySQL max_allowed_packet.
		$log = get_transient( self::LOG_OPTION );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'rule'    => sanitize_key( $rule['id'] ),
			'message' => sanitize_text_field( $rule['message'] ),
			'ip'      => sanitize_text_field( $ip ),
			'uri'     => sanitize_text_field( substr( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', 0, 200 ) ),
			'time'    => gmdate( 'Y-m-d H:i:s' ),
		);
		set_transient( self::LOG_OPTION, array_slice( $log, -50 ), DAY_IN_SECONDS );

		status_header( 403 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body>';
		echo '<h1>403 Forbidden</h1>';
		echo '<p>' . esc_html__( 'Your request has been blocked by the security firewall.', 'codewp-shield-monitor' ) . '</p>';
		echo '<p><small>ShieldPress WAF &mdash; Rule: ' . esc_html( $rule['id'] ) . '</small></p>';
		echo '</body></html>';
		exit;
	}
}
