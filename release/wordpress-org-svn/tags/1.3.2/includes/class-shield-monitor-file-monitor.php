<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_File_Monitor {
	const HASHES_OPTION = 'shield_monitor_file_hashes';
	const CRON_HOOK     = 'shield_monitor_file_integrity_scan';
	const CORE_OPTION   = 'shield_monitor_core_checksum_status';
	const SCOPE_OPTION  = 'shield_monitor_file_scope_version';
	const SCOPE_VERSION = '2';
	const DB_VERSION    = '1.0';

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'scan' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( 'admin_init', array( $this, 'maybe_scan_when_cron_overdue' ) );
		add_action( 'admin_post_shield_monitor_trust_file_baseline', array( $this, 'trust_current_baseline' ) );
	}

	public function cron_schedule( $schedules ) {
		$schedules['shield_monitor_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'codewp-shield-monitor' ),
		);
		return $schedules;
	}

	public static function create_baseline_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'shield_monitor_file_baseline';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_path varchar(500) NOT NULL,
			file_group varchar(40) NOT NULL DEFAULT 'other',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			md5_hash char(32) NOT NULL DEFAULT '',
			sha256_hash char(64) NOT NULL DEFAULT '',
			readable tinyint(1) NOT NULL DEFAULT 0,
			writable tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'normal',
			first_seen_at datetime NOT NULL,
			last_seen_at datetime DEFAULT NULL,
			last_changed_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_path (file_path(191)),
			KEY status (status),
			KEY file_group (file_group),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'shield_monitor_file_baseline_db_version', self::DB_VERSION, false );
	}

	private function maybe_create_baseline_table() {
		if ( self::DB_VERSION !== (string) get_option( 'shield_monitor_file_baseline_db_version', '' ) ) {
			self::create_baseline_table();
		}
	}

	public function ensure_schedule() {
		$this->maybe_create_baseline_table();
		$enabled = $this->settings->get( 'file_monitoring' ) || $this->settings->get( 'core_checksum_verification' );
		if ( $enabled && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'shield_monitor_five_minutes', self::CRON_HOOK );
		}
		if ( ! $enabled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public function maybe_scan_when_cron_overdue() {
		if ( ! current_user_can( 'manage_options' ) || get_transient( 'shield_monitor_file_admin_scan_lock' ) ) {
			return;
		}
		if ( ! $this->settings->get( 'file_monitoring' ) || ! $this->cron_overdue() ) {
			return;
		}
		set_transient( 'shield_monitor_file_admin_scan_lock', 1, 5 * MINUTE_IN_SECONDS );
		$this->scan();
	}

	public static function activate() {
		$monitor = new self( new Shield_Monitor_Settings(), new Shield_Monitor_Audit_Log() );
		self::create_baseline_table();
		$monitor->save_baseline();
	}

	public function save_baseline() {
		$snapshot = $this->snapshot();
		update_option( self::HASHES_OPTION, $snapshot, false );
		$this->sync_database_baseline( $snapshot, true );
		update_option( 'shield_monitor_file_baseline_created', gmdate( 'c' ), false );
		update_option( self::SCOPE_OPTION, self::SCOPE_VERSION, false );
	}

	public function trust_current_baseline() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to trust this baseline.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_trust_file_baseline' );
		$this->save_baseline();
		$this->logger->record( 'file_baseline_trusted', array( 'source' => 'manual' ), get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor#integrity' ) );
		exit;
	}

	public function scan() {
		$file_monitoring = $this->settings->get( 'file_monitoring' );
		if ( ! $file_monitoring && ! $this->settings->get( 'core_checksum_verification' ) ) {
			return;
		}
		if ( ! $file_monitoring ) {
			update_option( 'shield_monitor_file_last_scan', gmdate( 'c' ), false );
			update_option( 'shield_monitor_file_last_result', array( 'status' => 'checksum_only', 'changes' => 0, 'finished_at' => gmdate( 'c' ) ), false );
			$this->maybe_verify_core_checksums();
			return;
		}
		$before = get_option( self::HASHES_OPTION, array() );
		$after  = $this->snapshot( true );
		$scope_upgraded = self::SCOPE_VERSION !== (string) get_option( self::SCOPE_OPTION, '' );
		if ( ! is_array( $before ) || empty( $before ) ) {
			update_option( self::HASHES_OPTION, $after, false );
			$this->sync_database_baseline( $after, true );
			update_option( 'shield_monitor_file_baseline_created', gmdate( 'c' ), false );
			update_option( self::SCOPE_OPTION, self::SCOPE_VERSION, false );
			update_option( 'shield_monitor_file_last_result', array( 'status' => 'baseline_created', 'changes' => 0, 'finished_at' => gmdate( 'c' ) ), false );
			$this->maybe_verify_core_checksums();
			return;
		}
		if ( $scope_upgraded ) {
			foreach ( $after as $path => $metadata ) {
				if ( ! isset( $before[ $path ] ) ) {
					$before[ $path ] = $metadata;
				}
			}
			update_option( self::SCOPE_OPTION, self::SCOPE_VERSION, false );
		}

		$changes = $this->sync_database_baseline( $after, false, $scope_upgraded );
		update_option( self::HASHES_OPTION, $after, false );
		update_option( 'shield_monitor_file_last_result', array( 'status' => $changes ? 'changes_detected' : 'clean', 'changes' => count( $changes ), 'finished_at' => gmdate( 'c' ) ), false );
		if ( $changes ) {
			do_action( 'shield_monitor_files_changed', $changes );
		}
		$this->maybe_verify_core_checksums();
	}

	public function status() {
		$snapshot = get_option( self::HASHES_OPTION, array() );
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			$snapshot = $this->snapshot();
		}
		$db_summary = $this->baseline_summary();
		$writable = 0;
		foreach ( $snapshot as $file ) {
			if ( ! empty( $file['exists'] ) && ! empty( $file['writable'] ) ) {
				++$writable;
			}
		}
		$core_status = get_option( self::CORE_OPTION, array() );
		if ( ! is_array( $core_status ) || empty( $core_status ) ) {
			$core_status = array( 'status' => 'not_checked', 'checked_at' => null, 'modified_count' => null, 'missing_count' => null, 'files' => array() );
		}
		return array(
			'enabled'          => (bool) $this->settings->get( 'file_monitoring' ),
			'monitored_files'  => $db_summary['total'] ?: count( $snapshot ),
			'writable_files'   => $db_summary['writable'] ?: $writable,
			'changes_30_days'  => $this->logger->count_matching( array( 'file_added', 'file_modified', 'file_deleted', 'core_checksum_mismatch' ), 30 * DAY_IN_SECONDS ),
			'last_scan'        => get_option( 'shield_monitor_file_last_scan', null ),
			'last_result'      => get_option( 'shield_monitor_file_last_result', null ),
			'baseline_created_at' => get_option( 'shield_monitor_file_baseline_created', null ),
			'baseline_storage' => 'database',
			'hash_algorithms'  => array( 'md5', 'sha256' ),
			'baseline_summary' => $db_summary,
			'coverage'         => 'critical_and_entry_files',
			'next_scan_at'     => $this->next_scan_at(),
			'cron_overdue'     => $this->cron_overdue(),
			'core_checksum'    => $core_status,
		);
	}

	private function next_scan_at() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		return $next ? gmdate( 'c', $next ) : null;
	}

	private function cron_overdue() {
		$last = get_option( 'shield_monitor_file_last_scan', '' );
		if ( $last ) {
			return time() - strtotime( $last ) > 15 * MINUTE_IN_SECONDS;
		}
		if ( get_option( 'shield_monitor_file_baseline_created', '' ) ) {
			return true;
		}
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	private function baseline_summary() {
		global $wpdb;
		$this->maybe_create_baseline_table();
		$table = $this->baseline_table();
		$rows  = $wpdb->get_results( "SELECT status, file_group, COUNT(*) AS total, SUM(writable) AS writable FROM {$table} GROUP BY status, file_group" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$summary = array(
			'total'        => 0,
			'writable'     => 0,
			'normal'       => 0,
			'added'        => 0,
			'modified'     => 0,
			'deleted'      => 0,
			'groups'       => array(),
			'stored_fields' => array( 'path', 'size', 'md5', 'sha256', 'first_seen_at', 'last_seen_at', 'last_changed_at', 'status' ),
		);
		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( $row->status );
			$group  = sanitize_key( $row->file_group );
			$total  = absint( $row->total );
			$summary['total'] += $total;
			$summary['writable'] += absint( $row->writable );
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ] += $total;
			}
			if ( ! isset( $summary['groups'][ $group ] ) ) {
				$summary['groups'][ $group ] = 0;
			}
			$summary['groups'][ $group ] += $total;
		}
		return $summary;
	}

	private function sync_database_baseline( $snapshot, $create_only = false, $trust_new_paths = false ) {
		global $wpdb;
		$this->maybe_create_baseline_table();
		$table = $this->baseline_table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$rows  = $wpdb->get_results( "SELECT file_path, file_size, md5_hash, sha256_hash, status FROM {$table}", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = is_array( $rows ) ? $rows : array();

		if ( empty( $rows ) ) {
			foreach ( $snapshot as $path => $meta ) {
				$this->insert_baseline_row( $path, $meta, 'normal', $now );
			}
			return array();
		}

		$changes = array();
		foreach ( $snapshot as $path => $meta ) {
			$row = $rows[ $path ] ?? null;
			if ( ! $row ) {
				$status = $create_only || $trust_new_paths ? 'normal' : 'added';
				$this->insert_baseline_row( $path, $meta, $status, $now );
				if ( 'added' === $status ) {
					$changes[] = $this->record_file_change( $path, 'added', $meta );
				}
				continue;
			}

			$new_hash = (string) ( $meta['sha256'] ?? $meta['hash'] ?? '' );
			$old_hash = (string) $row->sha256_hash;
			$changed = ! empty( $meta['exists'] ) && ( ! hash_equals( $old_hash, $new_hash ) || (int) $row->file_size !== (int) ( $meta['size'] ?? 0 ) );
			$status  = $changed ? 'modified' : 'normal';
			$this->update_baseline_row( $path, $meta, $status, $now, $changed );
			if ( $changed && ! $create_only ) {
				$changes[] = $this->record_file_change( $path, 'modified', $meta, $row );
			}
			unset( $rows[ $path ] );
		}

		if ( ! $create_only ) {
			foreach ( $rows as $path => $row ) {
				if ( 'deleted' === (string) $row->status ) {
					continue;
				}
				$wpdb->update(
					$table,
					array( 'status' => 'deleted', 'last_changed_at' => $now, 'updated_at' => $now ),
					array( 'file_path' => $path ),
					array( '%s', '%s', '%s' ),
					array( '%s' )
				);
				$changes[] = $this->record_file_change( $path, 'deleted', array(), $row );
			}
		}

		return array_values( array_filter( $changes ) );
	}

	private function insert_baseline_row( $path, $meta, $status, $now ) {
		global $wpdb;
		$wpdb->insert(
			$this->baseline_table(),
			array(
				'file_path'       => sanitize_text_field( $path ),
				'file_group'      => sanitize_key( $meta['group'] ?? $this->file_group( $path ) ),
				'file_size'       => absint( $meta['size'] ?? 0 ),
				'md5_hash'        => sanitize_text_field( $meta['md5'] ?? '' ),
				'sha256_hash'     => sanitize_text_field( $meta['sha256'] ?? $meta['hash'] ?? '' ),
				'readable'        => empty( $meta['readable'] ) ? 0 : 1,
				'writable'        => empty( $meta['writable'] ) ? 0 : 1,
				'status'          => sanitize_key( $status ),
				'first_seen_at'   => $now,
				'last_seen_at'    => empty( $meta['exists'] ) ? null : $now,
				'last_changed_at' => 'normal' === $status ? null : $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function update_baseline_row( $path, $meta, $status, $now, $changed ) {
		global $wpdb;
		$data = array(
			'file_group'  => sanitize_key( $meta['group'] ?? $this->file_group( $path ) ),
			'file_size'   => absint( $meta['size'] ?? 0 ),
			'md5_hash'    => sanitize_text_field( $meta['md5'] ?? '' ),
			'sha256_hash' => sanitize_text_field( $meta['sha256'] ?? $meta['hash'] ?? '' ),
			'readable'    => empty( $meta['readable'] ) ? 0 : 1,
			'writable'    => empty( $meta['writable'] ) ? 0 : 1,
			'status'      => sanitize_key( $status ),
			'last_seen_at'=> empty( $meta['exists'] ) ? null : $now,
			'updated_at'  => $now,
		);
		$formats = array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );
		if ( $changed ) {
			$data['last_changed_at'] = $now;
			$formats[] = '%s';
		}
		$wpdb->update( $this->baseline_table(), $data, array( 'file_path' => $path ), $formats, array( '%s' ) );
	}

	private function record_file_change( $path, $change, $meta = array(), $old = null ) {
		$details = array(
			'path'      => sanitize_text_field( $path ),
			'change'    => sanitize_key( $change ),
			'group'     => sanitize_key( $meta['group'] ?? $this->file_group( $path ) ),
			'size'      => absint( $meta['size'] ?? 0 ),
			'md5'       => sanitize_text_field( $meta['md5'] ?? '' ),
			'sha256'    => sanitize_text_field( $meta['sha256'] ?? $meta['hash'] ?? '' ),
			'old_md5'   => $old && isset( $old->md5_hash ) ? sanitize_text_field( $old->md5_hash ) : null,
			'old_sha256'=> $old && isset( $old->sha256_hash ) ? sanitize_text_field( $old->sha256_hash ) : null,
			'severity'  => $this->change_severity( $path, $change, $meta ),
		);
		$this->logger->record( 'file_' . sanitize_key( $change ), $details );
		return $details;
	}

	private function change_severity( $path, $change, $meta ) {
		if ( false !== strpos( $path, 'uploads/' ) && preg_match( '/\.php$/i', $path ) ) {
			return 'critical';
		}
		if ( 0 === strpos( $path, 'wp-' ) || in_array( $path, array( '.htaccess', 'index.php', '../wp-config.php', 'wp-config.php' ), true ) ) {
			return 'critical';
		}
		if ( 'modified' === $change && ( 0 === strpos( $path, 'active-plugin/' ) || 0 === strpos( $path, 'active-theme/' ) ) ) {
			return 'high';
		}
		return 'deleted' === $change ? 'medium' : 'low';
	}

	private function baseline_table() {
		global $wpdb;
		return $wpdb->prefix . 'shield_monitor_file_baseline';
	}

	private function file_group( $path ) {
		if ( 0 === strpos( $path, 'active-plugin/' ) ) {
			return 'plugin';
		}
		if ( 0 === strpos( $path, 'active-theme/' ) ) {
			return 'theme';
		}
		if ( false !== strpos( $path, 'uploads/' ) ) {
			return 'uploads';
		}
		if ( 0 === strpos( $path, 'wp-admin/' ) || 0 === strpos( $path, 'wp-includes/' ) || 0 === strpos( $path, 'wp-' ) ) {
			return 'core';
		}
		return 'root';
	}

	private function maybe_verify_core_checksums() {
		if ( ! $this->settings->get( 'core_checksum_verification' ) || get_transient( 'shield_monitor_core_checksum_cooldown' ) ) {
			return;
		}
		set_transient( 'shield_monitor_core_checksum_cooldown', 1, 12 * HOUR_IN_SECONDS );
		global $wp_version;
		$url      = add_query_arg( array( 'version' => $wp_version, 'locale' => get_locale() ), 'https://api.wordpress.org/core/checksums/1.0/' );
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => true, 'user-agent' => 'CodeWP Shield Monitor/' . SHIELD_MONITOR_VERSION ) );
		$body     = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		$checksums = is_array( $body ) && isset( $body['checksums'] ) && is_array( $body['checksums'] ) ? $body['checksums'] : false;
		if ( ! is_array( $checksums ) ) {
			set_transient( 'shield_monitor_core_checksum_cooldown', 1, HOUR_IN_SECONDS );
			update_option( self::CORE_OPTION, array( 'status' => 'unavailable', 'checked_at' => gmdate( 'c' ), 'modified_count' => null, 'missing_count' => null, 'files' => array() ), false );
			return;
		}
		$modified = array();
		$missing  = array();
		$root     = $this->wordpress_root_path();
		foreach ( $checksums as $relative => $expected ) {
			$path = $root . ltrim( $relative, '/' );
			if ( ! is_file( $path ) ) {
				$missing[] = sanitize_text_field( $relative );
			} elseif ( is_readable( $path ) && ! hash_equals( strtolower( (string) $expected ), (string) md5_file( $path ) ) ) {
				$modified[] = sanitize_text_field( $relative );
			}
		}
		$issues      = array_merge( $modified, $missing );
		$status      = $issues ? 'mismatch' : 'verified';
		$fingerprint = hash( 'sha256', wp_json_encode( $issues ) );
		$previous    = get_option( self::CORE_OPTION, array() );
		update_option( self::CORE_OPTION, array( 'status' => $status, 'checked_at' => gmdate( 'c' ), 'wordpress_version' => $wp_version, 'locale' => get_locale(), 'modified_count' => count( $modified ), 'missing_count' => count( $missing ), 'files' => array_slice( $issues, 0, 50 ), 'fingerprint' => $fingerprint ), false );
		if ( $issues && ( $previous['fingerprint'] ?? '' ) !== $fingerprint ) {
			$changes = array();
			foreach ( array_slice( $issues, 0, 20 ) as $file ) {
				$changes[] = array( 'path' => $file, 'change' => 'core_checksum_mismatch' );
				$this->logger->record( 'core_checksum_mismatch', array( 'path' => $file ) );
			}
			do_action( 'shield_monitor_files_changed', $changes );
		}
	}

	private function snapshot( $record_scan = false ) {
		$files    = $this->critical_files();
		$snapshot = array();
		foreach ( $files as $relative => $absolute ) {
			$exists   = is_file( $absolute );
			$readable = $exists && is_readable( $absolute );
			$snapshot[ $relative ] = array(
				'exists'   => $exists,
				'hash'     => $readable ? hash_file( 'sha256', $absolute ) : '',
				'sha256'   => $readable ? hash_file( 'sha256', $absolute ) : '',
				'md5'      => $readable ? md5_file( $absolute ) : '',
				'size'     => $exists ? (int) filesize( $absolute ) : 0,
				'group'    => $this->file_group( $relative ),
				'writable' => $exists ? is_writable( $absolute ) : false,
				'readable' => $readable,
			);
		}
		if ( $record_scan ) {
			update_option( 'shield_monitor_file_last_scan', gmdate( 'c' ), false );
		}
		return $snapshot;
	}

	private function critical_files() {
		$files = array();
		$root  = $this->wordpress_root_path();
		foreach ( array( '.htaccess', 'index.php', 'wp-config.php', 'wp-load.php', 'wp-settings.php', 'wp-login.php', 'wp-cron.php', 'wp-blog-header.php', 'xmlrpc.php', 'wp-admin/index.php', 'wp-includes/version.php' ) as $relative ) {
			$files[ $relative ] = $root . $relative;
		}
		$parent_config = dirname( untrailingslashit( $root ) ) . '/wp-config.php';
		if ( ! is_file( $files['wp-config.php'] ) && is_file( $parent_config ) ) {
			$files['../wp-config.php'] = $parent_config;
			unset( $files['wp-config.php'] );
		}
		$stylesheet = get_stylesheet_directory();
		foreach ( array( 'functions.php', 'style.css' ) as $name ) {
			$files[ 'active-theme/' . $name ] = trailingslashit( $stylesheet ) . $name;
		}
		$files = array_merge( $files, $this->directory_files( $stylesheet, 'active-theme/' . basename( $stylesheet ), 300 ) );
		$active_plugins = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$plugin_file_budget = 1200;
		$plugins_dir        = $this->plugins_directory_path();
		foreach ( array_unique( $active_plugins ) as $plugin ) {
			$safe = sanitize_text_field( $plugin );
			if ( $safe ) {
				$files[ 'active-plugin/' . $safe ] = $plugins_dir . $safe;
				$plugin_dir = $plugins_dir . dirname( $safe );
				if ( $plugin_file_budget > 0 && '.' !== dirname( $safe ) && is_dir( $plugin_dir ) ) {
					$plugin_files       = $this->directory_files( $plugin_dir, 'active-plugin/' . dirname( $safe ), min( 250, $plugin_file_budget ) );
					$plugin_file_budget = max( 0, $plugin_file_budget - count( $plugin_files ) );
					$files              = array_merge( $files, $plugin_files );
				}
			}
		}
		return $files;
	}

	private function wordpress_root_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return trailingslashit( wp_normalize_path( get_home_path() ) );
	}

	private function plugins_directory_path() {
		return trailingslashit( wp_normalize_path( dirname( plugin_dir_path( SHIELD_MONITOR_FILE ) ) ) );
	}

	private function directory_files( $root, $prefix, $limit ) {
		$files = array();
		if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
			return $files;
		}

		$allowed_extensions = array( 'php', 'js', 'css', 'json' );
		$blocked_dirs       = array( 'node_modules', 'vendor', '.git', 'cache', 'uploads', 'dist', 'build' );
		$root               = untrailingslashit( wp_normalize_path( $root ) );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					function ( $current ) use ( $blocked_dirs ) {
						if ( $current->isDir() ) {
							return ! in_array( strtolower( $current->getFilename() ), $blocked_dirs, true );
						}
						return true;
					}
				)
			);
		} catch ( Exception $exception ) {
			return $files;
		}

		foreach ( $iterator as $file ) {
			if ( count( $files ) >= absint( $limit ) ) {
				break;
			}
			if ( ! $file->isFile() || $file->isLink() || $file->getSize() > 1048576 ) {
				continue;
			}
			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, $allowed_extensions, true ) ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( 0 !== strpos( $path, $root . '/' ) ) {
				continue;
			}
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			$files[ sanitize_text_field( trailingslashit( $prefix ) . $relative ) ] = $path;
		}

		return $files;
	}
}
