<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Threat_Intelligence {
	const CACHE_KEY = 'shield_monitor_threat_intelligence';

	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function status() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$findings = array_merge(
			$this->admin_anomalies(),
			$this->plugin_theme_risks(),
			$this->uploads_php_guard(),
			$this->option_cron_mu_plugin_scan(),
			$this->fake_captcha_clickfix_scan(),
			$this->external_script_scan(),
			$this->seo_cloaking_check(),
			$this->file_integrity_diff(),
			$this->security_baseline_hardening()
		);

		$findings = array_slice( $findings, 0, 150 );
		$summary  = $this->summarize( $findings );
		$status   = array(
			'schema_version' => '1.0',
			'generated_at'   => gmdate( 'c' ),
			'summary'        => $summary,
			'findings'       => $findings,
			'incident_report'=> $this->incident_report( $summary, $findings ),
			'notes'          => array(
				__( 'Threat findings are review signals and do not automatically prove malware.', 'codewp-shield-monitor' ),
				__( 'No destructive action is taken automatically.', 'codewp-shield-monitor' ),
				__( 'Public endpoints do not expose these diagnostics.', 'codewp-shield-monitor' ),
			),
		);

		set_transient( self::CACHE_KEY, $status, 15 * MINUTE_IN_SECONDS );
		return $status;
	}

	private function admin_anomalies() {
		$findings = array();
		$admins   = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'ID', 'user_login', 'user_email', 'user_registered' ),
				'number' => 50,
			)
		);
		$suspicious_names = array( 'admin1', 'backup', 'support', 'test', 'wpservice', 'wp_update', 'system', 'wordpress', 'demo' );

		if ( count( $admins ) > 3 ) {
			$findings[] = $this->finding( 'admin_anomaly', 'medium', __( 'Multiple administrator accounts detected.', 'codewp-shield-monitor' ), sprintf( __( '%d administrator accounts exist on this site.', 'codewp-shield-monitor' ), count( $admins ) ), 'users' );
		}

		foreach ( $admins as $admin ) {
			$login = strtolower( (string) $admin->user_login );
			if ( in_array( $login, $suspicious_names, true ) || preg_match( '/^(wp|wordpress|admin|support)[-_]?\d+$/', $login ) ) {
				$findings[] = $this->finding( 'admin_anomaly', 'high', __( 'Suspicious administrator username.', 'codewp-shield-monitor' ), $admin->user_login, 'users' );
			}
			if ( strtotime( (string) $admin->user_registered ) > time() - ( 14 * DAY_IN_SECONDS ) ) {
				$findings[] = $this->finding( 'admin_anomaly', 'high', __( 'Recently created administrator account.', 'codewp-shield-monitor' ), $admin->user_login, 'users' );
			}
		}

		$recent_admins = $this->logger->count_matching( array( 'admin_user_created', 'role_changed' ), 14 * DAY_IN_SECONDS );
		if ( $recent_admins ) {
			$findings[] = $this->finding( 'admin_anomaly', 'high', __( 'Recent administrator access changes.', 'codewp-shield-monitor' ), sprintf( __( '%d administrator access events in the last 14 days.', 'codewp-shield-monitor' ), $recent_admins ), 'audit_log' );
		}

		return $findings;
	}

	private function plugin_theme_risks() {
		$findings = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$active  = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
		$plugins_dir = trailingslashit( wp_normalize_path( dirname( plugin_dir_path( SHIELD_MONITOR_FILE ) ) ) );
		foreach ( $plugins as $file => $data ) {
			if ( ! in_array( $file, $active, true ) ) {
				$path = $plugins_dir . $file;
				if ( is_file( $path ) ) {
					$findings[] = $this->finding( 'plugin_theme_risk', 'low', __( 'Inactive plugin code remains installed.', 'codewp-shield-monitor' ), sanitize_text_field( $data['Name'] ?? $file ), $file );
				}
			}
			if ( empty( $data['Version'] ) || '0.0.0' === (string) $data['Version'] ) {
				$findings[] = $this->finding( 'plugin_theme_risk', 'medium', __( 'Plugin has missing or unusual version metadata.', 'codewp-shield-monitor' ), sanitize_text_field( $data['Name'] ?? $file ), $file );
			}
		}

		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( $slug !== get_stylesheet() && $slug !== get_template() && empty( $theme->get( 'Version' ) ) ) {
				$findings[] = $this->finding( 'plugin_theme_risk', 'medium', __( 'Inactive theme has missing version metadata.', 'codewp-shield-monitor' ), $theme->get( 'Name' ), $slug );
			}
		}

		return array_slice( $findings, 0, 30 );
	}

	private function uploads_php_guard() {
		$upload = wp_upload_dir();
		$root   = $upload['basedir'] ?? '';
		if ( ! $root || ! is_dir( $root ) ) {
			return array();
		}
		$findings = array();
		$iterator = $this->iterator( $root );
		$count    = 0;
		foreach ( $iterator as $file ) {
			if ( ++$count > 1200 || count( $findings ) >= 30 ) {
				break;
			}
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			$name = strtolower( $file->getFilename() );
			if ( preg_match( '/\.(php|phtml|phar|php\d)$/', $name ) || preg_match( '/\.(jpg|jpeg|png|gif|ico)\.php$/', $name ) ) {
				$findings[] = $this->finding( 'uploads_php_guard', 'critical', __( 'Executable file found in uploads.', 'codewp-shield-monitor' ), $this->relative_path( $file->getPathname() ), $this->relative_path( $file->getPathname() ) );
			}
		}
		return $findings;
	}

	private function option_cron_mu_plugin_scan() {
		global $wpdb;
		$findings = array();
		$patterns = $this->suspicious_patterns();
		$options  = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on', 'auto') AND option_value <> '' ORDER BY option_id DESC LIMIT 80" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $options as $option ) {
			$value = maybe_unserialize( $option->option_value );
			$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern['regex'], (string) $value, $match ) ) {
					$findings[] = $this->finding( 'option_cron_mu_scan', $pattern['severity'], sprintf( __( 'Suspicious option pattern: %s', 'codewp-shield-monitor' ), $pattern['label'] ), $match[0] ?? '', 'option:' . sanitize_key( $option->option_name ) );
					break;
				}
			}
		}

		$cron = _get_cron_array();
		foreach ( (array) $cron as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( preg_match( '/(eval|base64|shell|curl|wget|tmp|backdoor|inject)/i', (string) $hook ) ) {
					$findings[] = $this->finding( 'option_cron_mu_scan', 'high', __( 'Suspicious cron hook name.', 'codewp-shield-monitor' ), sanitize_key( $hook ), 'wp_cron' );
				}
			}
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
			foreach ( glob( trailingslashit( WPMU_PLUGIN_DIR ) . '*.php' ) ?: array() as $file ) {
				$content = is_readable( $file ) ? file_get_contents( $file, false, null, 0, 65536 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				foreach ( $patterns as $pattern ) {
					if ( is_string( $content ) && preg_match( $pattern['regex'], $content, $match ) ) {
						$findings[] = $this->finding( 'option_cron_mu_scan', $pattern['severity'], sprintf( __( 'Suspicious MU plugin pattern: %s', 'codewp-shield-monitor' ), $pattern['label'] ), $match[0] ?? '', $this->relative_path( $file ) );
						break;
					}
				}
			}
		}

		return array_slice( $findings, 0, 40 );
	}

	private function fake_captcha_clickfix_scan() {
		global $wpdb;
		$findings = array();
		$regex    = '/(win\s*\+\s*r|powershell|copy\s+and\s+paste|clipboard|verify\s+you\s+are\s+human|cloudflare\s+verification|captcha\s+verification|clickfix)/i';
		$rows     = $wpdb->get_results( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_content <> '' ORDER BY post_modified_gmt DESC LIMIT 80" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			if ( preg_match( $regex, (string) $row->post_content, $match ) ) {
				$findings[] = $this->finding( 'fake_captcha_clickfix', 'high', __( 'Possible fake CAPTCHA or ClickFix content.', 'codewp-shield-monitor' ), $match[0] ?? '', 'post:' . absint( $row->ID ) . ' ' . sanitize_text_field( $row->post_title ) );
			}
		}
		return array_slice( $findings, 0, 20 );
	}

	private function external_script_scan() {
		global $wpdb;
		$findings = array();
		$current  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$rows     = $wpdb->get_results( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE '%<script%' ORDER BY post_modified_gmt DESC LIMIT 80" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			if ( ! preg_match_all( '/<script\b[^>]+src=["\']?(https?:\/\/[^"\' >]+)/i', (string) $row->post_content, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $url ) {
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( $host && ! $this->same_host( $host, $current ) ) {
					$findings[] = $this->finding( 'external_script_detected', 'medium', __( 'External script domain detected in public content.', 'codewp-shield-monitor' ), $host, 'post:' . absint( $row->ID ) );
				}
			}
		}
		return array_slice( $findings, 0, 25 );
	}

	private function seo_cloaking_check() {
		$cached = get_transient( 'shield_monitor_cloaking_check' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$findings = array();
		$normal   = $this->fetch_home_snippet( 'Mozilla/5.0 CodeWP Shield Monitor' );
		$bot      = $this->fetch_home_snippet( 'Googlebot/2.1 (+http://www.google.com/bot.html)' );
		if ( $normal && $bot ) {
			$normal_links = $this->external_link_count( $normal );
			$bot_links    = $this->external_link_count( $bot );
			if ( $bot_links > $normal_links + 5 ) {
				$findings[] = $this->finding( 'seo_cloaking', 'high', __( 'Search-engine response contains many more external links than normal response.', 'codewp-shield-monitor' ), sprintf( '%d vs %d', $bot_links, $normal_links ), home_url( '/' ) );
			}
			if ( false === stripos( $normal, '<script' ) && false !== stripos( $bot, '<script' ) ) {
				$findings[] = $this->finding( 'seo_cloaking', 'medium', __( 'Search-engine response includes scripts not seen in normal response.', 'codewp-shield-monitor' ), 'script difference', home_url( '/' ) );
			}
		}
		set_transient( 'shield_monitor_cloaking_check', $findings, 12 * HOUR_IN_SECONDS );
		return $findings;
	}

	private function file_integrity_diff() {
		$result = get_option( 'shield_monitor_file_last_result', array() );
		$changes = isset( $result['changes_detail'] ) && is_array( $result['changes_detail'] ) ? $result['changes_detail'] : array();
		$findings = array();
		foreach ( array_slice( $changes, 0, 25 ) as $change ) {
			$path = sanitize_text_field( $change['path'] ?? '' );
			if ( preg_match( '#uploads/.+\.(php|phtml|phar)$#i', $path ) ) {
				$findings[] = $this->finding( 'file_integrity_diff', 'critical', __( 'Executable upload changed.', 'codewp-shield-monitor' ), $path, $path );
			} elseif ( preg_match( '#(plugins|themes)/.+\.(php|js)$#i', $path ) ) {
				$findings[] = $this->finding( 'file_integrity_diff', 'medium', __( 'Plugin or theme executable changed.', 'codewp-shield-monitor' ), $path, $path );
			}
		}
		return $findings;
	}

	private function security_baseline_hardening() {
		$findings = array();
		if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			$findings[] = $this->finding( 'security_baseline', 'medium', __( 'Debug display is enabled.', 'codewp-shield-monitor' ), 'WP_DEBUG_DISPLAY', 'wp-config.php' );
		}
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT ) {
			$findings[] = $this->finding( 'security_baseline', 'medium', __( 'Dashboard file editor is not disabled.', 'codewp-shield-monitor' ), 'DISALLOW_FILE_EDIT', 'wp-config.php' );
		}
		if ( function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available() ) {
			$findings[] = $this->finding( 'security_baseline', 'low', __( 'Application passwords are available.', 'codewp-shield-monitor' ), 'application_passwords', 'users' );
		}
		$root = $this->wordpress_root_path();
		foreach ( array( '.env', 'backup.zip', 'database.sql', 'db.sql', 'site.zip' ) as $name ) {
			if ( is_file( $root . $name ) ) {
				$findings[] = $this->finding( 'security_baseline', 'high', __( 'Potential sensitive file exists in the web root.', 'codewp-shield-monitor' ), $name, $name );
			}
		}
		return $findings;
	}

	private function fetch_home_snippet( $user_agent ) {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'    => 3,
				'redirection'=> 2,
				'user-agent' => $user_agent,
				'sslverify'  => true,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return substr( (string) wp_remote_retrieve_body( $response ), 0, 200000 );
	}

	private function external_link_count( $html ) {
		$count = 0;
		$current = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( preg_match_all( '/href=["\'](https?:\/\/[^"\']+)/i', (string) $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( $host && ! $this->same_host( $host, $current ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	private function suspicious_patterns() {
		return array(
			array( 'severity' => 'high', 'label' => 'encoded execution', 'regex' => '/(eval|assert)\s*\(.*(base64_decode|gzinflate|str_rot13|chr)\s*\(/is' ),
			array( 'severity' => 'high', 'label' => 'remote script loader', 'regex' => '/(curl_exec|file_get_contents|wp_remote_get)\s*\(.*https?:\/\//is' ),
			array( 'severity' => 'medium', 'label' => 'long encoded payload', 'regex' => '/[A-Za-z0-9+\/]{260,}={0,2}/' ),
			array( 'severity' => 'medium', 'label' => 'hidden iframe', 'regex' => '/<iframe\b[^>]*(display\s*:\s*none|visibility\s*:\s*hidden|width=["\']?0|height=["\']?0)/is' ),
		);
	}

	private function summarize( $findings ) {
		$summary = array( 'total' => count( $findings ), 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'risk_level' => 'clean' );
		foreach ( $findings as $finding ) {
			$key = sanitize_key( $finding['severity'] ?? 'low' );
			if ( isset( $summary[ $key ] ) ) {
				++$summary[ $key ];
			}
		}
		$summary['risk_level'] = $summary['critical'] ? 'critical' : ( $summary['high'] ? 'high' : ( $summary['medium'] ? 'medium' : ( $summary['low'] ? 'low' : 'clean' ) ) );
		return $summary;
	}

	private function incident_report( $summary, $findings ) {
		return array(
			'generated_at' => gmdate( 'c' ),
			'summary'      => $summary,
			'top_findings' => array_slice( $findings, 0, 20 ),
			'next_steps'   => array(
				__( 'Review critical and high findings first.', 'codewp-shield-monitor' ),
				__( 'Confirm recent administrator changes with the site owner.', 'codewp-shield-monitor' ),
				__( 'Back up the site before removing or quarantining any file.', 'codewp-shield-monitor' ),
				__( 'Rotate administrator passwords and API credentials after cleanup.', 'codewp-shield-monitor' ),
			),
		);
	}

	private function finding( $type, $severity, $title, $evidence, $location ) {
		return array(
			'type'      => sanitize_key( $type ),
			'severity'  => sanitize_key( $severity ),
			'title'     => sanitize_text_field( $title ),
			'evidence'  => sanitize_text_field( wp_strip_all_tags( substr( (string) $evidence, 0, 180 ) ) ),
			'location'  => sanitize_text_field( $location ),
		);
	}

	private function iterator( $root ) {
		try {
			return new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		} catch ( Exception $exception ) {
			return new EmptyIterator();
		}
	}

	private function relative_path( $path ) {
		$path = wp_normalize_path( $path );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			$root = trailingslashit( wp_normalize_path( $root ) );
			if ( 0 === strpos( $path, $root ) ) {
				return ltrim( substr( $path, strlen( $root ) ), '/' );
			}
		}
		return basename( $path );
	}

	private function same_host( $host, $current ) {
		return $this->base_host( $host ) === $this->base_host( $current );
	}

	private function base_host( $host ) {
		return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
	}

	private function wordpress_root_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return trailingslashit( wp_normalize_path( get_home_path() ) );
	}
}
