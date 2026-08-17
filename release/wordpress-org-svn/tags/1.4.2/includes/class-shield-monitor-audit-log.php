<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Audit_Log {
	const RATE_LIMIT_WINDOW  = 300;
	const RATE_LIMIT_MAX     = 20;
	const CLEANUP_BATCH_SIZE = 500;

	private static $buffer         = array();
	private static $shutdown_added = false;
	private static $rate_counters  = array();

	public function register_hooks() {
		add_action( 'wp_login', array( $this, 'login_success' ), 10, 2 );
		add_action( 'user_register', array( $this, 'user_registered' ) );
		add_action( 'set_user_role', array( $this, 'role_changed' ), 10, 3 );
		add_action( 'profile_update', array( $this, 'profile_updated' ), 10, 3 );
		add_action( 'after_password_reset', array( $this, 'password_reset' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'post_saved' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'software_updated' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'plugin_deactivated' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'theme_activated' ), 10, 3 );
		add_action( 'shield_monitor_daily_cleanup', array( $this, 'cleanup' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		if ( ! wp_next_scheduled( 'shield_monitor_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'shield_monitor_daily_cleanup' );
		}
	}

	public function record( $type, $details = array(), $user_id = 0 ) {
		$settings = ( new Shield_Monitor_Settings() )->get( 'audit_log' );
		if ( ! $settings ) {
			return;
		}

		$type    = sanitize_key( $type );
		$ip_hash = $this->ip_hash();

		if ( $this->is_rate_limited( $type, $ip_hash ) ) {
			return;
		}

		self::$buffer[] = array(
			'event_type' => $type,
			'user_id'    => absint( $user_id ),
			'ip_hash'    => $ip_hash,
			'details'    => wp_json_encode( $details ),
			'created_at' => current_time( 'mysql', true ),
		);

		if ( ! self::$shutdown_added ) {
			add_action( 'shutdown', array( $this, 'flush_buffer' ) );
			self::$shutdown_added = true;
		}
	}

	public function flush_buffer() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'shield_monitor_events';
		$rows         = self::$buffer;
		self::$buffer = array();

		if ( count( $rows ) === 1 ) {
			$row = $rows[0];
			$wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%s', '%s' ) );
			return;
		}

		$placeholders = array();
		$values       = array();
		foreach ( $rows as $row ) {
			$placeholders[] = '(%s, %d, %s, %s, %s)';
			$values[]       = $row['event_type'];
			$values[]       = $row['user_id'];
			$values[]       = $row['ip_hash'];
			$values[]       = $row['details'];
			$values[]       = $row['created_at'];
		}

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"INSERT INTO {$table} (event_type, user_id, ip_hash, details, created_at) VALUES " . implode( ', ', $placeholders ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$values
		) );
	}

	private function is_rate_limited( $type, $ip_hash ) {
		$rate_key = $type . '|' . $ip_hash;
		$now      = time();

		if ( ! isset( self::$rate_counters[ $rate_key ] ) ) {
			self::$rate_counters[ $rate_key ] = array( 'count' => 0, 'window_start' => $now, 'summary_logged' => false );
		}

		$counter = &self::$rate_counters[ $rate_key ];

		if ( $now - $counter['window_start'] > self::RATE_LIMIT_WINDOW ) {
			$counter = array( 'count' => 0, 'window_start' => $now, 'summary_logged' => false );
		}

		$counter['count']++;

		if ( $counter['count'] > self::RATE_LIMIT_MAX ) {
			if ( ! $counter['summary_logged'] ) {
				$counter['summary_logged'] = true;
				self::$buffer[] = array(
					'event_type' => 'rate_limited',
					'user_id'    => 0,
					'ip_hash'    => $ip_hash,
					'details'    => wp_json_encode( array(
						'original_type' => $type,
						'count'         => $counter['count'],
						'window_sec'    => self::RATE_LIMIT_WINDOW,
					) ),
					'created_at' => current_time( 'mysql', true ),
				);
			}
			return true;
		}

		return false;
	}

	private function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '';
	}

	public function login_success( $user_login, $user ) {
		$this->record( user_can( $user, 'manage_options' ) ? 'admin_login' : 'login_success', array(), $user->ID );
	}

	public function user_registered( $user_id ) {
		$this->record( 'user_registered', array(), $user_id );
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			$details = array( 'username' => sanitize_user( $user->user_login, true ), 'source' => 'user_registered' );
			$this->record_admin_user_created( $user_id, $details );
		}
	}

	public function role_changed( $user_id, $role, $old_roles ) {
		$this->record( 'role_changed', array( 'new_role' => sanitize_key( $role ), 'old_roles' => array_map( 'sanitize_key', $old_roles ) ), $user_id );
		$old_roles = array_map( 'sanitize_key', (array) $old_roles );
		if ( 'administrator' === sanitize_key( $role ) && ! in_array( 'administrator', $old_roles, true ) ) {
			$user    = get_user_by( 'id', absint( $user_id ) );
			$details = array(
				'username'  => $user ? sanitize_user( $user->user_login, true ) : '',
				'old_roles' => $old_roles,
				'source'    => 'role_changed',
			);
			$this->record_admin_user_created( $user_id, $details );
		}
	}

	private function record_admin_user_created( $user_id, $details ) {
		$key = 'shield_monitor_admin_user_created_' . absint( $user_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		$this->record( 'admin_user_created', $details, $user_id );
		do_action( 'shield_monitor_admin_user_created', $user_id, $details );
	}

	public function profile_updated( $user_id, $old_user_data, $userdata ) {
		$user = get_user_by( 'id', absint( $user_id ) );
		if ( ! $user || ! user_can( $user, 'manage_options' ) || ! $old_user_data instanceof WP_User ) {
			return;
		}
		if ( empty( $userdata['user_pass'] ) || hash_equals( (string) $old_user_data->user_pass, (string) $userdata['user_pass'] ) ) {
			return;
		}
		$this->record_password_changed( $user->ID, 'profile_update' );
	}

	public function password_reset( $user, $new_pass ) {
		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_options' ) ) {
			return;
		}
		$this->record_password_changed( $user->ID, 'password_reset' );
	}

	private function record_password_changed( $user_id, $source ) {
		$key = 'shield_monitor_password_changed_' . absint( $user_id );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );
		$user = get_user_by( 'id', absint( $user_id ) );
		$this->record(
			'admin_password_changed',
			array(
				'username' => $user ? sanitize_user( $user->user_login, true ) : '',
				'source'   => sanitize_key( $source ),
			),
			$user_id
		);
	}

	public function post_saved( $post_id, $post, $update, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! $this->is_auditable_post_type( $post->post_type ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$details = array(
			'post_id'   => absint( $post_id ),
			'post_type' => sanitize_key( $post->post_type ),
			'title'     => sanitize_text_field( get_the_title( $post_id ) ),
			'status'    => sanitize_key( $post->post_status ),
		);

		if ( ! $update ) {
			$this->record( 'post_created', $details, absint( $post->post_author ) );
			return;
		}

		if ( ! $post_before instanceof WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post_before->post_status ) {
			$this->record( 'post_created', $details, absint( $post->post_author ) );
			return;
		}

		$changed = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'menu_order' ) as $field ) {
			if ( $post_before->{$field} !== $post->{$field} ) {
				$changed[] = sanitize_key( str_replace( 'post_', '', $field ) );
			}
		}
		if ( empty( $changed ) ) {
			return;
		}

		$details['changed_fields'] = $changed;
		$details['old_status']     = sanitize_key( $post_before->post_status );
		$this->record( 'post_updated', $details, absint( get_current_user_id() ?: $post->post_author ) );
	}

	private function is_auditable_post_type( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		return $post_type_object && ! empty( $post_type_object->public );
	}

	public function software_updated( $upgrader, $hook_extra ) {
		$type = isset( $hook_extra['type'] ) ? sanitize_key( $hook_extra['type'] ) : 'unknown';
		if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
			return;
		}
		$action = isset( $hook_extra['action'] ) ? sanitize_key( $hook_extra['action'] ) : 'update';

		$items = array();
		foreach ( array( 'plugins', 'themes' ) as $key ) {
			if ( ! empty( $hook_extra[ $key ] ) && is_array( $hook_extra[ $key ] ) ) {
				$items = array_merge( $items, array_map( 'sanitize_text_field', $hook_extra[ $key ] ) );
			}
		}
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$items[] = sanitize_text_field( $hook_extra['plugin'] );
		}
		if ( ! empty( $hook_extra['theme'] ) ) {
			$items[] = sanitize_text_field( $hook_extra['theme'] );
		}

		$this->record(
			'software_updated',
			array(
				'type'   => $type,
				'action' => $action,
				'items'  => array_values( array_unique( $items ) ),
			),
			get_current_user_id()
		);

		if ( 'install' === $action && in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$event_type = $type . '_installed';
			$this->record(
				$event_type,
				array(
					'type'   => $type,
					'action' => $action,
					'items'  => array_values( array_unique( $items ) ),
				),
				get_current_user_id()
			);
		}
		if ( 'update' === $action && in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			$this->record(
				$type . '_updated',
				array(
					'type'   => $type,
					'action' => $action,
					'items'  => array_values( array_unique( $items ) ),
				),
				get_current_user_id()
			);
		}
	}

	public function plugin_activated( $plugin, $network_wide ) {
		$this->record(
			'plugin_activated',
			array_merge(
				$this->plugin_details( $plugin ),
				array( 'network_wide' => (bool) $network_wide )
			),
			get_current_user_id()
		);
	}

	public function plugin_deactivated( $plugin, $network_wide ) {
		$this->record(
			'plugin_deactivated',
			array_merge(
				$this->plugin_details( $plugin ),
				array( 'network_wide' => (bool) $network_wide )
			),
			get_current_user_id()
		);
	}

	public function theme_activated( $new_name, $new_theme, $old_theme ) {
		$this->record(
			'theme_activated',
			array(
				'name'          => sanitize_text_field( $new_name ),
				'stylesheet'    => $new_theme instanceof WP_Theme ? sanitize_key( $new_theme->get_stylesheet() ) : sanitize_key( get_stylesheet() ),
				'template'      => $new_theme instanceof WP_Theme ? sanitize_key( $new_theme->get_template() ) : sanitize_key( get_template() ),
				'version'       => $new_theme instanceof WP_Theme ? sanitize_text_field( $new_theme->get( 'Version' ) ) : null,
				'old_name'      => $old_theme instanceof WP_Theme ? sanitize_text_field( $old_theme->get( 'Name' ) ) : '',
				'old_stylesheet'=> $old_theme instanceof WP_Theme ? sanitize_key( $old_theme->get_stylesheet() ) : '',
			),
			get_current_user_id()
		);
	}

	private function plugin_details( $plugin ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = trailingslashit( wp_normalize_path( dirname( plugin_dir_path( SHIELD_MONITOR_FILE ) ) ) ) . ltrim( (string) $plugin, '/' );
		$data = is_readable( $file ) ? get_plugin_data( $file, false, false ) : array();
		return array(
			'file'    => sanitize_text_field( $plugin ),
			'name'    => sanitize_text_field( $data['Name'] ?? $plugin ),
			'version' => sanitize_text_field( $data['Version'] ?? '' ),
		);
	}

	public function cleanup() {
		global $wpdb;
		$days  = 30;
		$table = $wpdb->prefix . 'shield_monitor_events';
		$total = 0;
		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < UTC_TIMESTAMP() - INTERVAL %d DAY ORDER BY id ASC LIMIT %d", $days, self::CLEANUP_BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total  += $deleted;
		} while ( $deleted >= self::CLEANUP_BATCH_SIZE && $total < 5000 );
	}

	public function recent( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, user_id, details, created_at FROM {$table} ORDER BY id DESC LIMIT %d", min( 100, absint( $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function recent_since( $seconds = 2592000, $limit = 200 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		$since = gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, user_id, details, created_at FROM {$table} WHERE created_at >= %s ORDER BY id DESC LIMIT %d", $since, min( 300, max( 1, absint( $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function after_id( $after_id = 0, $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, user_id, details, created_at FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", absint( $after_id ), min( 101, max( 1, absint( $limit ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function latest_id() {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		return absint( $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function counts( $seconds = 86400 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		$since = gmdate( 'Y-m-d H:i:s', time() - max( HOUR_IN_SECONDS, absint( $seconds ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event_type, COUNT(*) AS total FROM {$table} WHERE created_at >= %s GROUP BY event_type", $since ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();
		foreach ( (array) $rows as $type => $row ) {
			$result[ sanitize_key( $type ) ] = absint( $row->total );
		}
		return $result;
	}

	public function count_matching( $types, $seconds ) {
		global $wpdb;
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
		if ( empty( $types ) ) {
			return 0;
		}
		$table        = $wpdb->prefix . 'shield_monitor_events';
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );
		$args         = array_merge( $types, array( $since ) );
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type IN ({$placeholders}) AND created_at >= %s", $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function recent_matching( $types, $limit = 20 ) {
		global $wpdb;
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
		if ( empty( $types ) ) {
			return array();
		}
		$table        = $wpdb->prefix . 'shield_monitor_events';
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, array( min( 100, max( 1, absint( $limit ) ) ) ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, user_id, details, created_at FROM {$table} WHERE event_type IN ({$placeholders}) ORDER BY id DESC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function recent_activity_groups( $limit = 30 ) {
		$limit = min( 30, max( 1, absint( $limit ) ) );
		return array(
			'recent_post_updates'      => array_map( array( $this, 'event_payload' ), $this->recent_matching( array( 'post_updated' ), $limit ) ),
			'recent_post_creations'    => array_map( array( $this, 'event_payload' ), $this->recent_matching( array( 'post_created' ), $limit ) ),
			'recent_notifications'     => array_map( array( $this, 'event_payload' ), $this->recent_matching( array( 'contact_form_7_submission', 'woocommerce_order_created', 'custom_post_type_created' ), $limit ) ),
			'recent_admin_creations'   => array_map( array( $this, 'event_payload' ), $this->recent_matching( array( 'admin_user_created', 'admin_password_changed' ), $limit ) ),
			'recent_system_db_changes' => array_map( array( $this, 'event_payload' ), $this->recent_matching( array( 'file_added', 'file_modified', 'file_deleted', 'core_checksum_mismatch', 'software_updated', 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated', 'malware_scan_completed', 'malware_scan_suspicious', 'login_ip_blocked', 'login_ip_unlocked' ), $limit ) ),
		);
	}

	public function event_payload( $row ) {
		$details = json_decode( $row->details, true );
		if ( ! is_array( $details ) ) {
			$details = array();
		}
		return array(
			'id'         => absint( $row->id ),
			'type'       => sanitize_key( $row->event_type ),
			'title'      => $this->event_title( $row->event_type ),
			'category'   => $this->event_category( $row->event_type ),
			'severity'   => $this->event_severity( $row->event_type ),
			'user_id'    => absint( $row->user_id ),
			'username'   => $row->user_id ? sanitize_user( (string) get_the_author_meta( 'user_login', $row->user_id ), true ) : null,
			'details'    => map_deep( $details, 'sanitize_text_field' ),
			'created_at' => mysql2date( 'c', $row->created_at, false ),
		);
	}

	private function event_title( $type ) {
		$type = sanitize_key( $type );
		$labels = array(
			'admin_login' => __( 'Administrator login', 'codewp-shield-monitor' ),
			'login_success' => __( 'User login', 'codewp-shield-monitor' ),
			'login_failed' => __( 'Failed login', 'codewp-shield-monitor' ),
			'login_lockout' => __( 'Login lockout', 'codewp-shield-monitor' ),
			'login_ip_blocked' => __( 'Login IP blocked', 'codewp-shield-monitor' ),
			'login_ip_unlocked' => __( 'Login IP unlocked', 'codewp-shield-monitor' ),
			'quick_login_link_created' => __( 'Quick-login link created', 'codewp-shield-monitor' ),
			'quick_login_used' => __( 'Quick login used', 'codewp-shield-monitor' ),
			'quick_login_failed' => __( 'Quick login failed', 'codewp-shield-monitor' ),
			'admin_user_created' => __( 'Administrator user created', 'codewp-shield-monitor' ),
			'admin_password_changed' => __( 'Administrator password changed', 'codewp-shield-monitor' ),
			'post_created' => __( 'Post created', 'codewp-shield-monitor' ),
			'post_updated' => __( 'Post updated', 'codewp-shield-monitor' ),
			'file_added' => __( 'File added', 'codewp-shield-monitor' ),
			'file_modified' => __( 'File modified', 'codewp-shield-monitor' ),
			'file_deleted' => __( 'File deleted', 'codewp-shield-monitor' ),
			'file_baseline_trusted' => __( 'File baseline trusted', 'codewp-shield-monitor' ),
			'core_checksum_mismatch' => __( 'Core checksum mismatch', 'codewp-shield-monitor' ),
			'table_prefix_changed' => __( 'Database table prefix changed', 'codewp-shield-monitor' ),
			'software_updated' => __( 'Software updated', 'codewp-shield-monitor' ),
			'plugin_installed' => __( 'Plugin installed', 'codewp-shield-monitor' ),
			'plugin_updated' => __( 'Plugin updated', 'codewp-shield-monitor' ),
			'plugin_activated' => __( 'Plugin activated', 'codewp-shield-monitor' ),
			'plugin_deactivated' => __( 'Plugin deactivated', 'codewp-shield-monitor' ),
			'theme_installed' => __( 'Theme installed', 'codewp-shield-monitor' ),
			'theme_updated' => __( 'Theme updated', 'codewp-shield-monitor' ),
			'theme_activated' => __( 'Theme activated', 'codewp-shield-monitor' ),
			'malware_scan_completed' => __( 'Malware scan completed', 'codewp-shield-monitor' ),
			'malware_scan_suspicious' => __( 'Suspicious scan findings', 'codewp-shield-monitor' ),
			'malware_report_cleared' => __( 'Malware report cleared', 'codewp-shield-monitor' ),
			'app_paired' => __( 'App/Web paired', 'codewp-shield-monitor' ),
			'app_revoked' => __( 'App/Web revoked', 'codewp-shield-monitor' ),
			'token_rotated' => __( 'API token rotated', 'codewp-shield-monitor' ),
			'token_scope_changed' => __( 'API token scope changed', 'codewp-shield-monitor' ),
			'rate_limited' => __( 'Events rate-limited', 'codewp-shield-monitor' ),
		);
		return sanitize_text_field( $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ) );
	}

	private function event_category( $type ) {
		$type = sanitize_key( $type );
		if ( in_array( $type, array( 'admin_login', 'login_success', 'login_failed', 'login_lockout', 'login_ip_blocked', 'login_ip_unlocked', 'quick_login_link_created', 'quick_login_used', 'quick_login_failed' ), true ) ) {
			return 'login';
		}
		if ( in_array( $type, array( 'user_registered', 'role_changed', 'admin_user_created', 'admin_password_changed' ), true ) ) {
			return 'admin';
		}
		if ( in_array( $type, array( 'post_created', 'post_updated', 'custom_post_type_created' ), true ) ) {
			return 'content';
		}
		if ( in_array( $type, array( 'contact_form_7_submission', 'woocommerce_order_created' ), true ) ) {
			return 'notifications';
		}
		if ( in_array( $type, array( 'file_added', 'file_modified', 'file_deleted', 'file_baseline_trusted', 'core_checksum_mismatch' ), true ) ) {
			return 'files';
		}
		if ( in_array( $type, array( 'software_updated', 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated' ), true ) ) {
			return 'software';
		}
		if ( in_array( $type, array( 'malware_scan_completed', 'malware_scan_suspicious', 'malware_report_cleared' ), true ) ) {
			return 'scan';
		}
		if ( 'table_prefix_changed' === $type ) {
			return 'database';
		}
		if ( in_array( $type, array( 'app_paired', 'app_revoked', 'token_rotated', 'token_scope_changed' ), true ) ) {
			return 'app';
		}
		return 'system';
	}

	private function event_severity( $type ) {
		$type = sanitize_key( $type );
		if ( in_array( $type, array( 'core_checksum_mismatch', 'malware_scan_suspicious' ), true ) ) {
			return 'critical';
		}
		if ( in_array( $type, array( 'login_lockout', 'login_ip_blocked', 'admin_user_created', 'file_added', 'file_modified', 'file_deleted', 'table_prefix_changed' ), true ) ) {
			return 'high';
		}
		if ( in_array( $type, array( 'login_failed', 'quick_login_failed', 'admin_password_changed', 'software_updated', 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated' ), true ) ) {
			return 'medium';
		}
		return 'low';
	}

	public function latest_login() {
		global $wpdb;
		$table = $wpdb->prefix . 'shield_monitor_events';
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT id, event_type, user_id, created_at FROM {$table} WHERE event_type IN (%s, %s) ORDER BY id DESC LIMIT 1", 'admin_login', 'login_success' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $event ) {
			return null;
		}

		$user = $event->user_id ? get_user_by( 'id', absint( $event->user_id ) ) : false;

		return array(
			'event_id'     => absint( $event->id ),
			'event_type'   => sanitize_key( $event->event_type ),
			'user_id'      => absint( $event->user_id ),
			'username'     => $user ? sanitize_user( $user->user_login, true ) : null,
			'display_name' => $user ? sanitize_text_field( $user->display_name ) : null,
			'roles'        => $user ? array_values( array_map( 'sanitize_key', (array) $user->roles ) ) : array(),
			'logged_in_at' => $this->format_utc_datetime( $event->created_at ),
		);
	}

	public function recent_file_changes( $limit = 20, $seconds = 2592000 ) {
		global $wpdb;
		$types        = array( 'file_added', 'file_modified', 'file_deleted', 'core_checksum_mismatch' );
		$table        = $wpdb->prefix . 'shield_monitor_events';
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - absint( $seconds ) );
		$args         = array_merge( $types, array( $since, min( 100, max( 1, absint( $limit ) ) ) ) );
		$events       = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, details, created_at FROM {$table} WHERE event_type IN ({$placeholders}) AND created_at >= %s ORDER BY id DESC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = array();

		foreach ( $events as $event ) {
			$details = json_decode( $event->details, true );
			if ( ! is_array( $details ) ) {
				$details = array();
			}

			$result[] = array(
				'event_id'   => absint( $event->id ),
				'event_type' => sanitize_key( $event->event_type ),
				'path'       => isset( $details['path'] ) ? sanitize_text_field( $details['path'] ) : null,
				'change'     => isset( $details['change'] ) ? sanitize_key( $details['change'] ) : sanitize_key( str_replace( 'file_', '', $event->event_type ) ),
				'changed_at' => $this->format_utc_datetime( $event->created_at ),
			);
		}

		return $result;
	}

	private function format_utc_datetime( $datetime ) {
		$timestamp = strtotime( (string) $datetime );
		return $timestamp ? gmdate( 'c', $timestamp ) : null;
	}

	public function privacy_policy_content() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'CodeWP Shield Monitor',
				wp_kses_post( __( 'CodeWP Shield Monitor stores login, user-role, administrator-access, quick-login, content-create/update, plugin/theme lifecycle, software-update, suspicious-code scan, notification, and file-change event types, timestamps, WordPress user IDs, usernames for administrator-access events, public post IDs, public post types, post titles, post statuses, changed field names, plugin/theme labels, relative file paths, cryptographic file hashes, scan severity levels, scan signatures, database table/record labels, short sanitized scan evidence snippets, selected Contact Form 7 field values, WooCommerce order metadata such as order number, status, total, currency, item count, payment method, customer ID, and basic billing contact fields, and one-way hashed IP addresses on this website for 30 days. For failed-login lockout management, CodeWP Shield Monitor may also store recent source IP addresses, attempt counts, lockout status, and last failed-login time so administrators can block or unlock those IPs. File uploads, file contents, full database values, CAPTCHA tokens, API tokens, quick-login tokens, and post body content are never stored. If email alerts are enabled, login IP and browser information or suspicious scan summaries can be emailed to the site administrator. When core checksum verification is enabled, the installed WordPress version, locale, and CodeWP Shield Monitor version user-agent are sent to the official WordPress.org checksum API at most once every 12 hours. Stored credentials, file contents, full database values, and post body content are not included; WordPress.org can observe the network request source IP. A paired CodeWP Shield Monitor App or Web client can request security data, notification events, and one-time quick-login URLs through scoped, expiring, revocable token-protected endpoints hosted on this website.', 'codewp-shield-monitor' ) )
			);
		}
	}

	public function register_exporter( $exporters ) {
		$exporters['codewp-shield-monitor'] = array(
			'exporter_friendly_name' => __( 'CodeWP Shield Monitor security events', 'codewp-shield-monitor' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['codewp-shield-monitor'] = array(
			'eraser_friendly_name' => __( 'CodeWP Shield Monitor security events', 'codewp-shield-monitor' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		global $wpdb;
		$table  = $wpdb->prefix . 'shield_monitor_events';
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT id, event_type, created_at FROM {$table} WHERE user_id = %d ORDER BY id ASC", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data   = array();
		foreach ( $events as $event ) {
			$data[] = array(
				'group_id'    => 'shield_monitor-events',
				'group_label' => __( 'CodeWP Shield Monitor security events', 'codewp-shield-monitor' ),
				'item_id'     => 'shield_monitor-event-' . absint( $event->id ),
				'data'        => array(
					array( 'name' => __( 'Event', 'codewp-shield-monitor' ), 'value' => sanitize_key( $event->event_type ) ),
					array( 'name' => __( 'Time (UTC)', 'codewp-shield-monitor' ), 'value' => sanitize_text_field( $event->created_at ) ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'shield_monitor_events';
		$updated = $wpdb->update( $table, array( 'user_id' => 0 ), array( 'user_id' => $user->ID ), array( '%d' ), array( '%d' ) );
		return array( 'items_removed' => (bool) $updated, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
