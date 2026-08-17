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
			'limit_heartbeat'        => 0,
			'security_headers'       => 1,
			'hide_version'           => 1,
			'disable_file_editor'    => 1,
			'disable_software_installation' => 0,
			'captcha_enabled'        => 0,
			'captcha_provider'       => 'turnstile',
			'captcha_site_key'       => '',
			'captcha_secret_key'     => '',
			'admin_pin_enabled'      => 0,
			'admin_pin_hash'         => '',
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
			'core_checksum_verification' => 0,
			'notify_contact_form_7'    => 0,
			'notify_woocommerce'       => 0,
			'notify_post_types'        => array(),
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
		foreach ( array( 'login_protection', 'hide_login', 'block_enumeration', 'disable_xmlrpc', 'restrict_rest_api', 'limit_heartbeat', 'security_headers', 'hide_version', 'disable_file_editor', 'disable_software_installation', 'captcha_enabled', 'admin_pin_enabled', 'audit_log', 'file_monitoring', 'malware_scanning', 'email_alerts', 'alert_admin_login', 'alert_admin_user_changes', 'alert_file_changes', 'alert_lockouts', 'alert_malware_findings', 'core_checksum_verification', 'notify_contact_form_7', 'notify_woocommerce' ) as $key ) {
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
		$frequency = sanitize_key( $input['malware_scan_frequency'] ?? ( $current['malware_scan_frequency'] ?? 'daily' ) );
		$clean['malware_scan_frequency'] = in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ? $frequency : 'daily';
		$time = sanitize_text_field( $input['malware_scan_time'] ?? ( $current['malware_scan_time'] ?? '03:15' ) );
		$clean['malware_scan_time'] = preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '03:15';
		$captcha_provider = sanitize_key( $input['captcha_provider'] ?? ( $current['captcha_provider'] ?? 'turnstile' ) );
		$clean['captcha_provider'] = in_array( $captcha_provider, array( 'recaptcha', 'turnstile' ), true ) ? $captcha_provider : 'turnstile';
		$clean['captcha_site_key'] = sanitize_text_field( $input['captcha_site_key'] ?? ( $current['captcha_site_key'] ?? '' ) );
		$clean['captcha_secret_key'] = sanitize_text_field( $input['captcha_secret_key'] ?? ( $current['captcha_secret_key'] ?? '' ) );
		$pin = isset( $input['admin_pin'] ) ? preg_replace( '/\D/', '', (string) $input['admin_pin'] ) : '';
		$clean['admin_pin_hash'] = $current['admin_pin_hash'] ?? '';
		if ( $pin ) {
			if ( preg_match( '/^\d{6}$/', $pin ) ) {
				$clean['admin_pin_hash'] = wp_hash_password( $pin );
			} else {
				add_settings_error( self::OPTION, 'shield_monitor_pin_invalid', __( 'Admin PIN must contain exactly 6 digits.', 'codewp-shield-monitor' ) );
			}
		}
		if ( $clean['admin_pin_enabled'] && empty( $clean['admin_pin_hash'] ) ) {
			$clean['admin_pin_enabled'] = 0;
			add_settings_error( self::OPTION, 'shield_monitor_pin_required', __( 'Set a 6-digit admin PIN before enabling PIN login.', 'codewp-shield-monitor' ) );
		}
		$quick_login_user_id = absint( $input['quick_login_user_id'] ?? ( $current['quick_login_user_id'] ?? 0 ) );
		$clean['quick_login_user_id'] = $this->valid_admin_user_id( $quick_login_user_id );
		$clean['max_attempts']        = max( 3, min( 20, absint( $input['max_attempts'] ?? ( $current['max_attempts'] ?? 5 ) ) ) );
		$clean['lockout_minutes']     = max( 5, min( 1440, absint( $input['lockout_minutes'] ?? ( $current['lockout_minutes'] ?? 15 ) ) ) );
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
