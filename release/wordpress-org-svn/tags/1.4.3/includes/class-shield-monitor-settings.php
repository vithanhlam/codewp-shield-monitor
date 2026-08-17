<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Settings {
	const OPTION = 'shield_monitor_settings';

	public function defaults() {
		return array(
			'login_protection'       => 1,
			'max_attempts'           => 5,
			'lockout_minutes'        => 15,
			'hide_login'             => 0,
			'login_slug'             => 'codewp-shield-monitor-login',
			'block_enumeration'      => 1,
			'disable_xmlrpc'         => 0,
			'restrict_rest_api'      => 0,
			'rest_api_public_namespaces'       => array(),
			'rest_api_block_user_creation'     => 1,
			'rest_api_block_media_upload'      => 0,
			'rest_api_block_post_creation'     => 0,
			'rest_api_block_plugin_management' => 1,
			'rest_api_block_settings_access'   => 1,
			'rest_api_block_export'            => 1,
			'rest_api_block_app_passwords'     => 1,
			'limit_heartbeat'        => 0,
			'security_headers'       => 1,
			'hide_version'           => 1,
			'disable_file_editor'    => 1,
			'disable_software_installation' => 0,
			'captcha_enabled'        => 0,
			'captcha_provider'       => 'turnstile',
			'captcha_site_key'       => '',
			'captcha_secret_key'     => '',
			'captcha_forms'          => array( 'login' ),
			'captcha_score_threshold'=> '0.1',
			'admin_pin_enabled'      => 0,
			'admin_pin_hash'         => '',
			'admin_pin_roles'        => array( 'administrator', 'editor', 'shop_manager' ),
			'quick_login_enabled'    => 0,
			'quick_login_configured' => 0,
			'quick_login_user_id'    => 0,
			'audit_log'              => 1,
			'data_retention_days'    => 30,
			'file_monitoring'         => 1,
			'malware_scanning'        => 1,
			'malware_scan_frequency'  => 'daily',
			'malware_scan_time'       => '03:15',
			'email_alerts'            => 1,
			'alert_admin_login'       => 1,
			'alert_admin_user_changes'=> 1,
			'alert_file_changes'      => 1,
			'alert_lockouts'          => 1,
			'alert_malware_findings'  => 1,
			'alert_backup_overdue'    => 1,
			'alert_vulnerability'     => 1,
			'core_checksum_verification' => 0,
			'notify_contact_form_7'    => 0,
			'notify_woocommerce'       => 0,
			'notify_post_types'        => array(),
			'waf_enabled'                    => 0,
			'honeypot_enabled'               => 0,
			'block_php_uploads'              => 0,
			'comment_captcha_enabled'        => 0,
			'comment_rate_limit_enabled'     => 0,
			'comment_rate_limit_max'         => 5,
			'comment_rate_limit_window'      => 60,
			'comment_math_captcha_enabled'   => 0,
			'registration_rate_limit_enabled'  => 0,
			'registration_rate_limit_max'      => 3,
			'registration_rate_limit_window'   => 60,
			'disable_image_sizes'              => 0,
			'keep_image_sizes'                 => array(),
			'webp_convert_enabled'             => 0,
			'webp_keep_original'               => 1,
			'webp_quality'                     => 82,
			'upload_max_size_kb'               => 1024,
			'upload_limit_enabled'             => 0,
			'image_resize_enabled'             => 0,
			'image_max_width'                  => 1920,
		);
	}

	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	public function register() {
		register_setting(
			'codewp-shield-monitor',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function all() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), $this->defaults() );
	}

	public function get( $key ) {
		$settings = $this->all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = $this->all();
		$clean = array();
		foreach ( array( 'login_protection', 'hide_login', 'block_enumeration', 'disable_xmlrpc', 'restrict_rest_api', 'rest_api_block_user_creation', 'rest_api_block_media_upload', 'rest_api_block_post_creation', 'rest_api_block_plugin_management', 'rest_api_block_settings_access', 'rest_api_block_export', 'rest_api_block_app_passwords', 'limit_heartbeat', 'security_headers', 'hide_version', 'disable_file_editor', 'disable_software_installation', 'captcha_enabled', 'admin_pin_enabled', 'audit_log', 'file_monitoring', 'malware_scanning', 'email_alerts', 'alert_admin_login', 'alert_admin_user_changes', 'alert_file_changes', 'alert_lockouts', 'alert_malware_findings', 'alert_backup_overdue', 'alert_vulnerability', 'core_checksum_verification', 'notify_contact_form_7', 'notify_woocommerce', 'waf_enabled', 'honeypot_enabled', 'block_php_uploads', 'comment_captcha_enabled', 'comment_rate_limit_enabled', 'comment_math_captcha_enabled', 'registration_rate_limit_enabled', 'disable_image_sizes', 'webp_convert_enabled', 'webp_keep_original', 'upload_limit_enabled', 'image_resize_enabled' ) as $key ) {
			$clean[ $key ] = array_key_exists( $key, $input ) ? ( empty( $input[ $key ] ) ? 0 : 1 ) : absint( $current[ $key ] ?? 0 );
		}
		$quick_login_submitted = ! empty( $input['quick_login_submitted'] );
		$clean['quick_login_configured'] = $quick_login_submitted ? 1 : absint( $current['quick_login_configured'] ?? 0 );
		$clean['quick_login_enabled'] = $quick_login_submitted ? ( empty( $input['quick_login_enabled'] ) ? 0 : 1 ) : absint( $current['quick_login_enabled'] ?? 0 );
		$selected_post_types = isset( $input['notify_post_types'] ) && is_array( $input['notify_post_types'] ) ? $input['notify_post_types'] : ( array_key_exists( 'notify_post_types_submitted', $input ) ? array() : ( $current['notify_post_types'] ?? array() ) );
		$available_post_types = get_post_types( array( 'show_ui' => true ), 'names' );
		$blocked_post_types = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'shop_order', 'shop_coupon' );
		$clean['notify_post_types'] = array_values( array_intersect( array_diff( array_map( 'sanitize_key', (array) $selected_post_types ), $blocked_post_types ), array_map( 'sanitize_key', $available_post_types ) ) );
		$slug = sanitize_title( $input['login_slug'] ?? ( $current['login_slug'] ?? 'codewp-shield-monitor-login' ) );
		$clean['login_slug'] = $slug && ! in_array( $slug, array( 'wp-admin', 'wp-login', 'login', 'admin' ), true ) ? $slug : 'codewp-shield-monitor-login';
		$ns_input = isset( $input['rest_api_public_namespaces'] ) && is_array( $input['rest_api_public_namespaces'] )
			? $input['rest_api_public_namespaces']
			: ( array_key_exists( 'rest_api_public_namespaces_submitted', $input )
				? array()
				: ( $current['rest_api_public_namespaces'] ?? array() ) );
		$clean['rest_api_public_namespaces'] = array_values( array_map( 'sanitize_text_field', (array) $ns_input ) );
		$frequency = sanitize_key( $input['malware_scan_frequency'] ?? ( $current['malware_scan_frequency'] ?? 'daily' ) );
		$clean['malware_scan_frequency'] = in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ? $frequency : 'daily';
		$time = sanitize_text_field( $input['malware_scan_time'] ?? ( $current['malware_scan_time'] ?? '03:15' ) );
		$clean['malware_scan_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '03:15';
		$captcha_provider = sanitize_key( $input['captcha_provider'] ?? ( $current['captcha_provider'] ?? 'turnstile' ) );
		$captcha_provider = 'recaptcha' === $captcha_provider ? 'recaptcha_v2' : $captcha_provider;
		$clean['captcha_provider'] = in_array( $captcha_provider, array( 'recaptcha_v2', 'recaptcha_v3', 'turnstile' ), true ) ? $captcha_provider : 'turnstile';
		$clean['captcha_site_key'] = sanitize_text_field( $input['captcha_site_key'] ?? ( $current['captcha_site_key'] ?? '' ) );
		$clean['captcha_secret_key'] = sanitize_text_field( $input['captcha_secret_key'] ?? ( $current['captcha_secret_key'] ?? '' ) );
		$captcha_forms = isset( $input['captcha_forms'] ) && is_array( $input['captcha_forms'] ) ? $input['captcha_forms'] : ( array_key_exists( 'captcha_forms_submitted', $input ) ? array() : ( $current['captcha_forms'] ?? array( 'login' ) ) );
		$clean['captcha_forms'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $captcha_forms ), array( 'login', 'register', 'checkout', 'comment' ) ) );
		if ( empty( $clean['captcha_forms'] ) ) {
			$clean['captcha_forms'] = array( 'login' );
		}
		$threshold = str_replace( ',', '.', sanitize_text_field( $input['captcha_score_threshold'] ?? ( $current['captcha_score_threshold'] ?? '0.1' ) ) );
		$threshold = is_numeric( $threshold ) ? (float) $threshold : 0.1;
		$clean['captcha_score_threshold'] = rtrim( rtrim( sprintf( '%.2F', max( 0, min( 1, $threshold ) ) ), '0' ), '.' );
		$pin = isset( $input['admin_pin'] ) ? preg_replace( '/\D/', '', (string) $input['admin_pin'] ) : '';
		$clean['admin_pin_hash'] = $current['admin_pin_hash'] ?? '';
		if ( $pin ) {
			if ( preg_match( '/^\d{6}$/', $pin ) ) {
				$clean['admin_pin_hash'] = wp_hash_password( $pin );
			} else {
				add_settings_error( self::OPTION, 'shield_monitor_pin_invalid', __( 'Staff PIN must contain exactly 6 digits.', 'codewp-shield-monitor' ) );
			}
		}
		if ( $clean['admin_pin_enabled'] && empty( $clean['admin_pin_hash'] ) ) {
			$clean['admin_pin_enabled'] = 0;
			add_settings_error( self::OPTION, 'shield_monitor_pin_required', __( 'Set a 6-digit staff PIN before enabling PIN login.', 'codewp-shield-monitor' ) );
		}
		$editable_roles = array_keys( get_editable_roles() );
		$default_roles  = array( 'administrator', 'editor', 'shop_manager' );
		$selected_roles = isset( $input['admin_pin_roles'] ) && is_array( $input['admin_pin_roles'] ) ? $input['admin_pin_roles'] : ( $current['admin_pin_roles'] ?? $default_roles );
		$clean['admin_pin_roles'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $selected_roles ), array_map( 'sanitize_key', $editable_roles ) ) );
		if ( empty( $clean['admin_pin_roles'] ) ) {
			$clean['admin_pin_roles'] = array( 'administrator' );
		}
		$quick_login_user_id = absint( $input['quick_login_user_id'] ?? ( $current['quick_login_user_id'] ?? 0 ) );
		$clean['quick_login_user_id'] = $this->valid_admin_user_id( $quick_login_user_id );
		$clean['max_attempts']        = max( 3, min( 20, absint( $input['max_attempts'] ?? ( $current['max_attempts'] ?? 5 ) ) ) );
		$clean['lockout_minutes']     = max( 5, min( 1440, absint( $input['lockout_minutes'] ?? ( $current['lockout_minutes'] ?? 15 ) ) ) );
		$clean['comment_rate_limit_max']    = max( 1, min( 50, absint( $input['comment_rate_limit_max'] ?? ( $current['comment_rate_limit_max'] ?? 5 ) ) ) );
		$clean['comment_rate_limit_window'] = max( 1, min( 1440, absint( $input['comment_rate_limit_window'] ?? ( $current['comment_rate_limit_window'] ?? 60 ) ) ) );
		$clean['registration_rate_limit_max']    = max( 1, min( 20, absint( $input['registration_rate_limit_max'] ?? ( $current['registration_rate_limit_max'] ?? 3 ) ) ) );
		$clean['registration_rate_limit_window'] = max( 1, min( 1440, absint( $input['registration_rate_limit_window'] ?? ( $current['registration_rate_limit_window'] ?? 60 ) ) ) );
		$clean['webp_quality'] = max( 1, min( 100, absint( $input['webp_quality'] ?? ( $current['webp_quality'] ?? 82 ) ) ) );
		$clean['upload_max_size_kb'] = max( 100, min( 20480, absint( $input['upload_max_size_kb'] ?? ( $current['upload_max_size_kb'] ?? 1024 ) ) ) );
		$clean['image_max_width']    = max( 200, min( 10000, absint( $input['image_max_width'] ?? ( $current['image_max_width'] ?? 1920 ) ) ) );
		$keep_sizes = isset( $input['keep_image_sizes'] ) && is_array( $input['keep_image_sizes'] ) ? $input['keep_image_sizes'] : ( array_key_exists( 'keep_image_sizes_submitted', $input ) ? array() : ( $current['keep_image_sizes'] ?? array() ) );
		$clean['keep_image_sizes'] = array_values( array_map( 'sanitize_key', (array) $keep_sizes ) );
		$clean['data_retention_days'] = 30;
		return $clean;
	}

	public function quick_login_user_id() {
		return $this->valid_admin_user_id( absint( $this->get( 'quick_login_user_id' ) ) );
	}

	public function quick_login_enabled() {
		$settings = $this->all();
		return ! empty( $settings['quick_login_enabled'] );
	}

	private function valid_admin_user_id( $user_id ) {
		$user = $user_id ? get_user_by( 'id', $user_id ) : false;
		if ( $user && user_can( $user, 'manage_options' ) ) {
			return absint( $user->ID );
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins[0] ) ? absint( $admins[0] ) : 0;
	}
}
