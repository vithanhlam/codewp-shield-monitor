<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_File_Monitor {
	const HASHES_OPTION = 'shield_monitor_file_hashes';
	const CRON_HOOK     = 'shield_monitor_file_integrity_scan';
	const CORE_OPTION   = 'shield_monitor_core_checksum_status';
	const SCOPE_OPTION  = 'shield_monitor_file_scope_version';
	const SCOPE_VERSION = '3';
	const DB_VERSION    = '1.0';

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'safe_scan' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( 'admin_init', array( $this, 'maybe_scan_when_cron_overdue' ) );
		add_action( 'admin_post_shield_monitor_trust_file_baseline', array( $this, 'trust_current_baseline' ) );
	}

	public function cron_schedule( $schedules ) {
		$schedules['shield_monitor_offpeak_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once daily (off-peak)', 'codewp-shield-monitor' ),
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
		// Only check table on admin requests to avoid overhead on every page load.
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			$this->maybe_create_baseline_table();
		}
		$enabled = $this->settings->get( 'file_monitoring' ) || $this->settings->get( 'core_checksum_verification' );
		if ( $enabled && ( ! wp_next_scheduled( self::CRON_HOOK ) || 'shield_monitor_offpeak_daily' !== wp_get_schedule( self::CRON_HOOK ) ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( $this->next_offpeak_scan(), 'shield_monitor_offpeak_daily', self::CRON_HOOK );
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
		set_transient( 'shield_monitor_file_admin_scan_lock', 1, 30 * MINUTE_IN_SECONDS );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
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

	/**
	 * Cron-safe wrapper: catch fatal errors so they never break page loads.
	 */
	public function safe_scan() {
		$hour = (int) current_datetime()->format( 'G' );
		if ( $hour < 1 || $hour >= 6 ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK, array( 'deferred' ) ) ) {
				wp_schedule_single_event( $this->next_offpeak_scan(), self::CRON_HOOK, array( 'deferred' ) );
			}
			return;
		}
		try {
			$this->scan();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'ShieldPress file scan error: ' . $e->getMessage() );
			}
		}
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
			'coverage'         => get_option( 'shield_monitor_file_scope_report', array( 'status' => 'not_scanned' ) ),
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
			return time() - strtotime( $last ) > 26 * HOUR_IN_SECONDS;
		}
		if ( get_option( 'shield_monitor_file_baseline_created', '' ) ) {
			return true;
		}
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	private function next_offpeak_scan() {
		$timezone = wp_timezone();
		$next = new DateTimeImmutable( 'today 04:15', $timezone );
		if ( $next->getTimestamp() <= time() + MINUTE_IN_SECONDS ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
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
		$rows  = $wpdb->get_results( "SELECT file_path, file_size, sha256_hash, status FROM {$table}", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = is_array( $rows ) ? $rows : array();

		if ( empty( $rows ) ) {
			foreach ( $snapshot as $path => $meta ) {
				$this->insert_baseline_row( $path, $meta, 'normal', $now );
			}
			return array();
		}

		$changes       = array();
		$unchanged_ids = array();
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
			$changed  = ! empty( $meta['exists'] ) && ( ! hash_equals( $old_hash, $new_hash ) || (int) $row->file_size !== (int) ( $meta['size'] ?? 0 ) );

			if ( $changed ) {
				$this->update_baseline_row( $path, $meta, 'modified', $now, true );
				if ( ! $create_only ) {
					$changes[] = $this->record_file_change( $path, 'modified', $meta, $row );
				}
			} else {
				$unchanged_ids[] = $wpdb->prepare( '%s', $path );
			}
			unset( $rows[ $path ] );
		}

		if ( ! empty( $unchanged_ids ) ) {
			foreach ( array_chunk( $unchanged_ids, 500 ) as $chunk ) {
				$in = implode( ',', $chunk );
				$wpdb->query( "UPDATE {$table} SET status = 'normal', last_seen_at = '{$now}', updated_at = '{$now}' WHERE file_path IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		if ( ! $create_only ) {
			$delete_paths = array();
			foreach ( $rows as $path => $row ) {
				if ( 'deleted' === (string) $row->status ) {
					continue;
				}
				$delete_paths[] = $path;
				$changes[]      = $this->record_file_change( $path, 'deleted', array(), $row );
			}
			if ( ! empty( $delete_paths ) ) {
				foreach ( array_chunk( $delete_paths, 500 ) as $chunk ) {
					$in = implode( ',', array_map( function ( $p ) use ( $wpdb ) { return $wpdb->prepare( '%s', $p ); }, $chunk ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'deleted', last_changed_at = %s, updated_at = %s WHERE file_path IN ({$in})", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				}
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
		if ( 'modified' === $change && ( 0 === strpos( $path, 'plugin/' ) || 0 === strpos( $path, 'active-plugin/' ) || 0 === strpos( $path, 'active-theme/' ) || 0 === strpos( $path, 'parent-theme/' ) || 0 === strpos( $path, 'mu-plugin/' ) ) ) {
			return 'high';
		}
		return 'deleted' === $change ? 'medium' : 'low';
	}

	private function baseline_table() {
		global $wpdb;
		return $wpdb->prefix . 'shield_monitor_file_baseline';
	}

	private function file_group( $path ) {
		if ( 0 === strpos( $path, 'plugin/' ) || 0 === strpos( $path, 'active-plugin/' ) || 0 === strpos( $path, 'mu-plugin/' ) ) {
			return 'plugin';
		}
		if ( 0 === strpos( $path, 'active-theme/' ) || 0 === strpos( $path, 'parent-theme/' ) ) {
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
			$sha256   = $readable ? hash_file( 'sha256', $absolute ) : '';
			$snapshot[ $relative ] = array(
				'exists'   => $exists,
				'hash'     => $sha256,
				'sha256'   => $sha256,
				'md5'      => $readable ? md5_file( $absolute ) : '',
				'size'     => $exists ? (int) filesize( $absolute ) : 0,
				'group'    => $this->file_group( $relative ),
				'writable' => $exists ? is_writable( $absolute ) : false,
				'readable' => $readable,
			);
		}
		if ( $record_scan ) {
			$report = get_option( 'shield_monitor_file_scope_report', array() );
			$report['files_hashed'] = count( $snapshot );
			$report['status'] = ! empty( $report['truncated'] ) ? 'partial' : 'complete';
			$report['scanned_at'] = gmdate( 'c' );
			update_option( 'shield_monitor_file_scope_report', $report, false );
			update_option( 'shield_monitor_file_last_scan', gmdate( 'c' ), false );
		}
		return $snapshot;
	}

	private function critical_files() {
		$files = array();
		$skipped = array();
		$limits = array();
		$root  = $this->wordpress_root_path();
		foreach ( array( '.htaccess', '.user.ini', 'index.php', 'wp-config.php', 'wp-load.php', 'wp-settings.php', 'wp-login.php', 'wp-cron.php', 'wp-blog-header.php', 'xmlrpc.php', 'wp-admin/index.php', 'wp-includes/version.php' ) as $relative ) {
			$files[ $relative ] = $root . $relative;
		}
		$parent_config = dirname( untrailingslashit( $root ) ) . '/wp-config.php';
		if ( ! is_file( $files['wp-config.php'] ) && is_file( $parent_config ) ) {
			$files['../wp-config.php'] = $parent_config;
			unset( $files['wp-config.php'] );
		}
		$stylesheet = get_stylesheet_directory();
		$themes = array_unique( array_filter( array( get_template_directory(), $stylesheet ) ) );
		foreach ( $themes as $theme_dir ) {
			$theme_label = $theme_dir === $stylesheet ? 'active-theme/' : 'parent-theme/';
			foreach ( array( 'functions.php', 'style.css' ) as $name ) {
				$files[ $theme_label . $name ] = trailingslashit( $theme_dir ) . $name;
			}
			$theme_files = $this->directory_files( $theme_dir, $theme_label . basename( $theme_dir ), 1500, $skipped );
			$files = array_merge( $files, $theme_files );
		}
		$plugins = array();
		$plugin_file_budget = 6000;
		$plugins_dir        = $this->plugins_directory_path();
		foreach ( glob( trailingslashit( $plugins_dir ) . '*.php' ) ?: array() as $plugin_file ) { $plugins[] = basename( $plugin_file ); }
		foreach ( glob( trailingslashit( $plugins_dir ) . '*', GLOB_ONLYDIR ) ?: array() as $plugin_dir ) {
			foreach ( glob( trailingslashit( $plugin_dir ) . '*.php' ) ?: array() as $plugin_file ) { $plugins[] = basename( $plugin_dir ) . '/' . basename( $plugin_file ); }
		}
		foreach ( array_unique( $plugins ) as $plugin ) {
			$files[ 'plugin/' . $plugin ] = $plugins_dir . $plugin;
			$plugin_dir = $plugins_dir . dirname( $plugin );
			if ( $plugin_file_budget > 0 && '.' !== dirname( $plugin ) && is_dir( $plugin_dir ) ) {
				$plugin_files = $this->directory_files( $plugin_dir, 'plugin/' . dirname( $plugin ), min( 500, $plugin_file_budget ), $skipped );
				$plugin_file_budget = max( 0, $plugin_file_budget - count( $plugin_files ) );
				$files = array_merge( $files, $plugin_files );
			}
		}
		$mu_dir = trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins';
		$files = array_merge( $files, $this->directory_files( $mu_dir, 'mu-plugin', 1000, $skipped ) );
		$dropins = array( 'advanced-cache.php', 'db.php', 'object-cache.php', 'sunrise.php', 'maintenance.php' );
		foreach ( $dropins as $dropin ) { $path = trailingslashit( WP_CONTENT_DIR ) . $dropin; if ( is_file( $path ) ) { $files[ 'drop-in/' . $dropin ] = $path; } }
		$upload_dir = wp_upload_dir();
		$files = array_merge( $files, $this->directory_files( $upload_dir['basedir'] ?? '', 'uploads', 2500, $skipped ) );
		$report = array( 'status' => $skipped ? 'partial' : 'complete', 'scope' => array( 'core_config', 'active_and_inactive_plugins', 'active_and_parent_themes', 'mu_plugins', 'drop_ins', 'uploads', 'html_verification_files' ), 'files_discovered' => count( $files ), 'files_hashed' => 0, 'skipped_by_reason' => $skipped, 'limits' => $limits, 'truncated' => ! empty( $skipped['file_limit'] ), 'scanned_at' => null );
		update_option( 'shield_monitor_file_scope_report', $report, false );
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

	private function directory_files( $root, $prefix, $limit, &$skipped = null ) {
		$files = array();
		if ( ! $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			if ( is_array( $skipped ) ) { $skipped['unavailable_directory'] = ( $skipped['unavailable_directory'] ?? 0 ) + 1; }
			return $files;
		}

		$allowed_extensions = array( 'php', 'js', 'css', 'json', 'html', 'htm', 'ini', 'conf', 'txt', 'xml' );
		$blocked_dirs       = array( 'node_modules', '.git' );
		$root               = untrailingslashit( wp_normalize_path( $root ) );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					function ( $current ) use ( $blocked_dirs, &$skipped ) {
						if ( $current->isDir() ) {
							$allowed = ! in_array( strtolower( $current->getFilename() ), $blocked_dirs, true );
							if ( ! $allowed && is_array( $skipped ) ) { $skipped['excluded_directory'] = ( $skipped['excluded_directory'] ?? 0 ) + 1; }
							return $allowed;
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
				if ( is_array( $skipped ) ) { $key = $file->isLink() ? 'symlink' : 'file_too_large_or_unreadable'; $skipped[$key] = ( $skipped[$key] ?? 0 ) + 1; }
				continue;
			}
			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, $allowed_extensions, true ) ) {
				if ( is_array( $skipped ) ) { $skipped['unsupported_extension'] = ( $skipped['unsupported_extension'] ?? 0 ) + 1; }
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( 0 !== strpos( $path, $root . '/' ) ) {
				continue;
			}
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			$files[ sanitize_text_field( trailingslashit( $prefix ) . $relative ) ] = $path;
			if ( count( $files ) >= absint( $limit ) && is_array( $skipped ) ) { $skipped['file_limit'] = ( $skipped['file_limit'] ?? 0 ) + 1; }
		}

		return $files;
	}
}
