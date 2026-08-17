<?php
defined( 'ABSPATH' ) || exit;

/**
 * Push site metrics to the ShieldPress API on a cron schedule.
 *
 * Mirrors the VPS Agent pattern: collect metrics locally, POST them
 * to the central API using the license key as Bearer token.
 *
 * SAFETY: collect() is expensive (~2-5s). We cache collected data in a
 * transient for 5 minutes and use a lock to prevent overlapping pushes.
 */
class Shield_Monitor_Push {
	const CRON_HOOK       = 'shield_monitor_push_metrics';
	const API_URL         = 'https://api.shieldpress.net/api/sites/metrics';
	const LAST_PUSH_OPT   = 'shield_monitor_last_push';
	const PUSH_FAIL_OPT   = 'shield_monitor_push_failures';
	const CACHE_TRANSIENT  = 'shield_monitor_push_cache';
	const LOCK_TRANSIENT   = 'shield_monitor_push_lock';
	const CACHE_TTL        = 300;  // 5 minutes
	const LOCK_TTL         = 60;   // 1 minute max lock

	private $status;
	private $logger;
	private $settings;

	public function __construct( $status, $logger, $settings ) {
		$this->status   = $status;
		$this->logger   = $logger;
		$this->settings = $settings;
	}

	public function register_hooks() {
		add_action( self::CRON_HOOK, array( $this, 'push' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	/**
	 * Ensure the cron event is scheduled (every 5 minutes).
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Register the custom cron intervals.
	 */
	public static function add_cron_interval( $schedules ) {
		if ( ! isset( $schedules['every_five_minutes'] ) ) {
			$schedules['every_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes', 'codewp-shield-monitor' ),
			);
		}
		return $schedules;
	}

	/**
	 * Collect and push metrics to the ShieldPress API.
	 *
	 * Uses a lock to prevent overlapping pushes and caches collected
	 * data to avoid running the expensive collect() on every push.
	 */
	public function push() {
		$license_key = get_option( Shield_Monitor_License::OPTION_KEY, '' );
		if ( empty( $license_key ) ) {
			return;
		}

		// Prevent overlapping pushes.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, self::LOCK_TTL );

		try {
			$payload = $this->get_cached_payload();

			$response = wp_remote_post( self::API_URL, array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $license_key,
				),
				'body' => wp_json_encode( $payload ),
			) );

			if ( is_wp_error( $response ) ) {
				$this->record_failure( $response->get_error_message() );
				return;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				update_option( self::LAST_PUSH_OPT, array(
					'time'   => gmdate( 'c' ),
					'status' => 'ok',
				), false );
				delete_option( self::PUSH_FAIL_OPT );
			} else {
				$body = wp_remote_retrieve_body( $response );
				$this->record_failure( sprintf( 'HTTP %d: %s', $code, substr( $body, 0, 200 ) ) );
			}
		} catch ( \Throwable $e ) {
			$this->record_failure( $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Get cached payload or build a new one.
	 *
	 * The expensive collect() call is cached for 5 minutes so that
	 * even if cron fires more frequently, we don't rebuild each time.
	 */
	private function get_cached_payload() {
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			// Update timestamp only.
			$cached['metrics']['timestamp'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			return $cached;
		}

		$data    = $this->safe_collect();
		$payload = $this->build_payload( $data );
		set_transient( self::CACHE_TRANSIENT, $payload, self::CACHE_TTL );
		return $payload;
	}

	/**
	 * Safely call collect() with error handling and timeout protection.
	 */
	private function safe_collect() {
		try {
			return $this->status->collect();
		} catch ( \Throwable $e ) {
			// Return minimal data if collect() fails.
			return array(
				'site' => array(
					'name' => get_bloginfo( 'name' ),
					'url'  => home_url( '/' ),
					'wordpress_version' => get_bloginfo( 'version' ),
					'php_version' => PHP_VERSION,
					'shield_monitor_version' => SHIELD_MONITOR_VERSION,
				),
				'system' => array(
					'cpu' => array(), 'memory' => array(),
					'disk' => array(), 'network' => array(),
				),
				'security' => array(),
				'inventory' => array(),
				'updates' => array(),
				'configuration' => array(),
				'integrations' => array(),
				'health_score' => 0,
				'issues' => array(),
				'backup_alert' => array(),
				'vulnerabilities' => array(),
			);
		}
	}

	/**
	 * Build the push payload matching the VPS Agent structure.
	 */
	private function build_payload( $data ) {
		$system  = $data['system'] ?? array();
		$cpu     = $system['cpu'] ?? array();
		$memory  = $system['memory'] ?? array();
		$disk    = $system['disk'] ?? array();
		$network = $system['network'] ?? array();
		$errors  = $system['_errors'] ?? array();

		return array(
			'shieldpress_version' => SHIELD_MONITOR_VERSION,
			'source'             => 'wordpress',
			'platform'           => $system['platform'] ?? PHP_OS,
			'site'               => $data['site'] ?? array(),
			'metrics'            => array(
				'hostname'        => sanitize_text_field( gethostname() ?: '' ),
				'timestamp'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'uptime_seconds'  => $this->uptime_seconds(),
				'publicIp'        => $network['site_ip'] ?? '',
				'privateIp'       => $network['server_ip'] ?? '',
				'os'              => $this->os_info(),
				'cpu'             => array(
					'cores'    => $cpu['cores'] ?? null,
					'model'    => $this->cpu_model(),
					'load_1m'  => $cpu['load_1m'] ?? 0,
					'load_5m'  => $cpu['load_5m'] ?? 0,
					'load_15m' => $cpu['load_15m'] ?? 0,
				),
				'cpuPercent'      => $cpu['usage_percent_estimate'] ?? 0,
				'memPercent'      => $memory['usage_percent'] ?? 0,
				'memory'          => array(
					'total_mb'     => $this->bytes_to_mb( $memory['total_bytes'] ?? 0 ),
					'used_mb'      => $this->bytes_to_mb( $memory['used_bytes'] ?? 0 ),
					'available_mb' => $this->bytes_to_mb( $memory['free_bytes'] ?? 0 ),
					'swap_total_mb' => 0,
					'swap_used_mb'  => 0,
				),
				'diskPercent'     => $disk['usage_percent'] ?? 0,
				'disk'            => array(
					'path'       => $disk['path'] ?? ABSPATH,
					'total_kb'   => $this->bytes_to_kb( $disk['total_bytes'] ?? 0 ),
					'used_kb'    => $this->bytes_to_kb( $disk['used_bytes'] ?? 0 ),
					'percent'    => $disk['usage_percent'] ?? 0,
					'read_kbps'  => 0,
					'write_kbps' => 0,
				),
				'network'         => array(
					'tcp_connections' => 0,
				),
				'networkRxKbps'   => 0,
				'networkTxKbps'   => 0,
				'services'        => $this->services(),
				'top_processes'   => array(),
				'listening_ports' => array(),
				'docker_containers' => array(),
				'_errors'         => $errors,
			),
			'logins'       => $this->logins( $data ),
			'file_changes' => $this->file_changes( $data ),
			'logs'         => array(
				'nginx_error'  => array(),
				'nginx_access' => array(),
				'mysql'        => array(),
				'syslog'       => array(),
			),
			'wordpress'    => array(
				'inventory'      => $data['inventory'] ?? array(),
				'configuration'  => $data['configuration'] ?? array(),
				'integrations'   => $data['integrations'] ?? array(),
				'updates'        => $data['updates'] ?? array(),
				'security'       => $data['security'] ?? array(),
				'health_score'   => $data['health_score'] ?? 0,
				'issues'         => $data['issues'] ?? array(),
				'backup_alert'   => $data['backup_alert'] ?? array(),
				'vulnerabilities'=> $data['vulnerabilities'] ?? array(),
			),
		);
	}

	private function uptime_seconds() {
		if ( is_readable( '/proc/uptime' ) ) {
			$content = @file_get_contents( '/proc/uptime' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $content ) {
				return (int) floatval( $content );
			}
		}
		return 0;
	}

	private function os_info() {
		$name    = '';
		$version = '';
		if ( is_readable( '/etc/os-release' ) ) {
			$lines = @file( '/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $lines as $line ) {
				if ( 0 === strpos( $line, 'NAME=' ) ) {
					$name = trim( str_replace( array( 'NAME=', '"' ), '', $line ) );
				}
				if ( 0 === strpos( $line, 'VERSION_ID=' ) ) {
					$version = trim( str_replace( array( 'VERSION_ID=', '"' ), '', $line ) );
				}
			}
		}
		return array(
			'name'    => $name ?: PHP_OS,
			'version' => $version,
			'kernel'  => php_uname( 'r' ),
			'arch'    => php_uname( 'm' ),
		);
	}

	private function cpu_model() {
		if ( is_readable( '/proc/cpuinfo' ) ) {
			$lines = @file( '/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $lines as $line ) {
				if ( 0 === strpos( $line, 'model name' ) ) {
					return trim( substr( $line, strpos( $line, ':' ) + 1 ) );
				}
			}
		}
		return '';
	}

	private function services() {
		$services = array(
			'nginx'      => 'stopped',
			'apache'     => 'stopped',
			'mysql'      => 'stopped',
			'postgresql' => 'stopped',
			'redis'      => 'stopped',
			'docker'     => 'stopped',
			'sshd'       => 'stopped',
			'php_fpm'    => array(),
		);

		if ( 'Linux' !== PHP_OS_FAMILY || ! function_exists( 'shell_exec' ) ) {
			return $services;
		}

		$check = function ( $name ) {
			$result = @shell_exec( 'systemctl is-active ' . escapeshellarg( $name ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
			return 'running' === trim( (string) $result ) ? 'running' : 'stopped';
		};

		$services['nginx'] = $check( 'nginx' );
		$services['apache'] = 'running' === $check( 'apache2' ) ? 'running' : $check( 'httpd' );
		$services['mysql'] = 'running' === $check( 'mysql' ) ? 'running' : ( 'running' === $check( 'mysqld' ) ? 'running' : $check( 'mariadb' ) );
		$services['postgresql'] = $check( 'postgresql' );
		$services['redis'] = 'running' === $check( 'redis-server' ) ? 'running' : $check( 'redis' );
		$services['docker'] = $check( 'docker' );
		$services['sshd'] = $check( 'sshd' );

		$php_items = array();
		foreach ( array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4' ) as $ver ) {
			$status = $check( 'php' . $ver . '-fpm' );
			if ( 'running' === $status ) {
				$php_items[] = array( 'version' => $ver, 'status' => 'running' );
			}
		}
		if ( ! empty( $php_items ) ) {
			$services['php_fpm'] = $php_items;
		}

		return $services;
	}

	private function logins( $data ) {
		$failed_logins = array();
		try {
			$events = $this->logger->recent_matching( array( 'login_failed', 'login_lockout' ), 10 );
			foreach ( $events as $event ) {
				$details = is_string( $event->details ) ? json_decode( $event->details, true ) : (array) $event->details;
				$failed_logins[] = array(
					'user' => sanitize_text_field( $details['username'] ?? '' ),
					'ip'   => '',
					'time' => sanitize_text_field( $event->created_at ?? '' ),
				);
			}
		} catch ( \Throwable $e ) {
			// Ignore — logins data is non-critical.
		}

		return array(
			'failed_logins' => $failed_logins,
			'current_users' => array(),
			'recent_logins' => array(),
		);
	}

	private function file_changes( $data ) {
		$security = $data['security'] ?? array();
		$changes  = array();
		$recent   = $security['recent_file_changes_30_days'] ?? array();
		foreach ( array_slice( (array) $recent, 0, 20 ) as $change ) {
			$path = sanitize_text_field( $change['file_path'] ?? ( $change['path'] ?? '' ) );
			$ext  = pathinfo( $path, PATHINFO_EXTENSION );
			$changes[] = array(
				'path' => $path,
				'ext'  => $ext,
				'type' => sanitize_key( $change['event_type'] ?? ( $change['type'] ?? 'modified' ) ),
			);
		}
		return $changes;
	}

	private function bytes_to_mb( $bytes ) {
		return $bytes ? (int) round( $bytes / 1048576 ) : 0;
	}

	private function bytes_to_kb( $bytes ) {
		return $bytes ? (int) round( $bytes / 1024 ) : 0;
	}

	private function record_failure( $message ) {
		$failures = absint( get_option( self::PUSH_FAIL_OPT, 0 ) );
		update_option( self::PUSH_FAIL_OPT, $failures + 1, false );
		update_option( self::LAST_PUSH_OPT, array(
			'time'    => gmdate( 'c' ),
			'status'  => 'error',
			'message' => sanitize_text_field( substr( $message, 0, 200 ) ),
		), false );
	}
}
