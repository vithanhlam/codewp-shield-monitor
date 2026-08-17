<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Security_Dashboard {
	private $settings;
	private $logger;
	private $status;
	private $api;

	public function __construct( $settings, $logger, $status, $api ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->status   = $status;
		$this->api      = $api;
	}

	private function checkbox( $key, $label, $description ) {
		?><label class="shield_monitor-switch-row"><span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $description ); ?></small></span><input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="0"><input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $this->settings->get( $key ), 1 ); ?>><i aria-hidden="true"></i></label><?php
	}

	private function notification_post_type_options() {
		$selected = array_map( 'sanitize_key', (array) $this->settings->get( 'notify_post_types' ) );
		$blocked  = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'shop_order', 'shop_coupon' );
		$types    = get_post_types( array( 'show_ui' => true ), 'objects' );
		foreach ( $types as $type => $object ) {
			$type = sanitize_key( $type );
			if ( in_array( $type, $blocked, true ) ) {
				continue;
			}
			?><label class="shield_monitor-switch-row sp-compact"><span><strong><?php echo esc_html( $object->labels->singular_name ?? $object->label ?? $type ); ?></strong><small><?php echo esc_html( $type ); ?></small></span><input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[notify_post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $selected, true ) ); ?>><i aria-hidden="true"></i></label><?php
		}
	}

	private function staff_pin_role_options() {
		$selected = array_map( 'sanitize_key', (array) $this->settings->get( 'admin_pin_roles' ) );
		foreach ( get_editable_roles() as $role => $details ) {
			$role = sanitize_key( $role );
			if ( 'customer' === $role ) {
				continue;
			}
			$name = translate_user_role( $details['name'] ?? $role );
			?><label class="shield_monitor-switch-row sp-compact"><span><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $role ); ?></small></span><input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[admin_pin_roles][]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $selected, true ) ); ?>><i aria-hidden="true"></i></label><?php
		}
	}

	private function captcha_form_options() {
		$selected = array_map( 'sanitize_key', (array) $this->settings->get( 'captcha_forms' ) );
		$options  = array(
			'login'    => array( __( 'Login forms', 'codewp-shield-monitor' ), __( 'WordPress login, WooCommerce login, and theme login forms.', 'codewp-shield-monitor' ) ),
			'register' => array( __( 'Registration forms', 'codewp-shield-monitor' ), __( 'WordPress and WooCommerce account registration forms.', 'codewp-shield-monitor' ) ),
			'checkout' => array( __( 'WooCommerce checkout', 'codewp-shield-monitor' ), __( 'Require a challenge before placing an order.', 'codewp-shield-monitor' ) ),
			'comment'  => array( __( 'Comment forms', 'codewp-shield-monitor' ), __( 'WordPress comment forms on posts and pages.', 'codewp-shield-monitor' ) ),
		);
		foreach ( $options as $key => $option ) {
			?><label class="shield_monitor-switch-row sp-compact"><span><strong><?php echo esc_html( $option[0] ); ?></strong><small><?php echo esc_html( $option[1] ); ?></small></span><input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_forms][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>><i aria-hidden="true"></i></label><?php
		}
	}

	private function admin_user_options( $selected_user_id ) {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'user_login', 'display_name', 'user_email' ),
			)
		);
		foreach ( $admins as $admin ) {
			$label = sprintf( '%s (%s)', $admin->display_name ?: $admin->user_login, $admin->user_login );
			?><option value="<?php echo esc_attr( $admin->ID ); ?>" <?php selected( absint( $selected_user_id ), absint( $admin->ID ) ); ?>><?php echo esc_html( $label ); ?></option><?php
		}
	}

	public function collect_system_info_public() {
		return $this->collect_system_info();
	}

	private function collect_system_info() {
		global $wpdb;

		$info = array();

		// Site identity.
		$info['site'] = array(
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url(),
			'admin_ip' => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'N/A',
			'user_ip'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'N/A',
		);

		// WordPress.
		$info['wordpress'] = array(
			'version'          => get_bloginfo( 'version' ),
			'site_url'         => site_url(),
			'home_url'         => home_url(),
			'multisite'        => is_multisite(),
			'permalink'        => get_option( 'permalink_structure' ) ?: 'Plain',
			'language'         => get_locale(),
			'timezone'         => wp_timezone_string(),
			'debug_mode'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'default',
			'max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'default',
		);

		// Server.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
		$server_type     = 'Unknown';
		$sw_lower        = strtolower( $server_software );
		if ( false !== strpos( $sw_lower, 'nginx' ) ) {
			$server_type = 'Nginx';
		} elseif ( false !== strpos( $sw_lower, 'apache' ) ) {
			$server_type = 'Apache';
		} elseif ( false !== strpos( $sw_lower, 'litespeed' ) ) {
			$server_type = 'LiteSpeed';
		} elseif ( false !== strpos( $sw_lower, 'iis' ) ) {
			$server_type = 'IIS';
		}

		$info['server'] = array(
			'software'    => $server_software,
			'type'        => $server_type,
			'php_version' => phpversion(),
			'php_sapi'    => php_sapi_name(),
			'os'          => PHP_OS,
		);

		// PHP configuration.
		$info['php'] = array(
			'version'               => phpversion(),
			'memory_limit'          => ini_get( 'memory_limit' ),
			'max_execution_time'    => ini_get( 'max_execution_time' ),
			'max_input_time'        => ini_get( 'max_input_time' ),
			'max_input_vars'        => ini_get( 'max_input_vars' ),
			'upload_max_filesize'   => ini_get( 'upload_max_filesize' ),
			'post_max_size'         => ini_get( 'post_max_size' ),
			'allow_url_fopen'       => ini_get( 'allow_url_fopen' ) ? 'On' : 'Off',
			'display_errors'        => ini_get( 'display_errors' ) ? 'On' : 'Off',
			'file_uploads'          => ini_get( 'file_uploads' ) ? 'On' : 'Off',
			'default_charset'       => ini_get( 'default_charset' ),
			'mbstring'              => extension_loaded( 'mbstring' ),
			'curl'                  => extension_loaded( 'curl' ),
			'curl_version'          => function_exists( 'curl_version' ) ? curl_version()['version'] : 'N/A',
			'openssl'               => extension_loaded( 'openssl' ),
			'openssl_version'       => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'N/A',
			'gd'                    => extension_loaded( 'gd' ),
			'imagick'               => extension_loaded( 'imagick' ),
			'zip'                   => extension_loaded( 'zip' ),
			'xml'                   => extension_loaded( 'xml' ),
			'intl'                  => extension_loaded( 'intl' ),
			'opcache'               => function_exists( 'opcache_get_status' ),
			'sodium'                => extension_loaded( 'sodium' ),
		);

		// Database.
		$db_version    = $wpdb->get_var( 'SELECT VERSION()' );
		$db_charset    = $wpdb->charset;
		$db_collate    = $wpdb->collate;
		$db_prefix     = $wpdb->prefix;
		$table_count   = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" );
		$db_size_row   = $wpdb->get_row( "SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()" );
		$db_size       = $db_size_row && $db_size_row->size ? size_format( (int) $db_size_row->size ) : 'N/A';

		$info['database'] = array(
			'version'      => $db_version ?: 'N/A',
			'charset'      => $db_charset,
			'collation'    => $db_collate,
			'prefix'       => $db_prefix,
			'table_count'  => (int) $table_count,
			'total_size'   => $db_size,
		);

		// Active theme.
		$theme = wp_get_theme();
		$info['theme'] = array(
			'name'         => $theme->get( 'Name' ),
			'version'      => $theme->get( 'Version' ),
			'author'       => $theme->get( 'Author' ),
			'template'     => $theme->get_template(),
			'stylesheet'   => $theme->get_stylesheet(),
			'is_child'     => $theme->parent() ? true : false,
			'parent_theme' => $theme->parent() ? $theme->parent()->get( 'Name' ) : '',
		);

		// Active plugins.
		$active_plugins = get_option( 'active_plugins', array() );
		$all_plugins    = get_plugins();
		$plugin_list    = array();
		foreach ( $active_plugins as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$plugin_list[] = array(
					'name'    => $all_plugins[ $plugin_file ]['Name'],
					'version' => $all_plugins[ $plugin_file ]['Version'],
					'author'  => wp_strip_all_tags( $all_plugins[ $plugin_file ]['AuthorName'] ?? $all_plugins[ $plugin_file ]['Author'] ?? '' ),
					'file'    => $plugin_file,
				);
			}
		}

		// Inactive plugins.
		$inactive_list = array();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( ! in_array( $plugin_file, $active_plugins, true ) ) {
				$inactive_list[] = array(
					'name'    => $plugin_data['Name'],
					'version' => $plugin_data['Version'],
					'file'    => $plugin_file,
				);
			}
		}

		$info['plugins'] = array(
			'active'         => $plugin_list,
			'active_count'   => count( $plugin_list ),
			'inactive'       => $inactive_list,
			'inactive_count' => count( $inactive_list ),
		);

		// Uploads directory (no filesystem path — only URL and writable status).
		$upload_dir = wp_upload_dir();
		$info['uploads'] = array(
			'url'      => $upload_dir['baseurl'],
			'writable' => wp_is_writable( $upload_dir['basedir'] ),
		);

		// Constants (no filesystem paths or credentials — safe to export).
		$info['constants'] = array(
			'CONCATENATE_SCRIPTS' => defined( 'CONCATENATE_SCRIPTS' ) && CONCATENATE_SCRIPTS,
			'COMPRESS_SCRIPTS'    => defined( 'COMPRESS_SCRIPTS' ) && COMPRESS_SCRIPTS,
			'COMPRESS_CSS'        => defined( 'COMPRESS_CSS' ) && COMPRESS_CSS,
			'WP_AUTO_UPDATE_CORE' => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : 'not set',
			'DISALLOW_FILE_EDIT'  => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'DISALLOW_FILE_MODS'  => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
		);

		// Generated.
		$info['generated_at'] = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		return $info;
	}

	private function system_info_panel() {
		$info = $this->collect_system_info();
		$php  = $info['php'];
		$srv  = $info['server'];
		$wp   = $info['wordpress'];
		$db   = $info['database'];
		$site = $info['site'];
		$theme_info = $info['theme'];
		?>
		<section id="system-info" class="shield_monitor-section">
			<div class="sp-section-head">
				<div>
					<span class="sp-kicker"><?php esc_html_e( 'ENVIRONMENT', 'codewp-shield-monitor' ); ?></span>
					<h2><?php esc_html_e( 'System Information', 'codewp-shield-monitor' ); ?></h2>
					<p><?php esc_html_e( 'Overview of server environment, PHP configuration, database, plugins, and theme. Export as JSON to share with support.', 'codewp-shield-monitor' ); ?></p>
				</div>
				<div class="sp-sysinfo-actions">
					<button class="button button-primary" id="shield_monitor-sysinfo-download" type="button"><?php esc_html_e( 'Download JSON', 'codewp-shield-monitor' ); ?></button>
					<button class="button" id="shield_monitor-sysinfo-copy" type="button"><?php esc_html_e( 'Copy to clipboard', 'codewp-shield-monitor' ); ?></button>
				</div>
			</div>

			<div class="sp-sysinfo-identity">
				<div><strong><?php esc_html_e( 'Website', 'codewp-shield-monitor' ); ?></strong><span><?php echo esc_html( $site['name'] ); ?> — <?php echo esc_html( $site['url'] ); ?></span></div>
				<div><strong><?php esc_html_e( 'Server IP', 'codewp-shield-monitor' ); ?></strong><span><?php echo esc_html( $site['admin_ip'] ); ?></span></div>
				<div><strong><?php esc_html_e( 'Your IP', 'codewp-shield-monitor' ); ?></strong><span><?php echo esc_html( $site['user_ip'] ); ?></span></div>
			</div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="sysinfo-server"><?php esc_html_e( 'Server & PHP', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="sysinfo-wordpress"><?php esc_html_e( 'WordPress', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="sysinfo-database"><?php esc_html_e( 'Database', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="sysinfo-plugins"><?php esc_html_e( 'Plugins', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="sysinfo-theme"><?php esc_html_e( 'Theme', 'codewp-shield-monitor' ); ?></button>
			</div>

			<!-- Server & PHP -->
			<div class="sp-inner-panel" data-inner="sysinfo-server">
				<table class="widefat striped sp-sysinfo-table">
					<thead><tr><th colspan="2"><?php esc_html_e( 'Server Environment', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Web Server', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $srv['software'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Server Type', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $srv['type'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'PHP Version', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $srv['php_version'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'PHP SAPI', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $srv['php_sapi'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Operating System', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $srv['os'] ); ?></td></tr>
					</tbody>
				</table>
				<table class="widefat striped sp-sysinfo-table" style="margin-top:16px;">
					<thead><tr><th colspan="2"><?php esc_html_e( 'PHP Configuration', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Memory Limit', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['memory_limit'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Max Execution Time', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['max_execution_time'] ); ?>s</td></tr>
						<tr><td><?php esc_html_e( 'Max Input Time', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['max_input_time'] ); ?>s</td></tr>
						<tr><td><?php esc_html_e( 'Max Input Vars', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['max_input_vars'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Upload Max Filesize', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['upload_max_filesize'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Post Max Size', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['post_max_size'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'File Uploads', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['file_uploads'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Allow URL Fopen', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['allow_url_fopen'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Display Errors', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['display_errors'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Default Charset', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $php['default_charset'] ); ?></td></tr>
					</tbody>
				</table>
				<table class="widefat striped sp-sysinfo-table" style="margin-top:16px;">
					<thead><tr><th colspan="2"><?php esc_html_e( 'PHP Extensions', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td>cURL</td><td><?php echo $php['curl'] ? '<span class="sp-ext-on">&#10003;</span> ' . esc_html( $php['curl_version'] ) : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>OpenSSL</td><td><?php echo $php['openssl'] ? '<span class="sp-ext-on">&#10003;</span> ' . esc_html( $php['openssl_version'] ) : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>Mbstring</td><td><?php echo $php['mbstring'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>GD</td><td><?php echo $php['gd'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>Imagick</td><td><?php echo $php['imagick'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>Zip</td><td><?php echo $php['zip'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>XML</td><td><?php echo $php['xml'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>Intl</td><td><?php echo $php['intl'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>OPcache</td><td><?php echo $php['opcache'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td>Sodium</td><td><?php echo $php['sodium'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
					</tbody>
				</table>
			</div>

			<!-- WordPress -->
			<div class="sp-inner-panel" data-inner="sysinfo-wordpress" hidden>
				<table class="widefat striped sp-sysinfo-table">
					<thead><tr><th colspan="2"><?php esc_html_e( 'WordPress Configuration', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Version', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['version'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Site URL', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['site_url'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Home URL', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['home_url'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Multisite', 'codewp-shield-monitor' ); ?></td><td><?php echo $wp['multisite'] ? esc_html__( 'Yes', 'codewp-shield-monitor' ) : esc_html__( 'No', 'codewp-shield-monitor' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Permalink Structure', 'codewp-shield-monitor' ); ?></td><td><code><?php echo esc_html( $wp['permalink'] ); ?></code></td></tr>
						<tr><td><?php esc_html_e( 'Language', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['language'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Timezone', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['timezone'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WP Debug Mode', 'codewp-shield-monitor' ); ?></td><td><?php echo $wp['debug_mode'] ? '<span class="sp-ext-off">' . esc_html__( 'Enabled', 'codewp-shield-monitor' ) . '</span>' : esc_html__( 'Disabled', 'codewp-shield-monitor' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WP Debug Log', 'codewp-shield-monitor' ); ?></td><td><?php echo $wp['debug_log'] ? '<span class="sp-ext-off">' . esc_html__( 'Enabled', 'codewp-shield-monitor' ) . '</span>' : esc_html__( 'Disabled', 'codewp-shield-monitor' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WP Cron', 'codewp-shield-monitor' ); ?></td><td><?php echo $wp['cron_disabled'] ? '<span class="sp-ext-off">' . esc_html__( 'Disabled', 'codewp-shield-monitor' ) . '</span>' : esc_html__( 'Enabled', 'codewp-shield-monitor' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WP Memory Limit', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['memory_limit'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WP Max Memory Limit', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $wp['max_memory_limit'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Uploads Writable', 'codewp-shield-monitor' ); ?></td><td><?php echo $info['uploads']['writable'] ? '<span class="sp-ext-on">&#10003;</span>' : '<span class="sp-ext-off">&#10007;</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'File Editor', 'codewp-shield-monitor' ); ?></td><td><?php echo $info['constants']['DISALLOW_FILE_EDIT'] ? esc_html__( 'Disabled', 'codewp-shield-monitor' ) : '<span class="sp-ext-off">' . esc_html__( 'Enabled', 'codewp-shield-monitor' ) . '</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'File Modifications', 'codewp-shield-monitor' ); ?></td><td><?php echo $info['constants']['DISALLOW_FILE_MODS'] ? esc_html__( 'Disabled', 'codewp-shield-monitor' ) : esc_html__( 'Enabled', 'codewp-shield-monitor' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<!-- Database -->
			<div class="sp-inner-panel" data-inner="sysinfo-database" hidden>
				<table class="widefat striped sp-sysinfo-table">
					<thead><tr><th colspan="2"><?php esc_html_e( 'Database', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Server Version', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['version'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Charset', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['charset'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Collation', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['collation'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Table Prefix', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['prefix'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Total Tables', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['table_count'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Database Size', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $db['total_size'] ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<!-- Plugins -->
			<div class="sp-inner-panel" data-inner="sysinfo-plugins" hidden>
				<table class="widefat striped sp-sysinfo-table">
					<thead><tr><th colspan="3"><?php echo esc_html( sprintf( __( 'Active Plugins (%d)', 'codewp-shield-monitor' ), $info['plugins']['active_count'] ) ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $info['plugins']['active'] as $p ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $p['name'] ); ?></strong></td>
							<td><?php echo esc_html( $p['version'] ); ?></td>
							<td><?php echo esc_html( $p['author'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $info['plugins']['inactive'] ) ) : ?>
				<table class="widefat striped sp-sysinfo-table" style="margin-top:16px;">
					<thead><tr><th colspan="2"><?php echo esc_html( sprintf( __( 'Inactive Plugins (%d)', 'codewp-shield-monitor' ), $info['plugins']['inactive_count'] ) ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $info['plugins']['inactive'] as $p ) : ?>
						<tr>
							<td><?php echo esc_html( $p['name'] ); ?></td>
							<td><?php echo esc_html( $p['version'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

			<!-- Theme -->
			<div class="sp-inner-panel" data-inner="sysinfo-theme" hidden>
				<table class="widefat striped sp-sysinfo-table">
					<thead><tr><th colspan="2"><?php esc_html_e( 'Active Theme', 'codewp-shield-monitor' ); ?></th></tr></thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Name', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $theme_info['name'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Version', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $theme_info['version'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Author', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $theme_info['author'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Template', 'codewp-shield-monitor' ); ?></td><td><?php echo esc_html( $theme_info['template'] ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Child Theme', 'codewp-shield-monitor' ); ?></td><td><?php echo $theme_info['is_child'] ? esc_html__( 'Yes', 'codewp-shield-monitor' ) . ' — ' . esc_html( $theme_info['parent_theme'] ) : esc_html__( 'No', 'codewp-shield-monitor' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="sp-note" style="margin-top:16px;">
				<p><?php esc_html_e( 'Sensitive data (database passwords, secret keys) are never included in the export. Share this report with your developer or hosting provider for troubleshooting.', 'codewp-shield-monitor' ); ?></p>
			</div>
		</section>
		<?php
	}

	private function backup_status_section() {
		$last_backup = get_option( Shield_Monitor_Backup_Alert::LAST_BACKUP_OPTION, 0 );
		$days_since  = $last_backup ? floor( ( time() - absint( $last_backup ) ) / DAY_IN_SECONDS ) : null;

		if ( null !== $days_since && $days_since <= 3 ) {
			$status_class = 'sp-backup-ok';
			$status_label = sprintf( __( 'Last backup: %s (%d days ago)', 'codewp-shield-monitor' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $last_backup ) ), $days_since );
		} elseif ( null !== $days_since ) {
			$status_class = $days_since >= 30 ? 'sp-backup-critical' : 'sp-backup-warning';
			$status_label = sprintf( __( 'Last backup: %s (%d days ago)', 'codewp-shield-monitor' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $last_backup ) ), $days_since );
		} else {
			$status_class = 'sp-backup-unknown';
			$status_label = __( 'No backup detected yet', 'codewp-shield-monitor' );
		}
		?>
		<div class="sp-backup-status <?php echo esc_attr( $status_class ); ?>">
			<div class="sp-backup-status-head">
				<div>
					<h3><?php esc_html_e( 'Backup Status', 'codewp-shield-monitor' ); ?></h3>
					<p class="sp-backup-status-label"><?php echo esc_html( $status_label ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shield_monitor_mark_backup">
					<?php wp_nonce_field( 'shield_monitor_mark_backup' ); ?>
					<button class="button" type="submit"><?php esc_html_e( 'I just backed up', 'codewp-shield-monitor' ); ?></button>
				</form>
			</div>
			<p class="description"><?php esc_html_e( 'If you use hosting-level backups or a plugin we don\'t auto-detect, click the button above to mark your site as backed up and reset the alert.', 'codewp-shield-monitor' ); ?></p>
		</div>
		<?php
	}

	private function notification_settings_section() {
		?>
		<section id="notifications" class="shield_monitor-section">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'APP EVENTS', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Notification events', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Push form submissions, orders, and selected post type creations to the authenticated events API.', 'codewp-shield-monitor' ); ?></p></div></div>
			<form action="options.php" method="post" class="sp-settings-form">
				<?php settings_fields( 'codewp-shield-monitor' ); ?>
				<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[notify_post_types_submitted]" value="1">
				<div class="sp-settings-grid">
					<div>
						<h3><?php esc_html_e( 'Sources', 'codewp-shield-monitor' ); ?></h3>
						<?php $this->checkbox( 'notify_contact_form_7', __( 'Contact Form 7 submissions', 'codewp-shield-monitor' ), __( 'Push new Contact Form 7 submissions to /events.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'notify_woocommerce', __( 'WooCommerce orders', 'codewp-shield-monitor' ), __( 'Push new WooCommerce orders to /events.', 'codewp-shield-monitor' ) ); ?>
					</div>
					<div>
						<h3><?php esc_html_e( 'Post types', 'codewp-shield-monitor' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Select custom post types that should create notification events when a new item is published.', 'codewp-shield-monitor' ); ?></p>
						<?php $this->notification_post_type_options(); ?>
					</div>
				</div>
				<?php submit_button( __( 'Save notification settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
			</form>
		</section>
		<?php
	}

	private function connect_section( $pairing, $tokens ) {
		?>
		<section id="connect" class="shield_monitor-section shield_monitor-connect">
			<div>
				<span class="sp-kicker"><?php esc_html_e( 'REMOTE MONITORING', 'codewp-shield-monitor' ); ?></span>
				<h2><?php esc_html_e( 'Connect CodeWP Shield Monitor App or Web', 'codewp-shield-monitor' ); ?></h2>
				<p><?php esc_html_e( 'Scan the QR code, or enter the website URL and pairing code manually. The code works once and expires in 10 minutes.', 'codewp-shield-monitor' ); ?></p>
				<label class="shield_monitor-manual-label"><?php esc_html_e( 'Website URL', 'codewp-shield-monitor' ); ?><code><?php echo esc_html( home_url( '/' ) ); ?></code></label>
				<label class="shield_monitor-manual-label"><?php esc_html_e( 'Manual pairing code', 'codewp-shield-monitor' ); ?><code class="shield_monitor-pair-code"><?php echo esc_html( $pairing['code'] ); ?></code></label>
			</div>
			<div id="shield_monitor-qr" class="shield_monitor-qr" data-payload="<?php echo esc_attr( wp_json_encode( $pairing ) ); ?>"><span><?php esc_html_e( 'Generating QR code...', 'codewp-shield-monitor' ); ?></span></div>
			<div class="shield_monitor-devices">
				<h3><?php esc_html_e( 'Connected clients', 'codewp-shield-monitor' ); ?></h3>
				<?php if ( empty( $tokens ) ) : ?>
					<p><?php esc_html_e( 'No App or Web client is connected yet.', 'codewp-shield-monitor' ); ?></p>
				<?php else : ?>
					<?php foreach ( $tokens as $id => $token ) : ?>
						<?php $scopes = (array) ( $token['scopes'] ?? array( 'status:read', 'events:read', 'token:rotate' ) ); ?>
						<?php $quick_login_allowed = in_array( 'quick-login:create', $scopes, true ); ?>
						<div class="shield_monitor-device">
							<div>
								<strong><?php echo esc_html( $token['label'] ); ?></strong>
								<small><?php echo esc_html( sprintf( __( 'Created %s - Last used %s', 'codewp-shield-monitor' ), $token['created_at'], $token['last_used'] ?: __( 'never', 'codewp-shield-monitor' ) ) ); ?></small>
								<small><?php echo esc_html( sprintf( __( 'Expires %s - Scopes: %s', 'codewp-shield-monitor' ), $token['expires_at'] ?? __( 'after next use', 'codewp-shield-monitor' ), implode( ', ', $scopes ) ) ); ?></small>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="shield_monitor_toggle_token_scope">
								<input type="hidden" name="token_id" value="<?php echo esc_attr( $id ); ?>">
								<input type="hidden" name="scope" value="quick-login:create">
								<input type="hidden" name="enabled" value="<?php echo $quick_login_allowed ? '0' : '1'; ?>">
								<?php wp_nonce_field( 'shield_monitor_toggle_token_scope' ); ?>
								<button class="button" type="submit"><?php echo esc_html( $quick_login_allowed ? __( 'Disable quick login', 'codewp-shield-monitor' ) : __( 'Allow quick login', 'codewp-shield-monitor' ) ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="shield_monitor_revoke_token">
								<input type="hidden" name="token_id" value="<?php echo esc_attr( $id ); ?>">
								<?php wp_nonce_field( 'shield_monitor_revoke_token' ); ?>
								<button class="button" type="submit"><?php esc_html_e( 'Revoke', 'codewp-shield-monitor' ); ?></button>
							</form>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function stat_card( $icon, $label, $value, $detail, $tone = 'blue' ) {
		?><article class="sp-stat sp-tone-<?php echo esc_attr( $tone ); ?>"><div><small><?php echo esc_html( $label ); ?></small><strong><?php echo esc_html( $value ); ?></strong><em><?php echo esc_html( $detail ); ?></em></div></article><?php
	}

	private function recommendation( $passed, $title, $description ) {
		?><li class="<?php echo $passed ? 'is-passed' : 'is-action'; ?>"><div><strong><?php echo esc_html( $title ); ?></strong><p><?php echo esc_html( $description ); ?></p></div><em><?php echo esc_html( $passed ? __( 'Good', 'codewp-shield-monitor' ) : __( 'Recommended', 'codewp-shield-monitor' ) ); ?></em></li><?php
	}

	private function event_label( $type ) {
		$labels = array(
			'admin_login' => __( 'Administrator login', 'codewp-shield-monitor' ), 'login_success' => __( 'User login', 'codewp-shield-monitor' ),
			'login_failed' => __( 'Failed login', 'codewp-shield-monitor' ), 'login_lockout' => __( 'Login attack blocked', 'codewp-shield-monitor' ),
			'login_ip_blocked' => __( 'Login IP blocked', 'codewp-shield-monitor' ), 'login_ip_unlocked' => __( 'Login IP unlocked', 'codewp-shield-monitor' ),
			'admin_user_created' => __( 'New administrator user', 'codewp-shield-monitor' ),
			'admin_password_changed' => __( 'Administrator password changed', 'codewp-shield-monitor' ),
			'post_created' => __( 'Post created', 'codewp-shield-monitor' ), 'post_updated' => __( 'Post updated', 'codewp-shield-monitor' ),
			'file_added' => __( 'File added', 'codewp-shield-monitor' ), 'file_modified' => __( 'File modified', 'codewp-shield-monitor' ), 'file_deleted' => __( 'File deleted', 'codewp-shield-monitor' ),
			'file_baseline_trusted' => __( 'File baseline trusted', 'codewp-shield-monitor' ),
			'core_checksum_mismatch' => __( 'Core checksum mismatch', 'codewp-shield-monitor' ),
			'software_updated' => __( 'Software updated', 'codewp-shield-monitor' ),
			'quick_login_link_created' => __( 'Quick-login link created', 'codewp-shield-monitor' ),
			'quick_login_used' => __( 'Quick login used', 'codewp-shield-monitor' ),
			'quick_login_failed' => __( 'Quick login failed', 'codewp-shield-monitor' ),
			'token_scope_changed' => __( 'API token scope changed', 'codewp-shield-monitor' ),
			'plugin_installed' => __( 'Plugin installed', 'codewp-shield-monitor' ), 'plugin_updated' => __( 'Plugin updated', 'codewp-shield-monitor' ),
			'plugin_activated' => __( 'Plugin activated', 'codewp-shield-monitor' ), 'plugin_deactivated' => __( 'Plugin deactivated', 'codewp-shield-monitor' ),
			'theme_installed' => __( 'Theme installed', 'codewp-shield-monitor' ), 'theme_updated' => __( 'Theme updated', 'codewp-shield-monitor' ),
			'theme_activated' => __( 'Theme activated', 'codewp-shield-monitor' ),
			'malware_scan_completed' => __( 'Malware scan completed', 'codewp-shield-monitor' ), 'malware_scan_suspicious' => __( 'Suspicious scan findings', 'codewp-shield-monitor' ),
			'malware_report_cleared' => __( 'Malware report cleared', 'codewp-shield-monitor' ),
			'contact_form_7_submission' => __( 'Contact Form 7 submission', 'codewp-shield-monitor' ),
			'woocommerce_order_created' => __( 'WooCommerce order', 'codewp-shield-monitor' ),
			'custom_post_type_created' => __( 'Custom post type created', 'codewp-shield-monitor' ),
			'waf_blocked' => __( 'WAF request blocked', 'codewp-shield-monitor' ),
			'honeypot_blocked' => __( 'Honeypot bot blocked', 'codewp-shield-monitor' ),
			'dangerous_upload_blocked' => __( 'Dangerous upload blocked', 'codewp-shield-monitor' ),
		);
		return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	private function event_category( $type ) {
		if ( in_array( $type, array( 'admin_login', 'login_success', 'login_failed', 'login_lockout', 'login_ip_blocked', 'login_ip_unlocked' ), true ) ) {
			return 'login';
		}
		if ( in_array( $type, array( 'quick_login_link_created', 'quick_login_used', 'quick_login_failed' ), true ) ) {
			return 'login';
		}
		if ( in_array( $type, array( 'post_created', 'post_updated' ), true ) ) {
			return 'content';
		}
		if ( in_array( $type, array( 'contact_form_7_submission', 'woocommerce_order_created', 'custom_post_type_created' ), true ) ) {
			return 'notifications';
		}
		if ( in_array( $type, array( 'admin_user_created', 'admin_password_changed', 'user_registered', 'role_changed' ), true ) ) {
			return 'admin';
		}
		if ( in_array( $type, array( 'file_added', 'file_modified', 'file_deleted', 'file_baseline_trusted', 'core_checksum_mismatch' ), true ) ) {
			return 'files';
		}
		if ( in_array( $type, array( 'malware_scan_completed', 'malware_scan_suspicious', 'malware_report_cleared' ), true ) ) {
			return 'scan';
		}
		if ( in_array( $type, array( 'plugin_installed', 'plugin_updated', 'plugin_activated', 'plugin_deactivated', 'theme_installed', 'theme_updated', 'theme_activated', 'software_updated' ), true ) ) {
			return 'software';
		}
		return 'system';
	}

	private function event_detail( $event ) {
		$details = json_decode( $event->details, true );
		if ( is_array( $details ) && ! empty( $details['path'] ) ) {
			return sanitize_text_field( $details['path'] );
		}
		if ( is_array( $details ) && 'contact_form_7' === ( $details['source'] ?? '' ) ) {
			return sanitize_text_field( trim( ( $details['form_title'] ?? __( 'Contact form', 'codewp-shield-monitor' ) ) . ' | ' . implode( ', ', array_keys( (array) ( $details['posted_fields'] ?? array() ) ) ) ) );
		}
		if ( is_array( $details ) && 'woocommerce' === ( $details['source'] ?? '' ) ) {
			return sanitize_text_field( sprintf( '#%s | %s %s | %s', $details['order_number'] ?? $details['order_id'] ?? '', $details['total'] ?? '', $details['currency'] ?? '', $details['billing']['email'] ?? '' ) );
		}
		if ( is_array( $details ) && ! empty( $details['title'] ) ) {
			$bits = array( sanitize_text_field( $details['title'] ) );
			if ( ! empty( $details['post_type'] ) ) {
				$bits[] = sanitize_key( $details['post_type'] );
			}
			if ( ! empty( $details['changed_fields'] ) && is_array( $details['changed_fields'] ) ) {
				$bits[] = sprintf( __( 'Changed: %s', 'codewp-shield-monitor' ), implode( ', ', array_map( 'sanitize_key', $details['changed_fields'] ) ) );
			}
			return implode( ' | ', $bits );
		}
		if ( is_array( $details ) && ! empty( $details['items'] ) && is_array( $details['items'] ) ) {
			return sanitize_text_field( ( $details['type'] ?? 'software' ) . ': ' . implode( ', ', array_slice( $details['items'], 0, 3 ) ) );
		}
		if ( is_array( $details ) && ! empty( $details['file'] ) ) {
			return sanitize_text_field( ( $details['name'] ?? '' ) . ' | ' . $details['file'] );
		}
		if ( is_array( $details ) && ! empty( $details['stylesheet'] ) ) {
			return sanitize_text_field( ( $details['name'] ?? '' ) . ' | ' . $details['stylesheet'] );
		}
		if ( is_array( $details ) && ! empty( $details['ip'] ) ) {
			return sanitize_text_field( $details['ip'] );
		}
		if ( is_array( $details ) && ! empty( $details['summary'] ) && is_array( $details['summary'] ) ) {
			return sanitize_text_field( ( $details['summary']['risk_level'] ?? 'unknown' ) . ': ' . absint( $details['summary']['total'] ?? 0 ) . ' findings' );
		}
		if ( is_array( $details ) && ! empty( $details['username'] ) ) {
			return sanitize_user( $details['username'], true );
		}
		if ( $event->user_id ) {
			return (string) get_the_author_meta( 'user_login', $event->user_id );
		}
		return '—';
	}

	private function events_table( $events, $empty = '' ) {
		if ( empty( $events ) ) {
			echo '<div class="sp-empty"><p>' . esc_html( $empty ?: __( 'No security activity has been recorded in the last 30 days.', 'codewp-shield-monitor' ) ) . '</p></div>';
			return;
		}
		?><div class="sp-history-tools"><label><?php esc_html_e( 'Filter', 'codewp-shield-monitor' ); ?><select id="shield_monitor-history-filter"><option value="all"><?php esc_html_e( 'All events', 'codewp-shield-monitor' ); ?></option><option value="login"><?php esc_html_e( 'Login', 'codewp-shield-monitor' ); ?></option><option value="content"><?php esc_html_e( 'Content', 'codewp-shield-monitor' ); ?></option><option value="notifications"><?php esc_html_e( 'Notifications', 'codewp-shield-monitor' ); ?></option><option value="admin"><?php esc_html_e( 'Admin users', 'codewp-shield-monitor' ); ?></option><option value="files"><?php esc_html_e( 'Files', 'codewp-shield-monitor' ); ?></option><option value="software"><?php esc_html_e( 'Software', 'codewp-shield-monitor' ); ?></option><option value="scan"><?php esc_html_e( 'Malware scan', 'codewp-shield-monitor' ); ?></option><option value="system"><?php esc_html_e( 'System', 'codewp-shield-monitor' ); ?></option></select></label><label><?php esc_html_e( 'Search', 'codewp-shield-monitor' ); ?><input id="shield_monitor-history-search" type="search" placeholder="<?php esc_attr_e( 'Search event, detail, user...', 'codewp-shield-monitor' ); ?>"></label></div><div class="sp-table-wrap"><table class="sp-events" id="shield_monitor-history-table"><thead><tr><th><?php esc_html_e( 'Event', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Details', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'User', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Time (UTC)', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $events as $event ) : ?><?php $type = sanitize_key( $event->event_type ); $detail = $this->event_detail( $event ); $user = $event->user_id ? (string) get_the_author_meta( 'user_login', $event->user_id ) : '-'; ?><tr data-category="<?php echo esc_attr( $this->event_category( $type ) ); ?>" data-search="<?php echo esc_attr( strtolower( $this->event_label( $type ) . ' ' . $detail . ' ' . $user . ' ' . $event->created_at ) ); ?>"><td><span class="sp-event-type sp-event-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $this->event_label( $type ) ); ?></span></td><td><code><?php echo esc_html( $detail ); ?></code></td><td><?php echo esc_html( sanitize_user( $user, true ) ?: '-' ); ?></td><td><?php echo esc_html( $event->created_at ); ?></td></tr><?php endforeach; ?></tbody></table><p class="sp-history-empty" id="shield_monitor-history-empty" hidden><?php esc_html_e( 'No matching events found.', 'codewp-shield-monitor' ); ?></p></div><?php
	}

	private function malware_table( $scan ) {
		$findings = isset( $scan['findings'] ) && is_array( $scan['findings'] ) ? $scan['findings'] : array();
		if ( empty( $findings ) ) {
			echo '<div class="sp-empty"><p>' . esc_html__( 'No suspicious files or database records have been reported yet.', 'codewp-shield-monitor' ) . '</p></div>';
			return;
		}
		?><div class="sp-table-wrap"><table class="sp-events sp-malware-table"><thead><tr><th><?php esc_html_e( 'Risk', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Location', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Reason', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $findings as $finding ) : ?><tr><td><span class="sp-risk sp-risk-<?php echo esc_attr( $finding['severity'] ?? 'low' ); ?>"><?php echo esc_html( strtoupper( $finding['severity'] ?? 'low' ) ); ?></span></td><td><code><?php echo esc_html( trim( ( $finding['source'] ?? '' ) . ': ' . ( $finding['location'] ?? '' ) . ( empty( $finding['record'] ) ? '' : ' / ' . $finding['record'] ) ) ); ?></code></td><td><strong><?php echo esc_html( $finding['message'] ?? '' ); ?></strong><small><?php echo esc_html( $finding['evidence'] ?? '' ); ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	private function threat_table( $threats ) {
		$findings = isset( $threats['findings'] ) && is_array( $threats['findings'] ) ? $threats['findings'] : array();
		if ( empty( $findings ) ) {
			echo '<div class="sp-empty"><p>' . esc_html__( 'No threat intelligence findings are currently reported.', 'codewp-shield-monitor' ) . '</p></div>';
			return;
		}
		?><div class="sp-table-wrap"><table class="sp-events sp-malware-table"><thead><tr><th><?php esc_html_e( 'Risk', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Type', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Location', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Evidence', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $findings as $finding ) : ?><tr><td><span class="sp-risk sp-risk-<?php echo esc_attr( $finding['severity'] ?? 'low' ); ?>"><?php echo esc_html( strtoupper( $finding['severity'] ?? 'low' ) ); ?></span></td><td><strong><?php echo esc_html( $finding['title'] ?? '' ); ?></strong><small><?php echo esc_html( str_replace( '_', ' ', $finding['type'] ?? '' ) ); ?></small></td><td><code><?php echo esc_html( $finding['location'] ?? '' ); ?></code></td><td><?php echo esc_html( $finding['evidence'] ?? '' ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	private function login_access_panel( $lockouts ) {
		$login_slug = sanitize_title( $this->settings->get( 'login_slug' ) ?: 'codewp-shield-monitor-login' );
		$login_url  = home_url( '/' . $login_slug . '/' );
		$quick_login_user_id = $this->settings->quick_login_user_id();
		$locked_count = 0;
		foreach ( $lockouts as $row ) {
			if ( ! empty( $row['locked'] ) ) {
				++$locked_count;
			}
		}
		?>
		<section id="login-access" class="shield_monitor-section sp-login-access">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'ACCESS CONTROL', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Login, protection, and lockouts', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Move the public login path, configure brute-force protection, enable app/web quick-login, and manage blocked IPs.', 'codewp-shield-monitor' ); ?></p></div><span class="sp-status <?php echo $this->settings->get( 'hide_login' ) ? 'is-on' : 'is-off'; ?>"><i></i><?php echo esc_html( $this->settings->get( 'hide_login' ) ? __( 'Hidden login active', 'codewp-shield-monitor' ) : __( 'Default login visible', 'codewp-shield-monitor' ) ); ?></span></div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="login-path"><?php esc_html_e( 'Login path', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="login-protection"><?php esc_html_e( 'Brute-force protection', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="login-quick"><?php esc_html_e( 'Quick login', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="login-lockouts"><?php esc_html_e( 'Failed IPs', 'codewp-shield-monitor' ); ?><?php if ( $locked_count ) : ?> <span class="sp-tab-badge"><?php echo esc_html( $locked_count ); ?></span><?php endif; ?></button>
			</div>

			<!-- Login path -->
			<div class="sp-inner-panel" data-inner="login-path">
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Hide the default WordPress login URL and use a custom path that only you know.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'hide_login', __( 'Hide default login paths', 'codewp-shield-monitor' ), __( 'Block anonymous access to /wp-login.php and /wp-admin, then use the custom path below.', 'codewp-shield-monitor' ) ); ?>
					<label class="sp-field"><?php esc_html_e( 'Custom login slug', 'codewp-shield-monitor' ); ?><input type="text" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[login_slug]" value="<?php echo esc_attr( $login_slug ); ?>" pattern="[a-zA-Z0-9_-]+" autocomplete="off"><small><?php echo esc_html( $login_url ); ?></small></label>
					<?php submit_button( __( 'Save login path', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Brute-force protection -->
			<div class="sp-inner-panel" data-inner="login-protection" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Automatically lock IPs after repeated failed login attempts to block brute-force attacks.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'login_protection', __( 'Login protection', 'codewp-shield-monitor' ), __( 'Lock repeated failed login attempts.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits"><label><?php esc_html_e( 'Maximum login attempts', 'codewp-shield-monitor' ); ?><input type="number" min="3" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $this->settings->get( 'max_attempts' ) ); ?>"></label><label><?php esc_html_e( 'Lockout duration (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="5" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $this->settings->get( 'lockout_minutes' ) ); ?>"></label></div>
					<?php submit_button( __( 'Save protection settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Quick login -->
			<div class="sp-inner-panel" data-inner="login-quick" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[quick_login_submitted]" value="1">
					<p class="description"><?php esc_html_e( 'Paired ShieldPress clients can request a one-time admin login link without asking for a WordPress username, password, CAPTCHA, or PIN.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'quick_login_enabled', __( 'Enable quick login', 'codewp-shield-monitor' ), __( 'Creates 60-second, one-use admin login links through the token-protected API.', 'codewp-shield-monitor' ) ); ?>
					<label class="sp-field"><?php esc_html_e( 'Login as administrator', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[quick_login_user_id]"><?php $this->admin_user_options( $quick_login_user_id ); ?></select><small><?php esc_html_e( 'Defaults to the first administrator account when no account is selected.', 'codewp-shield-monitor' ); ?></small></label>
					<?php submit_button( __( 'Save quick login settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Failed-login IPs -->
			<div class="sp-inner-panel" data-inner="login-lockouts" hidden>
			<?php if ( empty( $lockouts ) ) : ?>
				<div class="sp-empty"><p><?php esc_html_e( 'No failed-login IPs have been recorded yet.', 'codewp-shield-monitor' ); ?></p></div>
			<?php else : ?>
				<div class="sp-table-wrap"><table class="sp-events"><thead><tr><th><?php esc_html_e( 'IP', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Attempts', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Last failed (UTC)', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $lockouts as $row ) : ?><tr><td><code><?php echo esc_html( $row['ip'] ); ?></code></td><td><?php echo esc_html( $row['attempts'] ); ?></td><td><?php echo esc_html( $row['locked'] ? sprintf( __( 'Blocked until %s', 'codewp-shield-monitor' ), $row['locked_until'] ) : __( 'Watching', 'codewp-shield-monitor' ) ); ?></td><td><?php echo esc_html( $row['last_failed_at'] ?: '-' ); ?></td><td><?php if ( $row['locked'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_login_unlock_ip"><input type="hidden" name="ip_hash" value="<?php echo esc_attr( $row['hash'] ); ?>"><?php wp_nonce_field( 'shield_monitor_login_unlock_ip' ); ?><button class="button" type="submit"><?php esc_html_e( 'Unlock', 'codewp-shield-monitor' ); ?></button></form><?php else : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_login_block_ip"><input type="hidden" name="ip_hash" value="<?php echo esc_attr( $row['hash'] ); ?>"><?php wp_nonce_field( 'shield_monitor_login_block_ip' ); ?><button class="button" type="submit"><?php esc_html_e( 'Block', 'codewp-shield-monitor' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
			<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function advanced_hardening_panel() {
		?>
		<section id="advanced-hardening" class="shield_monitor-section sp-advanced-hardening">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'ADVANCED HARDENING', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Install locks, CAPTCHA, and admin PIN', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Add extra controls around administrator access and high-risk WordPress maintenance actions.', 'codewp-shield-monitor' ); ?></p></div></div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="adv-software"><?php esc_html_e( 'Software locks', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="adv-captcha"><?php esc_html_e( 'CAPTCHA', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="adv-pin"><?php esc_html_e( 'Staff PIN', 'codewp-shield-monitor' ); ?></button>
			</div>

			<div class="sp-inner-panel" data-inner="adv-software">
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Restrict what administrators can do in the WordPress dashboard to prevent unauthorized changes.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'disable_software_installation', __( 'Block plugin and theme installation', 'codewp-shield-monitor' ), __( 'Removes install, upload, and delete capabilities for plugins and themes.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'disable_file_editor', __( 'Disable plugin/theme file editor', 'codewp-shield-monitor' ), __( 'Prevents code editing from the WordPress dashboard.', 'codewp-shield-monitor' ) ); ?>
					<?php submit_button( __( 'Save software locks', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<div class="sp-inner-panel" data-inner="adv-captcha" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_forms_submitted]" value="1">
					<p class="description"><?php esc_html_e( 'Require Google reCAPTCHA or Cloudflare Turnstile challenges on selected forms to block bots.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'captcha_enabled', __( 'Enable CAPTCHA challenges', 'codewp-shield-monitor' ), __( 'Activate the selected CAPTCHA provider on the forms below.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-captcha-grid">
						<?php $captcha_provider = 'recaptcha' === $this->settings->get( 'captcha_provider' ) ? 'recaptcha_v2' : $this->settings->get( 'captcha_provider' ); ?>
						<label class="sp-field"><?php esc_html_e( 'Provider', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_provider]"><option value="turnstile" <?php selected( $captcha_provider, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'codewp-shield-monitor' ); ?></option><option value="recaptcha_v2" <?php selected( $captcha_provider, 'recaptcha_v2' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2', 'codewp-shield-monitor' ); ?></option><option value="recaptcha_v3" <?php selected( $captcha_provider, 'recaptcha_v3' ); ?>><?php esc_html_e( 'Google reCAPTCHA v3', 'codewp-shield-monitor' ); ?></option></select></label>
						<label class="sp-field"><?php esc_html_e( 'Site key', 'codewp-shield-monitor' ); ?><input type="text" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_site_key]" value="<?php echo esc_attr( $this->settings->get( 'captcha_site_key' ) ); ?>" autocomplete="off"></label>
						<label class="sp-field"><?php esc_html_e( 'Secret key', 'codewp-shield-monitor' ); ?><input type="password" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_secret_key]" value="<?php echo esc_attr( $this->settings->get( 'captcha_secret_key' ) ); ?>" autocomplete="new-password"></label>
						<label class="sp-field"><?php esc_html_e( 'Score threshold (v3 only)', 'codewp-shield-monitor' ); ?><input type="number" min="0" max="1" step="0.1" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_score_threshold]" value="<?php echo esc_attr( $this->settings->get( 'captcha_score_threshold' ) ?: '0.1' ); ?>"><small><?php esc_html_e( 'Used by Google reCAPTCHA v3. Default is 0.1.', 'codewp-shield-monitor' ); ?></small></label>
					</div>
					<h4 style="margin:18px 0 8px"><?php esc_html_e( 'Protected forms', 'codewp-shield-monitor' ); ?></h4>
					<?php $this->captcha_form_options(); ?>
					<?php submit_button( __( 'Save CAPTCHA settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>

				<div class="sp-captcha-guides">
					<h4><?php esc_html_e( 'How to get your keys', 'codewp-shield-monitor' ); ?></h4>

					<details class="sp-guide-block">
						<summary><strong><?php esc_html_e( 'Cloudflare Turnstile (recommended)', 'codewp-shield-monitor' ); ?></strong></summary>
						<ol>
							<li><?php echo wp_kses( __( 'Go to <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">Cloudflare Dashboard &rarr; Turnstile</a>.', 'codewp-shield-monitor' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
							<li><?php esc_html_e( 'Click "Add widget", enter your site name and domain.', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Widget type: choose "Managed" (auto-decides challenge) for best UX.', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Click "Create" — copy the Site Key and Secret Key into the fields above.', 'codewp-shield-monitor' ); ?></li>
						</ol>
						<p class="sp-guide-tip"><?php esc_html_e( 'Turnstile is free, privacy-friendly, and invisible to most users. No puzzles required.', 'codewp-shield-monitor' ); ?></p>
					</details>

					<details class="sp-guide-block">
						<summary><strong><?php esc_html_e( 'Google reCAPTCHA v2 ("I\'m not a robot")', 'codewp-shield-monitor' ); ?></strong></summary>
						<ol>
							<li><?php echo wp_kses( __( 'Go to <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer">Google reCAPTCHA Admin</a>.', 'codewp-shield-monitor' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
							<li><?php esc_html_e( 'Enter a label (e.g. your site name), select "reCAPTCHA v2" &rarr; "I\'m not a robot" Checkbox.', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Add your domain (e.g. example.com), accept terms, then click "Submit".', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Copy the Site Key and Secret Key into the fields above.', 'codewp-shield-monitor' ); ?></li>
						</ol>
						<p class="sp-guide-tip"><?php esc_html_e( 'v2 shows a checkbox challenge. Good compatibility but requires user interaction.', 'codewp-shield-monitor' ); ?></p>
					</details>

					<details class="sp-guide-block">
						<summary><strong><?php esc_html_e( 'Google reCAPTCHA v3 (invisible score)', 'codewp-shield-monitor' ); ?></strong></summary>
						<ol>
							<li><?php echo wp_kses( __( 'Go to <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer">Google reCAPTCHA Admin</a>.', 'codewp-shield-monitor' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></li>
							<li><?php esc_html_e( 'Enter a label, select "reCAPTCHA v3" (score-based).', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Add your domain, accept terms, then click "Submit".', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Copy the Site Key and Secret Key into the fields above.', 'codewp-shield-monitor' ); ?></li>
							<li><?php esc_html_e( 'Set the "Score threshold" above (default 0.1). Higher = stricter, but may block real users. Start with 0.1-0.3.', 'codewp-shield-monitor' ); ?></li>
						</ol>
						<p class="sp-guide-tip"><?php esc_html_e( 'v3 runs invisibly in the background and scores each request. No user interaction needed, but may be less accurate on low-traffic sites.', 'codewp-shield-monitor' ); ?></p>
					</details>
				</div>
			</div>

			<div class="sp-inner-panel" data-inner="adv-pin" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Add a 6-digit PIN as an extra verification step when staff members log in. WooCommerce customer accounts are excluded automatically.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'admin_pin_enabled', __( 'Require 6-digit staff PIN', 'codewp-shield-monitor' ), __( 'Non-customer users must enter the PIN together with their password.', 'codewp-shield-monitor' ) ); ?>
					<label class="sp-field sp-pin-field"><?php esc_html_e( 'Set new PIN', 'codewp-shield-monitor' ); ?><input type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[admin_pin]" value="" autocomplete="new-password" placeholder="123456"><small><?php echo esc_html( $this->settings->get( 'admin_pin_hash' ) ? __( 'A PIN is configured. Leave blank to keep it.', 'codewp-shield-monitor' ) : __( 'Enter exactly 6 digits before enabling PIN login.', 'codewp-shield-monitor' ) ); ?></small></label>
					<h4 style="margin:18px 0 8px"><?php esc_html_e( 'Roles requiring Staff PIN', 'codewp-shield-monitor' ); ?></h4>
					<?php $this->staff_pin_role_options(); ?>
					<?php submit_button( __( 'Save PIN settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	private function threat_patterns_panel() {
		$threat_patterns = Shield_Monitor_Threat_Patterns::instance();
		$patterns        = $threat_patterns->all();
		$waf_log         = get_transient( Shield_Monitor_WAF::LOG_OPTION );
		$waf_log         = is_array( $waf_log ) ? $waf_log : array();
		$type_labels     = array(
			'file_signature'  => __( 'File scan', 'codewp-shield-monitor' ),
			'waf_rule'        => __( 'WAF rule', 'codewp-shield-monitor' ),
			'database_pattern'=> __( 'Database scan', 'codewp-shield-monitor' ),
		);
		$severity_labels = array(
			'critical' => __( 'Critical', 'codewp-shield-monitor' ),
			'high'     => __( 'High', 'codewp-shield-monitor' ),
			'medium'   => __( 'Medium', 'codewp-shield-monitor' ),
			'low'      => __( 'Low', 'codewp-shield-monitor' ),
		);
		?>
		<section id="threat-patterns" class="shield_monitor-section sp-threat-patterns">
			<div class="sp-section-head">
				<div>
					<span class="sp-kicker"><?php esc_html_e( 'THREAT PATTERNS', 'codewp-shield-monitor' ); ?></span>
					<h2><?php esc_html_e( 'Custom threat signatures and WAF rules', 'codewp-shield-monitor' ); ?></h2>
					<p><?php esc_html_e( 'Add custom regex patterns to detect malware in files, filter malicious requests (WAF), or scan database content. Import/export your rule sets to share across sites.', 'codewp-shield-monitor' ); ?></p>
				</div>
				<span class="sp-status is-on"><i></i><?php echo esc_html( sprintf( __( '%d patterns', 'codewp-shield-monitor' ), count( $patterns ) ) ); ?></span>
			</div>

			<!-- Add/Edit form -->
			<div class="sp-pattern-form">
				<h3><?php esc_html_e( 'Add new pattern', 'codewp-shield-monitor' ); ?></h3>
				<div class="sp-settings-grid">
					<div>
						<label class="sp-field"><?php esc_html_e( 'Name', 'codewp-shield-monitor' ); ?>
							<input type="text" id="sp-pattern-name" placeholder="<?php esc_attr_e( 'e.g. Backdoor: eval($_POST)', 'codewp-shield-monitor' ); ?>">
						</label>
						<label class="sp-field"><?php esc_html_e( 'Type', 'codewp-shield-monitor' ); ?>
							<select id="sp-pattern-type">
								<option value="file_signature"><?php esc_html_e( 'File scan — match against file contents', 'codewp-shield-monitor' ); ?></option>
								<option value="waf_rule"><?php esc_html_e( 'WAF rule — block matching HTTP requests', 'codewp-shield-monitor' ); ?></option>
								<option value="database_pattern"><?php esc_html_e( 'Database scan — match against stored content', 'codewp-shield-monitor' ); ?></option>
							</select>
						</label>
						<label class="sp-field"><?php esc_html_e( 'Severity', 'codewp-shield-monitor' ); ?>
							<select id="sp-pattern-severity">
								<option value="critical"><?php esc_html_e( 'Critical', 'codewp-shield-monitor' ); ?></option>
								<option value="high"><?php esc_html_e( 'High', 'codewp-shield-monitor' ); ?></option>
								<option value="medium" selected><?php esc_html_e( 'Medium', 'codewp-shield-monitor' ); ?></option>
								<option value="low"><?php esc_html_e( 'Low', 'codewp-shield-monitor' ); ?></option>
							</select>
						</label>
					</div>
					<div>
						<label class="sp-field"><?php esc_html_e( 'Regex pattern', 'codewp-shield-monitor' ); ?>
							<input type="text" id="sp-pattern-regex" placeholder="<?php esc_attr_e( '/eval\\s*\\(\\s*\\$_POST/i', 'codewp-shield-monitor' ); ?>">
							<small><?php esc_html_e( 'Must be a valid PHP regular expression with delimiters (e.g. /pattern/i).', 'codewp-shield-monitor' ); ?></small>
						</label>
						<label class="sp-field"><?php esc_html_e( 'Description', 'codewp-shield-monitor' ); ?>
							<textarea id="sp-pattern-description" rows="2" placeholder="<?php esc_attr_e( 'What this pattern detects and why it is dangerous.', 'codewp-shield-monitor' ); ?>"></textarea>
						</label>
						<input type="hidden" id="sp-pattern-id" value="">
						<div class="sp-pattern-actions">
							<button type="button" class="button button-primary" id="sp-pattern-save"><?php esc_html_e( 'Add pattern', 'codewp-shield-monitor' ); ?></button>
							<button type="button" class="button" id="sp-pattern-cancel" style="display:none"><?php esc_html_e( 'Cancel edit', 'codewp-shield-monitor' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<!-- Pattern list -->
			<div class="sp-table-wrap">
				<table class="sp-events" id="sp-patterns-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'codewp-shield-monitor' ); ?></th>
							<th><?php esc_html_e( 'Type', 'codewp-shield-monitor' ); ?></th>
							<th><?php esc_html_e( 'Severity', 'codewp-shield-monitor' ); ?></th>
							<th><?php esc_html_e( 'Regex', 'codewp-shield-monitor' ); ?></th>
							<th><?php esc_html_e( 'Status', 'codewp-shield-monitor' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'codewp-shield-monitor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $patterns ) ) : ?>
							<tr class="sp-patterns-empty"><td colspan="6"><?php esc_html_e( 'No custom patterns yet. Add one above or load starter patterns.', 'codewp-shield-monitor' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $patterns as $p ) : ?>
								<tr data-id="<?php echo esc_attr( $p['id'] ?? '' ); ?>">
									<td><strong><?php echo esc_html( $p['name'] ?? '' ); ?></strong><br><small><?php echo esc_html( $p['description'] ?? '' ); ?></small></td>
									<td><?php echo esc_html( $type_labels[ $p['type'] ?? '' ] ?? $p['type'] ?? '' ); ?></td>
									<td><span class="sp-risk sp-risk-<?php echo esc_attr( $p['severity'] ?? 'medium' ); ?>"><?php echo esc_html( strtoupper( $severity_labels[ $p['severity'] ?? 'medium' ] ?? $p['severity'] ?? 'medium' ) ); ?></span></td>
									<td><code><?php echo esc_html( $p['regex'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( ! empty( $p['enabled'] ) ? __( 'Enabled', 'codewp-shield-monitor' ) : __( 'Disabled', 'codewp-shield-monitor' ) ); ?></td>
									<td>
										<button type="button" class="button sp-pattern-toggle" data-id="<?php echo esc_attr( $p['id'] ?? '' ); ?>"><?php echo esc_html( ! empty( $p['enabled'] ) ? __( 'Disable', 'codewp-shield-monitor' ) : __( 'Enable', 'codewp-shield-monitor' ) ); ?></button>
										<button type="button" class="button sp-pattern-edit" data-pattern="<?php echo esc_attr( wp_json_encode( $p ) ); ?>"><?php esc_html_e( 'Edit', 'codewp-shield-monitor' ); ?></button>
										<button type="button" class="button sp-pattern-delete" data-id="<?php echo esc_attr( $p['id'] ?? '' ); ?>"><?php esc_html_e( 'Delete', 'codewp-shield-monitor' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Import / Export / Starter -->
			<div class="sp-pattern-tools">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:inline-flex;gap:8px;align-items:center">
					<input type="hidden" name="action" value="shield_monitor_import_patterns">
					<?php wp_nonce_field( 'shield_monitor_import_patterns' ); ?>
					<input type="file" name="patterns_file" accept=".json">
					<button type="submit" class="button"><?php esc_html_e( 'Import JSON', 'codewp-shield-monitor' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="shield_monitor_export_patterns">
					<?php wp_nonce_field( 'shield_monitor_export_patterns' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Export JSON', 'codewp-shield-monitor' ); ?></button>
				</form>
				<button type="button" class="button" id="sp-load-starters"><?php esc_html_e( 'Load starter patterns', 'codewp-shield-monitor' ); ?></button>
			</div>

			<!-- WAF Blocked Log -->
			<?php if ( $this->settings->get( 'waf_enabled' ) && ! empty( $waf_log ) ) : ?>
			<div class="sp-waf-log">
				<h3><?php esc_html_e( 'Recent WAF blocked requests', 'codewp-shield-monitor' ); ?></h3>
				<div class="sp-table-wrap">
					<table class="sp-events">
						<thead><tr><th><?php esc_html_e( 'Rule', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Reason', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'IP', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'URI', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Time (UTC)', 'codewp-shield-monitor' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( array_reverse( $waf_log ) as $entry ) : ?>
								<tr>
									<td><code><?php echo esc_html( $entry['rule'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
									<td><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
									<td><code><?php echo esc_html( mb_strimwidth( $entry['uri'] ?? '', 0, 80, '...' ) ); ?></code></td>
									<td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<script>
			(function(){
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'shield_monitor_threat_pattern' ) ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var starters = <?php echo wp_json_encode( Shield_Monitor_Threat_Patterns::starter_patterns() ); ?>;

				function post(action, data, cb) {
					data._nonce = nonce;
					var fd = new FormData();
					fd.append('action', 'shield_monitor_threat_pattern_' + action);
					for (var k in data) { if (typeof data[k] === 'object') { for (var sk in data[k]) { fd.append('pattern[' + sk + ']', data[k][sk]); } } else { fd.append(k, data[k]); } }
					fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'}).then(function(r){return r.json();}).then(cb).catch(function(){alert('Request failed');});
				}

				document.getElementById('sp-pattern-save').addEventListener('click', function(){
					var p = {
						id: document.getElementById('sp-pattern-id').value,
						name: document.getElementById('sp-pattern-name').value,
						type: document.getElementById('sp-pattern-type').value,
						severity: document.getElementById('sp-pattern-severity').value,
						regex: document.getElementById('sp-pattern-regex').value,
						description: document.getElementById('sp-pattern-description').value,
						enabled: 1
					};
					if (!p.name || !p.regex) { alert('Name and regex are required.'); return; }
					post('save', {pattern: p}, function(r){ if(r.success){location.reload();}else{alert(r.data.message||'Error');} });
				});

				document.getElementById('sp-pattern-cancel').addEventListener('click', function(){
					document.getElementById('sp-pattern-id').value = '';
					document.getElementById('sp-pattern-name').value = '';
					document.getElementById('sp-pattern-regex').value = '';
					document.getElementById('sp-pattern-description').value = '';
					document.getElementById('sp-pattern-severity').value = 'medium';
					document.getElementById('sp-pattern-type').value = 'file_signature';
					this.style.display = 'none';
					document.getElementById('sp-pattern-save').textContent = <?php echo wp_json_encode( __( 'Add pattern', 'codewp-shield-monitor' ) ); ?>;
				});

				document.querySelectorAll('.sp-pattern-edit').forEach(function(btn){
					btn.addEventListener('click', function(){
						var p = JSON.parse(this.dataset.pattern);
						document.getElementById('sp-pattern-id').value = p.id || '';
						document.getElementById('sp-pattern-name').value = p.name || '';
						document.getElementById('sp-pattern-type').value = p.type || 'file_signature';
						document.getElementById('sp-pattern-severity').value = p.severity || 'medium';
						document.getElementById('sp-pattern-regex').value = p.regex || '';
						document.getElementById('sp-pattern-description').value = p.description || '';
						document.getElementById('sp-pattern-cancel').style.display = '';
						document.getElementById('sp-pattern-save').textContent = <?php echo wp_json_encode( __( 'Update pattern', 'codewp-shield-monitor' ) ); ?>;
						document.getElementById('sp-pattern-name').focus();
					});
				});

				document.querySelectorAll('.sp-pattern-toggle').forEach(function(btn){
					btn.addEventListener('click', function(){
						post('toggle', {pattern_id: this.dataset.id}, function(r){ if(r.success){location.reload();}else{alert(r.data.message||'Error');} });
					});
				});

				document.querySelectorAll('.sp-pattern-delete').forEach(function(btn){
					btn.addEventListener('click', function(){
						if (!confirm(<?php echo wp_json_encode( __( 'Delete this pattern?', 'codewp-shield-monitor' ) ); ?>)) return;
						post('delete', {pattern_id: this.dataset.id}, function(r){ if(r.success){location.reload();}else{alert(r.data.message||'Error');} });
					});
				});

				document.getElementById('sp-load-starters').addEventListener('click', function(){
					if (!confirm(<?php echo wp_json_encode( __( 'Load built-in starter patterns? Existing patterns will not be removed.', 'codewp-shield-monitor' ) ); ?>)) return;
					var loaded = 0, total = starters.length;
					starters.forEach(function(s){
						post('save', {pattern: s}, function(r){ loaded++; if(loaded>=total){location.reload();} });
					});
				});
			})();
			</script>
		</section>
		<?php
	}

	private function anti_spam_panel() {
		?>
		<section id="anti-spam" class="shield_monitor-section sp-anti-spam">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'ANTI-SPAM', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Firewall, spam, and bot protection', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'WAF firewall, honeypot fields, rate limiting, math CAPTCHA, and upload restrictions to prevent automated attacks and spam.', 'codewp-shield-monitor' ); ?></p></div></div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="spam-waf"><?php esc_html_e( 'WAF & Bot protection', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="spam-comments"><?php esc_html_e( 'Comment spam', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="spam-registration"><?php esc_html_e( 'Registration spam', 'codewp-shield-monitor' ); ?></button>
			</div>

			<!-- WAF & Bot protection -->
			<div class="sp-inner-panel" data-inner="spam-waf">
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Block malicious requests and automated bots before they reach WordPress.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'waf_enabled', __( 'Web Application Firewall (WAF)', 'codewp-shield-monitor' ), __( 'Block malicious requests containing SQL injection, XSS, path traversal, command injection, and other attack patterns before WordPress processes them.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'honeypot_enabled', __( 'Honeypot fields', 'codewp-shield-monitor' ), __( 'Add invisible trap fields to login, registration, and comment forms. Bots fill them and get blocked instantly.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'block_php_uploads', __( 'Block PHP execution in uploads', 'codewp-shield-monitor' ), __( 'Create .htaccess rules to prevent PHP files from executing inside wp-content/uploads. Also blocks uploading dangerous file types (.php, .phtml, .phar, etc.).', 'codewp-shield-monitor' ) ); ?>
					<?php submit_button( __( 'Save WAF settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Comment spam -->
			<div class="sp-inner-panel" data-inner="spam-comments" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Protect comment forms with CAPTCHA, rate limiting, and math challenges.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'comment_captcha_enabled', __( 'CAPTCHA on comment forms', 'codewp-shield-monitor' ), __( 'Apply the configured CAPTCHA provider (reCAPTCHA / Turnstile) to comment forms. Requires CAPTCHA to be enabled and "Comment forms" selected in Advanced Hardening.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'comment_rate_limit_enabled', __( 'Comment rate limiting', 'codewp-shield-monitor' ), __( 'Limit the number of comments a single IP can submit within a time window.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits">
						<label><?php esc_html_e( 'Max comments per IP', 'codewp-shield-monitor' ); ?><input type="number" min="1" max="50" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[comment_rate_limit_max]" value="<?php echo esc_attr( $this->settings->get( 'comment_rate_limit_max' ) ); ?>"></label>
						<label><?php esc_html_e( 'Time window (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="1" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[comment_rate_limit_window]" value="<?php echo esc_attr( $this->settings->get( 'comment_rate_limit_window' ) ); ?>"></label>
					</div>
					<?php $this->checkbox( 'comment_math_captcha_enabled', __( 'Math CAPTCHA for comments', 'codewp-shield-monitor' ), __( 'Show a simple math question (e.g. 3 + 5 = ?) on comment forms. When rate limiting is on, appears only after the limit is hit. Otherwise always visible.', 'codewp-shield-monitor' ) ); ?>
					<?php submit_button( __( 'Save comment spam settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Registration spam -->
			<div class="sp-inner-panel" data-inner="spam-registration" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<p class="description"><?php esc_html_e( 'Limit automated registrations and require a math challenge when limits are exceeded.', 'codewp-shield-monitor' ); ?></p>
					<?php $this->checkbox( 'registration_rate_limit_enabled', __( 'Registration rate limiting', 'codewp-shield-monitor' ), __( 'Limit registrations per IP. When exceeded, a math CAPTCHA is required to continue.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits">
						<label><?php esc_html_e( 'Max registrations per IP', 'codewp-shield-monitor' ); ?><input type="number" min="1" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[registration_rate_limit_max]" value="<?php echo esc_attr( $this->settings->get( 'registration_rate_limit_max' ) ); ?>"></label>
						<label><?php esc_html_e( 'Time window (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="1" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[registration_rate_limit_window]" value="<?php echo esc_attr( $this->settings->get( 'registration_rate_limit_window' ) ); ?>"></label>
					</div>
					<div class="sp-note" style="margin-top:16px"><p><?php esc_html_e( 'When an IP exceeds the registration limit, a math question (e.g. 7 + 4 = ?) must be answered to proceed.', 'codewp-shield-monitor' ); ?></p></div>
					<?php submit_button( __( 'Save registration spam settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	private function database_security_panel() {
		global $wpdb;
		$db_cleanup = new Shield_Monitor_DB_Cleanup( $this->settings, $this->logger );
		$stats = $db_cleanup->stats();
		$cleanup_nonce = wp_create_nonce( 'shield_monitor_db_cleanup' );
		$cleanup_items = array(
			'spam_comments'        => array( __( 'Spam comments', 'codewp-shield-monitor' ), __( 'Comments marked as spam by WordPress or anti-spam plugins.', 'codewp-shield-monitor' ) ),
			'unapproved_comments'  => array( __( 'Unapproved comments', 'codewp-shield-monitor' ), __( 'Pending comments that have not been approved.', 'codewp-shield-monitor' ) ),
			'trashed_comments'     => array( __( 'Trashed comments', 'codewp-shield-monitor' ), __( 'Comments moved to trash.', 'codewp-shield-monitor' ) ),
			'trashed_posts'        => array( __( 'Trashed posts', 'codewp-shield-monitor' ), __( 'Posts, pages, and products in the trash.', 'codewp-shield-monitor' ) ),
			'post_revisions'       => array( __( 'Post revisions', 'codewp-shield-monitor' ), __( 'Old revisions of posts and pages.', 'codewp-shield-monitor' ) ),
			'auto_drafts'          => array( __( 'Auto-drafts', 'codewp-shield-monitor' ), __( 'Automatically saved drafts that were never published.', 'codewp-shield-monitor' ) ),
			'expired_transients'   => array( __( 'Expired transients', 'codewp-shield-monitor' ), __( 'Temporary cached data that has already expired.', 'codewp-shield-monitor' ) ),
			'orphaned_postmeta'    => array( __( 'Orphaned post meta', 'codewp-shield-monitor' ), __( 'Post metadata for posts that no longer exist.', 'codewp-shield-monitor' ) ),
			'orphaned_commentmeta' => array( __( 'Orphaned comment meta', 'codewp-shield-monitor' ), __( 'Comment metadata for comments that no longer exist.', 'codewp-shield-monitor' ) ),
			'spam_users'           => array( __( 'Inactive subscribers', 'codewp-shield-monitor' ), __( 'Subscriber accounts with no posts, registered more than 7 days ago.', 'codewp-shield-monitor' ) ),
		);
		?>
		<section id="database-security" class="shield_monitor-section sp-database-security">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'DATABASE', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Database security and cleanup', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Review database hardening and clean up spam, revisions, orphaned data, and expired transients.', 'codewp-shield-monitor' ); ?></p></div></div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="db-prefix"><?php esc_html_e( 'Table prefix', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="db-cleanup"><?php esc_html_e( 'Cleanup', 'codewp-shield-monitor' ); ?></button>
			</div>

			<div class="sp-inner-panel" data-inner="db-prefix">
				<div class="sp-prefix-tool">
					<div>
						<h3><?php esc_html_e( 'Database table prefix', 'codewp-shield-monitor' ); ?></h3>
						<p><?php echo esc_html( sprintf( __( 'Current prefix: %s', 'codewp-shield-monitor' ), $wpdb->prefix ) ); ?></p>
						<p class="description"><?php esc_html_e( 'Changing the table prefix requires database changes and a matching wp-config.php update. For safety and WordPress.org policy compatibility, this plugin does not perform that operation automatically.', 'codewp-shield-monitor' ); ?></p>
					</div>
				</div>
			</div>

			<div class="sp-inner-panel" data-inner="db-cleanup" hidden>
				<div class="sp-table-wrap" style="max-height:none">
					<table class="sp-events" id="sp-db-cleanup-table">
						<thead><tr><th><?php esc_html_e( 'Item', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Description', 'codewp-shield-monitor' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Count', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'codewp-shield-monitor' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $cleanup_items as $key => $item ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $item[0] ); ?></strong></td>
									<td><small><?php echo esc_html( $item[1] ); ?></small></td>
									<td style="text-align:right"><span class="sp-cleanup-count" data-task="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $stats[ $key ] ?? 0 ); ?></span></td>
									<td><button type="button" class="button sp-cleanup-btn" data-task="<?php echo esc_attr( $key ); ?>" <?php disabled( ( $stats[ $key ] ?? 0 ) === 0 ); ?>><?php esc_html_e( 'Clean', 'codewp-shield-monitor' ); ?></button></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="sp-note" style="margin-top:16px">
					<p><?php esc_html_e( 'Cleanup actions are irreversible. Please make a database backup before cleaning large amounts of data.', 'codewp-shield-monitor' ); ?></p>
				</div>
			</div>

			<script>
			(function(){
				var nonce = <?php echo wp_json_encode( $cleanup_nonce ); ?>;
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

				function updateStats(stats) {
					Object.keys(stats).forEach(function(k){
						var el = document.querySelector('.sp-cleanup-count[data-task="'+k+'"]');
						if (el) el.textContent = stats[k];
						var b = document.querySelector('.sp-cleanup-btn[data-task="'+k+'"]');
						if (b && !b.classList.contains('is-running')) {
							b.disabled = stats[k] === 0;
						}
					});
				}

				function runBatch(task, btn, totalDeleted) {
					var fd = new FormData();
					fd.append('action', 'shield_monitor_db_cleanup');
					fd.append('_nonce', nonce);
					fd.append('task', task);
					fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
						.then(function(r){return r.json();})
						.then(function(r){
							if (!r.success) {
								btn.textContent = <?php echo wp_json_encode( __( 'Clean', 'codewp-shield-monitor' ) ); ?>;
								btn.disabled = false;
								btn.classList.remove('is-running');
								alert(r.data && r.data.message || 'Error');
								return;
							}
							totalDeleted += r.data.deleted;
							updateStats(r.data.stats);

							if (r.data.done) {
								btn.textContent = <?php echo wp_json_encode( __( 'Done!', 'codewp-shield-monitor' ) ); ?>;
								btn.classList.remove('is-running');
								setTimeout(function(){
									btn.textContent = <?php echo wp_json_encode( __( 'Clean', 'codewp-shield-monitor' ) ); ?>;
									btn.disabled = r.data.remaining === 0;
								}, 1500);
							} else {
								btn.textContent = <?php echo wp_json_encode( __( 'Cleaning...', 'codewp-shield-monitor' ) ); ?> + ' (' + r.data.remaining + ' ' + <?php echo wp_json_encode( __( 'remaining', 'codewp-shield-monitor' ) ); ?> + ')';
								setTimeout(function(){ runBatch(task, btn, totalDeleted); }, 200);
							}
						})
						.catch(function(){
							btn.textContent = <?php echo wp_json_encode( __( 'Clean', 'codewp-shield-monitor' ) ); ?>;
							btn.disabled = false;
							btn.classList.remove('is-running');
						});
				}

				document.querySelectorAll('.sp-cleanup-btn').forEach(function(btn){
					btn.addEventListener('click', function(){
						if (!confirm(<?php echo wp_json_encode( __( 'Are you sure? This action cannot be undone.', 'codewp-shield-monitor' ) ); ?>)) return;
						var task = this.dataset.task;
						this.disabled = true;
						this.classList.add('is-running');
						this.textContent = <?php echo wp_json_encode( __( 'Cleaning...', 'codewp-shield-monitor' ) ); ?>;
						runBatch(task, this, 0);
					});
				});
			})();
			</script>
		</section>
		<?php
	}

	private function media_optimization_panel() {
		$can_webp = ( function_exists( 'imagewebp' ) || ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true ) ) );
		$all_sizes = array(
			'thumbnail'    => __( 'Thumbnail (150x150)', 'codewp-shield-monitor' ),
			'medium'       => __( 'Medium (300x300)', 'codewp-shield-monitor' ),
			'medium_large' => __( 'Medium Large (768px)', 'codewp-shield-monitor' ),
			'large'        => __( 'Large (1024x1024)', 'codewp-shield-monitor' ),
			'1536x1536'    => __( '2x Medium Large (1536x1536)', 'codewp-shield-monitor' ),
			'2048x2048'    => __( '2x Large (2048x2048)', 'codewp-shield-monitor' ),
		);
		$selected_sizes = array_map( 'sanitize_key', (array) $this->settings->get( 'keep_image_sizes' ) );
		?>
		<section id="media-optimization" class="shield_monitor-section">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'MEDIA OPTIMIZATION', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Image sizes, upload limits, and WebP conversion', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Control image sizes, limit upload file size, auto-resize large images, and convert to WebP for faster loading.', 'codewp-shield-monitor' ); ?></p></div></div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="media-sizes"><?php esc_html_e( 'Image sizes', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="media-upload-limit"><?php esc_html_e( 'Upload limit', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="media-auto-resize"><?php esc_html_e( 'Auto resize', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="media-webp"><?php esc_html_e( 'WebP conversion', 'codewp-shield-monitor' ); ?></button>
			</div>

			<div class="sp-inner-panel" data-inner="media-sizes">
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[keep_image_sizes_submitted]" value="1">
					<?php $this->checkbox( 'disable_image_sizes', __( 'Disable extra image sizes', 'codewp-shield-monitor' ), __( 'Stop WordPress from generating multiple resized copies of each uploaded image. Only the original file is kept. Also disables the 2560px big image scaling.', 'codewp-shield-monitor' ) ); ?>
					<div style="margin-top:16px">
						<p class="description"><?php esc_html_e( 'If enabled, select which sizes to keep generating (leave all unchecked to keep only the original):', 'codewp-shield-monitor' ); ?></p>
						<?php foreach ( $all_sizes as $size_key => $size_label ) : ?>
							<label class="shield_monitor-switch-row sp-compact">
								<span><strong><?php echo esc_html( $size_label ); ?></strong><small><?php echo esc_html( $size_key ); ?></small></span>
								<input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[keep_image_sizes][]" value="<?php echo esc_attr( $size_key ); ?>" <?php checked( in_array( $size_key, $selected_sizes, true ) ); ?>>
								<i aria-hidden="true"></i>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="sp-note" style="margin-top:16px">
						<p><?php esc_html_e( 'This only affects future uploads. Existing images are not changed. Some themes may need specific sizes to display correctly.', 'codewp-shield-monitor' ); ?></p>
					</div>
					<?php submit_button( __( 'Save image settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<div class="sp-inner-panel" data-inner="media-upload-limit" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<?php $this->checkbox( 'upload_limit_enabled', __( 'Limit image upload file size', 'codewp-shield-monitor' ), __( 'Reject images that exceed the configured maximum file size. Only applies to uploads from post editor, WooCommerce, etc. — uploads from Media Library are skipped.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits" style="margin-top:16px">
						<label><?php esc_html_e( 'Maximum file size (KB)', 'codewp-shield-monitor' ); ?>
							<input type="number" min="100" max="20480" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[upload_max_size_kb]" value="<?php echo esc_attr( $this->settings->get( 'upload_max_size_kb' ) ); ?>">
							<small><?php esc_html_e( 'Default: 1024 KB (1 MB). Range: 100 KB – 20480 KB (20 MB). Only applies to image files.', 'codewp-shield-monitor' ); ?></small>
						</label>
					</div>
					<div class="sp-note" style="margin-top:16px">
						<p><?php
						printf(
							esc_html__( 'Current setting: images larger than %s will be rejected. Common sizes: 512 KB (strict), 1 MB (recommended), 2 MB (lenient).', 'codewp-shield-monitor' ),
							'<strong>' . esc_html( size_format( absint( $this->settings->get( 'upload_max_size_kb' ) ) * 1024 ) ) . '</strong>'
						);
						?></p>
					</div>
					<?php submit_button( __( 'Save upload limit', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<div class="sp-inner-panel" data-inner="media-auto-resize" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<?php $this->checkbox( 'image_resize_enabled', __( 'Auto-resize large images on upload', 'codewp-shield-monitor' ), __( 'Automatically resize images wider than the maximum width. Height is adjusted proportionally. Only applies to uploads from post editor, WooCommerce, etc. — uploads from Media Library are skipped so editors can upload banners and full-size originals.', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits" style="margin-top:16px">
						<label><?php esc_html_e( 'Maximum width (px)', 'codewp-shield-monitor' ); ?>
							<input type="number" min="200" max="10000" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[image_max_width]" value="<?php echo esc_attr( $this->settings->get( 'image_max_width' ) ); ?>">
							<small><?php esc_html_e( 'Default: 1920 px. Height is calculated automatically to maintain aspect ratio. Applies to JPEG and PNG only.', 'codewp-shield-monitor' ); ?></small>
						</label>
					</div>
					<div class="sp-note" style="margin-top:16px">
						<p><?php
						$max_w = absint( $this->settings->get( 'image_max_width' ) );
						printf(
							esc_html__( 'Example: A 4000x3000 image will be resized to %1$dx%2$d (proportional by width). Uploads from Media → Add New are always skipped.', 'codewp-shield-monitor' ),
							$max_w,
							absint( round( 3000 * $max_w / 4000 ) )
						);
						?></p>
					</div>
					<?php submit_button( __( 'Save resize settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<div class="sp-inner-panel" data-inner="media-webp" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>
					<?php if ( ! $can_webp ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 16px"><p><?php esc_html_e( 'WebP conversion requires PHP GD with WebP support or the Imagick extension. Neither is currently available on this server.', 'codewp-shield-monitor' ); ?></p></div>
					<?php endif; ?>
					<?php $this->checkbox( 'webp_convert_enabled', __( 'Auto-convert images to WebP on upload', 'codewp-shield-monitor' ), __( 'Automatically convert JPEG, PNG, and GIF images to WebP format when uploaded. Uploads from Media Library are skipped so editors can keep original format for banners.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'webp_keep_original', __( 'Keep original image file', 'codewp-shield-monitor' ), __( 'Keep the original JPEG/PNG/GIF file alongside the WebP version. Disable to save disk space (only the WebP file will be stored).', 'codewp-shield-monitor' ) ); ?>
					<div class="sp-login-limits" style="margin-top:16px">
						<label><?php esc_html_e( 'WebP quality', 'codewp-shield-monitor' ); ?>
							<input type="number" min="1" max="100" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[webp_quality]" value="<?php echo esc_attr( $this->settings->get( 'webp_quality' ) ); ?>">
							<small><?php esc_html_e( 'Default 82. Lower = smaller file, lower quality. Range: 1-100.', 'codewp-shield-monitor' ); ?></small>
						</label>
					</div>
					<div class="sp-note" style="margin-top:16px">
						<p><?php esc_html_e( 'WebP images are typically 25-35% smaller than JPEG/PNG with similar visual quality. All modern browsers support WebP. This only affects future uploads.', 'codewp-shield-monitor' ); ?></p>
					</div>
					<?php submit_button( __( 'Save WebP settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	// ── REST API Management Panel ───────────────────────────────────

	private function get_rest_namespace_map() {
		$cached = get_transient( 'shield_monitor_rest_ns_map' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = array();

		// Get all registered namespaces via REST server.
		$wp_rest_server = rest_get_server();
		$namespaces     = $wp_rest_server->get_namespaces();

		// Core and internal namespaces to exclude.
		$exclude = array( 'wp/v2', 'wp/v1', 'oembed/1.0', 'oembed' );
		if ( defined( 'CODEWP_SHIELD_MONITOR_API_NAMESPACE' ) ) {
			$exclude[] = CODEWP_SHIELD_MONITOR_API_NAMESPACE;
		}

		// Map of known namespace prefixes to plugin names.
		$known = array(
			'wc'               => 'WooCommerce',
			'wc-auth'          => 'WooCommerce Auth',
			'wp-abilities'     => 'WP Abilities',
			'contact-form-7'   => 'Contact Form 7',
			'rankmath'         => 'Rank Math SEO',
			'litespeed'        => 'LiteSpeed Cache',
			'cky'              => 'CookieYes',
			'font-awesome'     => 'Font Awesome',
			'jetpack'          => 'Jetpack',
			'elementor'        => 'Elementor',
			'wpforms'          => 'WPForms',
			'gravityforms'     => 'Gravity Forms',
			'yoast'            => 'Yoast SEO',
			'mcp'              => 'MCP Protocol',
			'wordfence'        => 'Wordfence',
			'updraftplus'      => 'UpdraftPlus',
			'fluentform'       => 'Fluent Forms',
			'surecart'         => 'SureCart',
			'wp-mail-smtp'     => 'WP Mail SMTP',
		);

		// Recommendation badges for namespaces that typically need public access.
		$recommendations = array(
			'contact-form-7/v1' => __( 'Required for form submissions', 'codewp-shield-monitor' ),
			'wc/store/v1'       => __( 'Required for Cart & Checkout blocks', 'codewp-shield-monitor' ),
			'wc/store'          => __( 'Required for Cart & Checkout blocks', 'codewp-shield-monitor' ),
			'cky/v1'            => __( 'Required for cookie consent banner', 'codewp-shield-monitor' ),
		);

		foreach ( $namespaces as $ns ) {
			if ( in_array( $ns, $exclude, true ) ) {
				continue;
			}

			$prefix = explode( '/', $ns )[0];
			$name   = $known[ $prefix ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $prefix ) );

			$map[] = array(
				'namespace'      => $ns,
				'plugin_name'    => $name,
				'recommendation' => $recommendations[ $ns ] ?? '',
			);
		}

		// Sort by plugin name.
		usort( $map, function ( $a, $b ) {
			return strcasecmp( $a['plugin_name'], $b['plugin_name'] );
		} );

		set_transient( 'shield_monitor_rest_ns_map', $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	private function rest_api_panel() {
		$ns_map       = $this->get_rest_namespace_map();
		$public_ns    = (array) $this->settings->get( 'rest_api_public_namespaces' );
		$restrict_on  = $this->settings->get( 'restrict_rest_api' );
		$option_name  = Shield_Monitor_Settings::OPTION;
		?>
		<section id="rest-api" class="shield_monitor-section">
			<div class="sp-section-head">
				<div>
					<span class="sp-kicker"><?php esc_html_e( 'REST API', 'codewp-shield-monitor' ); ?></span>
					<h2><?php esc_html_e( 'REST API access management', 'codewp-shield-monitor' ); ?></h2>
					<p><?php esc_html_e( 'Control which plugins can receive public REST API requests and restrict dangerous core API operations.', 'codewp-shield-monitor' ); ?></p>
				</div>
				<span class="sp-status <?php echo $restrict_on ? 'is-on' : 'is-off'; ?>"><i></i><?php echo esc_html( $restrict_on ? __( 'Restricted', 'codewp-shield-monitor' ) : __( 'Open', 'codewp-shield-monitor' ) ); ?></span>
			</div>

			<div class="sp-inner-tabs">
				<button class="sp-inner-tab is-active" data-target="rest-plugins"><?php esc_html_e( 'Plugin access', 'codewp-shield-monitor' ); ?></button>
				<button class="sp-inner-tab" data-target="rest-core"><?php esc_html_e( 'Core API controls', 'codewp-shield-monitor' ); ?></button>
			</div>

			<!-- Tab 1: Plugin namespace access -->
			<div class="sp-inner-panel" data-inner="rest-plugins">
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>

					<?php $this->checkbox( 'restrict_rest_api', __( 'Restrict public REST API', 'codewp-shield-monitor' ), __( 'Block anonymous REST API calls except the namespaces enabled below.', 'codewp-shield-monitor' ) ); ?>

					<div class="sp-note" style="margin:12px 0 16px;">
						<p><?php esc_html_e( 'When restriction is active, only logged-in users and the namespaces toggled below can access the REST API. ShieldPress and oEmbed endpoints are always allowed.', 'codewp-shield-monitor' ); ?></p>
					</div>

					<input type="hidden" name="<?php echo esc_attr( $option_name ); ?>[rest_api_public_namespaces_submitted]" value="1">

					<?php if ( empty( $ns_map ) ) : ?>
						<p class="description"><?php esc_html_e( 'No third-party REST API namespaces detected.', 'codewp-shield-monitor' ); ?></p>
					<?php else : ?>
						<div class="sp-ns-list">
						<?php foreach ( $ns_map as $ns_item ) :
							$checked = in_array( $ns_item['namespace'], $public_ns, true );
						?>
							<label class="shield_monitor-switch-row sp-compact">
								<span>
									<strong><?php echo esc_html( $ns_item['plugin_name'] ); ?></strong>
									<small><code><?php echo esc_html( $ns_item['namespace'] ); ?></code><?php
										if ( $ns_item['recommendation'] ) {
											echo ' &mdash; <em style="color:#e57722;">' . esc_html( $ns_item['recommendation'] ) . '</em>';
										}
									?></small>
								</span>
								<input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[rest_api_public_namespaces][]" value="<?php echo esc_attr( $ns_item['namespace'] ); ?>" <?php checked( $checked ); ?>>
								<i aria-hidden="true"></i>
							</label>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php submit_button( __( 'Save plugin access', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>

			<!-- Tab 2: Core API controls -->
			<div class="sp-inner-panel" data-inner="rest-core" hidden>
				<form action="options.php" method="post" class="sp-settings-form">
					<?php settings_fields( 'codewp-shield-monitor' ); ?>

					<div class="sp-note" style="margin:0 0 16px;">
						<p><?php esc_html_e( 'Block dangerous WordPress core REST API operations for non-administrator users. These controls work independently of the master toggle above.', 'codewp-shield-monitor' ); ?></p>
					</div>

					<?php $this->checkbox( 'rest_api_block_user_creation', __( 'Block user creation', 'codewp-shield-monitor' ), __( 'Prevent creating, editing, or deleting users via REST API (POST /wp/v2/users).', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_plugin_management', __( 'Block plugin management', 'codewp-shield-monitor' ), __( 'Hide and block plugin install, activate, deactivate, delete via REST API.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_settings_access', __( 'Block settings access', 'codewp-shield-monitor' ), __( 'Prevent reading and writing site settings via REST API (POST /wp/v2/settings).', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_export', __( 'Block site export', 'codewp-shield-monitor' ), __( 'Prevent full site export via REST API.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_app_passwords', __( 'Block application passwords', 'codewp-shield-monitor' ), __( 'Prevent creating API credentials via REST API.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_media_upload', __( 'Block media upload', 'codewp-shield-monitor' ), __( 'Prevent uploading files via REST API (POST /wp/v2/media). May affect Gutenberg for non-admin editors.', 'codewp-shield-monitor' ) ); ?>
					<?php $this->checkbox( 'rest_api_block_post_creation', __( 'Block post/page creation', 'codewp-shield-monitor' ), __( 'Prevent creating or editing posts/pages via REST API. Will block Gutenberg for non-admin users.', 'codewp-shield-monitor' ) ); ?>

					<?php submit_button( __( 'Save core API controls', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	private function score_tasks( $data, $updates ) {
		$security = $data['security'];
		$tasks    = array();
		$add      = function ( $passed, $title, $impact ) use ( &$tasks ) {
			if ( ! $passed ) {
				$tasks[] = array( 'title' => $title, 'impact' => $impact );
			}
		};
		$add( $security['https'], __( 'Enable HTTPS', 'codewp-shield-monitor' ), 20 );
		$add( $security['login_protection'], __( 'Enable login protection', 'codewp-shield-monitor' ), 10 );
		$add( $security['block_enumeration'], __( 'Block user enumeration', 'codewp-shield-monitor' ), 10 );
		$add( $security['security_headers'], __( 'Enable security headers', 'codewp-shield-monitor' ), 10 );
		$add( $security['file_editor_disabled'], __( 'Disable dashboard file editor', 'codewp-shield-monitor' ), 10 );
		$add( $security['file_integrity']['enabled'], __( 'Enable file integrity monitoring', 'codewp-shield-monitor' ), 10 );
		$add( $this->settings->get( 'email_alerts' ), __( 'Enable email alerts', 'codewp-shield-monitor' ), 5 );
		$add( ! $updates['core']['update_available'], __( 'Update WordPress core', 'codewp-shield-monitor' ), 15 );
		if ( $updates['plugins']['update_count'] + $updates['themes']['update_count'] ) {
			$tasks[] = array( 'title' => __( 'Update plugins and themes', 'codewp-shield-monitor' ), 'impact' => min( 15, ( $updates['plugins']['update_count'] + $updates['themes']['update_count'] ) * 2 ) );
		}
		$malware_summary = $security['malware_scan']['summary'] ?? array();
		if ( ! empty( $malware_summary['critical'] ) || ! empty( $malware_summary['high'] ) ) {
			$tasks[] = array( 'title' => __( 'Review high-risk malware findings', 'codewp-shield-monitor' ), 'impact' => 20 );
		}
		$threat_summary = $security['threat_intelligence']['summary'] ?? array();
		if ( ! empty( $threat_summary['critical'] ) || ! empty( $threat_summary['high'] ) ) {
			$tasks[] = array( 'title' => __( 'Review threat intelligence findings', 'codewp-shield-monitor' ), 'impact' => 15 );
		}
		if ( empty( $tasks ) ) {
			$tasks[] = array( 'title' => __( 'No score blockers detected', 'codewp-shield-monitor' ), 'impact' => 0 );
		}
		return $tasks;
	}

	public function render() {
		$data        = $this->status->collect();
		$security    = $data['security'];
		$files       = $security['file_integrity'];
		$malware     = $security['malware_scan'];
		$threats     = $security['threat_intelligence'];
		$login_protection = new Shield_Monitor_Login_Protection( $this->settings, $this->logger );
		$login_lockouts = $login_protection->lockout_rows();
		$malware_summary = $malware['summary'];
		$pairing     = $this->api->create_pairing_payload();
		$tokens      = $this->api->tokens();
		$history_logs = $this->logger->recent_since( 30 * DAY_IN_SECONDS, 200 );
		$updates     = $data['updates']['plugins']['update_count'] + $data['updates']['themes']['update_count'] + ( $data['updates']['core']['update_available'] ? 1 : 0 );
		$score_tone  = $data['health_score'] >= 85 ? 'strong' : ( $data['health_score'] >= 65 ? 'review' : 'risk' );
		$score_label = $data['health_score'] >= 85 ? __( 'Protected', 'codewp-shield-monitor' ) : ( $data['health_score'] >= 65 ? __( 'Needs review', 'codewp-shield-monitor' ) : __( 'At risk', 'codewp-shield-monitor' ) );
		$score_detail = $data['health_score'] >= 85 ? __( 'Core protections are active', 'codewp-shield-monitor' ) : ( $data['health_score'] >= 65 ? __( 'A few items need attention', 'codewp-shield-monitor' ) : __( 'Priority fixes recommended', 'codewp-shield-monitor' ) );
		$score_tasks = $this->score_tasks( $data, $data['updates'] );
		$cf7_active = ! empty( $data['integrations']['contact_form_7']['detected'] );
		$captcha_active = ! empty( $data['integrations']['captcha']['detected'] );
		$captcha_forms = array_map( 'sanitize_key', (array) $this->settings->get( 'captcha_forms' ) );
		$woo_active = function_exists( 'wc_get_orders' );
		?>
		<div class="wrap shield_monitor-wrap sp-dashboard sp-has-sidebar">
			<h1 class="wp-heading-inline" style="display:none;"><?php esc_html_e( 'Shield Monitor', 'codewp-shield-monitor' ); ?></h1>
			<hr class="wp-header-end" style="margin:0;border:0;height:0;">
			<header class="sp-hero"><div class="sp-brand"><div><h1><?php esc_html_e( 'Shield Monitor', 'codewp-shield-monitor' ); ?></h1><p><?php esc_html_e( 'Protection, integrity monitoring, and security alerts for WordPress.', 'codewp-shield-monitor' ); ?></p><small class="sp-brand-meta"><a href="<?php echo esc_url( SHIELD_MONITOR_APP_URL ); ?>" target="_blank" rel="noopener noreferrer">shieldpress.net</a><span>v<?php echo esc_html( SHIELD_MONITOR_VERSION ); ?></span></small></div></div><div class="sp-score sp-score-<?php echo esc_attr( $score_tone ); ?>"><div class="sp-score-ring" style="--score:<?php echo esc_attr( $data['health_score'] ); ?>"><strong><?php echo esc_html( $data['health_score'] ); ?></strong><small>/100</small></div><div class="sp-score-copy"><em><?php esc_html_e( 'Security score', 'codewp-shield-monitor' ); ?></em><span><?php echo esc_html( $score_label ); ?></span><small><?php echo esc_html( $score_detail ); ?></small></div></div></header>

			<div class="sp-body">
				<nav class="sp-sidebar">
					<a href="#overview" class="sp-sidebar-item is-active" data-section="overview"><strong><?php esc_html_e( 'Overview', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Security score, stats, and quick status of all protections.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#login-access" class="sp-sidebar-item" data-section="login-access"><strong><?php esc_html_e( 'Login access', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Hidden login URL, brute force lockout, quick login, and failed IP management.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#advanced-hardening" class="sp-sidebar-item" data-section="advanced-hardening"><strong><?php esc_html_e( 'Advanced hardening', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'CAPTCHA challenges, admin PIN, software lock, and file editor controls.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#anti-spam" class="sp-sidebar-item" data-section="anti-spam"><strong><?php esc_html_e( 'Anti-spam', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'WAF firewall, honeypot, comment/registration rate limiting, and math CAPTCHA.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#rest-api" class="sp-sidebar-item" data-section="rest-api"><strong><?php esc_html_e( 'REST API', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Plugin namespace access control and core endpoint restrictions.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#threat-patterns" class="sp-sidebar-item" data-section="threat-patterns"><strong><?php esc_html_e( 'Threat patterns', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Custom malware signatures, WAF rules, and database scan patterns.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#database-security" class="sp-sidebar-item" data-section="database-security"><strong><?php esc_html_e( 'Database', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Table prefix review, spam/revision/transient cleanup tools.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#media-optimization" class="sp-sidebar-item" data-section="media-optimization"><strong><?php esc_html_e( 'Media', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Image sizes, upload limits, auto-resize, and WebP conversion.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#integrity" class="sp-sidebar-item" data-section="integrity"><strong><?php esc_html_e( 'File integrity', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'SHA-256 hash monitoring for wp-config, plugins, themes, and core files.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#malware" class="sp-sidebar-item" data-section="malware"><strong><?php esc_html_e( 'Malware scan', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Heuristic file and database scanning for suspicious code and injections.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#threats" class="sp-sidebar-item" data-section="threats"><strong><?php esc_html_e( 'Threat intelligence', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Local checks for admin anomalies, executable uploads, and cloaking signals.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#logs" class="sp-sidebar-item" data-section="logs"><strong><?php esc_html_e( '30-day logs', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Full audit trail of logins, file changes, software updates, and scan results.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#recommendations" class="sp-sidebar-item" data-section="recommendations"><strong><?php esc_html_e( 'Recommendations', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Guided checklist of security improvements for your WordPress site.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#connect" class="sp-sidebar-item" data-section="connect"><strong><?php esc_html_e( 'App / Web', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Connect the ShieldPress mobile app or web dashboard via QR code.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#settings" class="sp-sidebar-item" data-section="settings"><strong><?php esc_html_e( 'Settings', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Protection toggles, email alert preferences, and scan schedule.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#notifications" class="sp-sidebar-item" data-section="notifications"><strong><?php esc_html_e( 'Notifications', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Push events for Contact Form 7, WooCommerce orders, and custom post types.', 'codewp-shield-monitor' ); ?></span></a>
					<a href="#system-info" class="sp-sidebar-item" data-section="system-info"><strong><?php esc_html_e( 'System Info', 'codewp-shield-monitor' ); ?></strong><span><?php esc_html_e( 'Server environment, PHP config, plugins, theme, and export for support.', 'codewp-shield-monitor' ); ?></span></a>
				</nav>

				<div class="sp-content">
					<?php settings_errors(); ?>
					<?php if ( defined( 'CODEWP_SHIELD_MONITOR_DISABLE_LOGIN_CHALLENGES' ) && CODEWP_SHIELD_MONITOR_DISABLE_LOGIN_CHALLENGES ) : ?>
						<div class="notice notice-error"><p><?php esc_html_e( 'Shield Monitor login CAPTCHA and Staff PIN checks are disabled by CODEWP_SHIELD_MONITOR_DISABLE_LOGIN_CHALLENGES. Remove this recovery constant after regaining access.', 'codewp-shield-monitor' ); ?></p></div>
					<?php endif; ?>

					<div class="sp-panel" data-panel="overview">
					<section id="overview" class="sp-stat-grid">
				<?php $this->stat_card( 'dashicons-lock', __( 'HTTPS', 'codewp-shield-monitor' ), $security['https'] ? __( 'Secure', 'codewp-shield-monitor' ) : __( 'Not secure', 'codewp-shield-monitor' ), __( 'Encrypted website connection', 'codewp-shield-monitor' ), $security['https'] ? 'green' : 'red' ); ?>
				<?php $this->stat_card( 'dashicons-admin-users', __( 'Admin logins', 'codewp-shield-monitor' ), $security['admin_logins_30_days'], __( 'Recorded in the last 30 days', 'codewp-shield-monitor' ), 'blue' ); ?>
				<?php $this->stat_card( 'dashicons-dismiss', __( 'Failed logins', 'codewp-shield-monitor' ), $security['failed_logins_30_days'], __( 'Failed or blocked in 30 days', 'codewp-shield-monitor' ), $security['failed_logins_30_days'] ? 'amber' : 'green' ); ?>
				<?php $this->stat_card( 'dashicons-media-code', __( 'File changes', 'codewp-shield-monitor' ), $files['changes_30_days'], __( 'Detected in the last 30 days', 'codewp-shield-monitor' ), $files['changes_30_days'] ? 'amber' : 'green' ); ?>
				<?php $this->stat_card( 'dashicons-warning', __( 'Threat findings', 'codewp-shield-monitor' ), absint( $threats['summary']['total'] ?? 0 ), __( 'Review signals from local intelligence checks', 'codewp-shield-monitor' ), ! empty( $threats['summary']['critical'] ) || ! empty( $threats['summary']['high'] ) ? 'red' : ( ! empty( $threats['summary']['medium'] ) ? 'amber' : 'green' ) ); ?>
			</section>

			<section class="shield_monitor-section sp-score-tasks"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'SCORE REVIEW', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Items affecting your security score', 'codewp-shield-monitor' ); ?></h2></div></div><ul><?php foreach ( $score_tasks as $task ) : ?><li><span><?php echo esc_html( $task['title'] ); ?></span><em><?php echo $task['impact'] ? esc_html( sprintf( __( '-%d pts', 'codewp-shield-monitor' ), absint( $task['impact'] ) ) ) : esc_html__( 'OK', 'codewp-shield-monitor' ); ?></em></li><?php endforeach; ?></ul></section>
					</div><!-- /.sp-panel overview -->

					<div class="sp-panel" data-panel="login-access" hidden>
					<?php $this->login_access_panel( $login_lockouts ); ?>
					</div>

					<div class="sp-panel" data-panel="advanced-hardening" hidden>
					<?php $this->advanced_hardening_panel(); ?>
					</div>

					<div class="sp-panel" data-panel="anti-spam" hidden>
					<?php $this->anti_spam_panel(); ?>
					</div>

					<div class="sp-panel" data-panel="rest-api" hidden>
					<?php $this->rest_api_panel(); ?>
					</div>

					<div class="sp-panel" data-panel="threat-patterns" hidden>
					<?php $this->threat_patterns_panel(); ?>
					</div>

					<div class="sp-panel" data-panel="database-security" hidden>
					<?php $this->database_security_panel(); ?>
					</div>

					<div class="sp-panel" data-panel="media-optimization" hidden>
					<?php $this->media_optimization_panel(); ?>
					</div>

			<div class="sp-panel" data-panel="integrity" hidden>
					<section id="integrity" class="shield_monitor-section sp-integrity"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'FILE PROTECTION', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Important file integrity', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Shield Monitor checks cryptographic hashes every five minutes and alerts you when a monitored file changes.', 'codewp-shield-monitor' ); ?></p></div><span class="sp-status <?php echo $files['enabled'] ? 'is-on' : 'is-off'; ?>"><i></i><?php echo esc_html( $files['enabled'] ? __( 'Monitoring active', 'codewp-shield-monitor' ) : __( 'Monitoring disabled', 'codewp-shield-monitor' ) ); ?></span></div><div class="sp-integrity-grid"><div><strong><?php echo esc_html( $files['monitored_files'] ); ?></strong><span><?php esc_html_e( 'Important files monitored', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $files['writable_files'] ); ?></strong><span><?php esc_html_e( 'Currently writable files', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $files['last_scan'] ?: __( 'Waiting for first scan', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last integrity scan', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $files['baseline_summary']['modified'] ?? 0 ) + absint( $files['baseline_summary']['deleted'] ?? 0 ) + absint( $files['baseline_summary']['added'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Changed baseline files', 'codewp-shield-monitor' ); ?></span></div></div><div class="sp-integrity-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_trust_file_baseline"><?php wp_nonce_field( 'shield_monitor_trust_file_baseline' ); ?><button class="button" type="submit"><?php esc_html_e( 'Trust current baseline', 'codewp-shield-monitor' ); ?></button></form></div><div class="sp-note"><p><?php esc_html_e( 'Shield Monitor monitors wp-config.php, .htaccess, WordPress bootstrap/login files, the active theme, and active plugin entry files. It stores path, size, MD5, SHA-256, first seen time, last seen time, last changed time, and status; never file contents.', 'codewp-shield-monitor' ); ?></p></div></section>

			</div><!-- /.sp-panel integrity -->

					<div class="sp-panel" data-panel="logs" hidden>
					<section id="logs" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( '30-DAY HISTORY', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Security audit history', 'codewp-shield-monitor' ); ?></h2></div><span class="sp-retention"><?php esc_html_e( 'Automatically deleted after 30 days', 'codewp-shield-monitor' ); ?></span></div><?php $this->events_table( $history_logs ); ?></section>

			</div><!-- /.sp-panel logs -->

					<div class="sp-panel" data-panel="malware" hidden>
					<section id="malware" class="shield_monitor-section sp-malware-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'MALWARE SCAN', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Suspicious files and database records', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Manual scans run in small batches with progress updates to reduce CPU and memory spikes.', 'codewp-shield-monitor' ); ?></p></div><div class="sp-malware-summary"><strong><?php echo esc_html( strtoupper( $malware_summary['risk_level'] ?? 'unknown' ) ); ?></strong><span><?php echo esc_html( sprintf( __( '%d findings', 'codewp-shield-monitor' ), absint( $malware_summary['total'] ?? 0 ) ) ); ?></span><div class="sp-malware-actions"><button class="button button-primary" id="shield_monitor-malware-scan-start" type="button"><?php esc_html_e( 'Run scan now', 'codewp-shield-monitor' ); ?></button><?php if ( ! empty( $malware['download_url'] ) ) : ?><a class="button" href="<?php echo esc_url( $malware['download_url'] ); ?>"><?php esc_html_e( 'Download report', 'codewp-shield-monitor' ); ?></a><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_clear_malware_report"><?php wp_nonce_field( 'shield_monitor_clear_malware_report' ); ?><button class="button" type="submit"><?php esc_html_e( 'Clear log', 'codewp-shield-monitor' ); ?></button></form></div></div></div><div class="sp-scan-progress" id="shield_monitor-malware-progress" hidden><div class="sp-scan-progress-head"><strong><?php esc_html_e( 'Manual scan progress', 'codewp-shield-monitor' ); ?></strong><span id="shield_monitor-malware-progress-label">0%</span></div><div class="sp-scan-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i style="width:0%"></i></div><small id="shield_monitor-malware-progress-detail"><?php esc_html_e( 'Preparing scan...', 'codewp-shield-monitor' ); ?></small></div><div class="sp-malware-metrics"><div><strong><?php echo esc_html( absint( $malware_summary['critical'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Critical', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $malware_summary['high'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'High', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $malware_summary['medium'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Medium', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $malware['last_scan_at'] ?: __( 'Not run yet', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last scan', 'codewp-shield-monitor' ); ?></span></div></div><?php $this->malware_table( $malware ); ?></section>

			</div><!-- /.sp-panel malware -->

					<div class="sp-panel" data-panel="threats" hidden>
					<section id="threats" class="shield_monitor-section sp-malware-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'THREAT INTELLIGENCE', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'WordPress compromise signals', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Local checks for administrator anomalies, executable uploads, suspicious options, cron entries, fake CAPTCHA content, external scripts, cloaking signals, and hardening gaps.', 'codewp-shield-monitor' ); ?></p></div><div class="sp-malware-summary"><strong><?php echo esc_html( strtoupper( $threats['summary']['risk_level'] ?? 'clean' ) ); ?></strong><span><?php echo esc_html( sprintf( __( '%d findings', 'codewp-shield-monitor' ), absint( $threats['summary']['total'] ?? 0 ) ) ); ?></span></div></div><div class="sp-malware-metrics"><div><strong><?php echo esc_html( absint( $threats['summary']['critical'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Critical', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $threats['summary']['high'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'High', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $threats['summary']['medium'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Medium', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $threats['generated_at'] ?? __( 'Not run yet', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last intelligence check', 'codewp-shield-monitor' ); ?></span></div></div><?php $this->threat_table( $threats ); ?><?php if ( ! empty( $threats['incident_report']['next_steps'] ) ) : ?><div class="sp-note"><strong><?php esc_html_e( 'Incident response next steps', 'codewp-shield-monitor' ); ?></strong><ul><?php foreach ( $threats['incident_report']['next_steps'] as $step ) : ?><li><?php echo esc_html( $step ); ?></li><?php endforeach; ?></ul></div><?php endif; ?></section>
					</div>

					<div class="sp-panel" data-panel="recommendations" hidden>
					<section id="recommendations" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'HARDENING GUIDE', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Recommended security improvements', 'codewp-shield-monitor' ); ?></h2></div></div><ul class="sp-recommendations"><?php $this->recommendation( $security['https'], __( 'Use HTTPS everywhere', 'codewp-shield-monitor' ), __( 'Install a valid SSL certificate and redirect all HTTP traffic to HTTPS.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( 0 === $updates, __( 'Keep WordPress software updated', 'codewp-shield-monitor' ), $updates ? sprintf( __( '%d WordPress, plugin, or theme updates are pending.', 'codewp-shield-monitor' ), $updates ) : __( 'WordPress core, plugins, and themes have no known pending updates.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['login_protection'], __( 'Protect the login form', 'codewp-shield-monitor' ), __( 'Limit repeated login attempts and use strong unique administrator passwords.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $this->settings->get( 'captcha_enabled' ) && in_array( 'login', $captcha_forms, true ), __( 'Add CAPTCHA to login forms', 'codewp-shield-monitor' ), __( 'Use Cloudflare Turnstile or Google reCAPTCHA on WordPress, WooCommerce, and theme login forms.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! $woo_active || ( $this->settings->get( 'captcha_enabled' ) && in_array( 'checkout', $captcha_forms, true ) ), __( 'Protect WooCommerce checkout', 'codewp-shield-monitor' ), __( 'For ecommerce sites, enable CAPTCHA on checkout to reduce automated orders and card testing.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $files['enabled'], __( 'Monitor important files', 'codewp-shield-monitor' ), __( 'Detect unexpected edits, additions, and deletions to security-sensitive files.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $this->settings->get( 'email_alerts' ), __( 'Enable security email alerts', 'codewp-shield-monitor' ), __( 'Receive alerts for administrator logins, blocked attacks, file changes, and scan findings.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['two_factor_authentication']['detected'] ), __( 'Enable two-factor authentication', 'codewp-shield-monitor' ), __( 'Require a second authentication factor for every administrator account.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['backup']['detected'] ), __( 'Install a backup solution', 'codewp-shield-monitor' ), __( 'Keep automatic off-site backups and regularly test that restoration works.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['cache']['detected'] ), __( 'Install a cache plugin', 'codewp-shield-monitor' ), __( 'Use a trusted cache/performance plugin to reduce server load during traffic spikes and automated attacks.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! $cf7_active || $captcha_active, __( 'Protect Contact Form 7 with CAPTCHA', 'codewp-shield-monitor' ), __( 'If Contact Form 7 is active, configure reCAPTCHA, hCaptcha, Turnstile, or another anti-spam challenge to reduce automated spam.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['xmlrpc_disabled'], __( 'Disable XML-RPC when unused', 'codewp-shield-monitor' ), __( 'Turn off XML-RPC if the site does not use Jetpack, mobile apps, or remote publishing.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['heartbeat_limited'], __( 'Limit WordPress Heartbeat XHR', 'codewp-shield-monitor' ), __( 'Reduce background admin-ajax polling to lower load on shared hosting.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['rest_api_restricted'], __( 'Restrict public REST API if possible', 'codewp-shield-monitor' ), __( 'Block anonymous REST API access when the theme and plugins do not need public REST endpoints.', 'codewp-shield-monitor' ) ); ?></ul></section>
					</div>

					<div class="sp-panel" data-panel="connect" hidden>
					<?php $this->connect_section( $pairing, $tokens ); ?>
					</div>

					<div class="sp-panel" data-panel="settings" hidden>
					<section id="settings" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'CONFIGURATION', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Security and alert settings', 'codewp-shield-monitor' ); ?></h2></div><span class="sp-retention"><?php esc_html_e( 'Logs retained for 30 days', 'codewp-shield-monitor' ); ?></span></div><form action="options.php" method="post" class="sp-settings-form"><?php settings_fields( 'codewp-shield-monitor' ); ?><div class="sp-settings-grid"><div><h3><?php esc_html_e( 'Protection', 'codewp-shield-monitor' ); ?></h3><?php $this->checkbox( 'login_protection', __( 'Login protection', 'codewp-shield-monitor' ), __( 'Lock repeated failed login attempts.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'file_monitoring', __( 'Important file monitoring', 'codewp-shield-monitor' ), __( 'Scan important file hashes every five minutes.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'malware_scanning', __( 'Suspicious code and database scanning', 'codewp-shield-monitor' ), __( 'Run lightweight heuristic scans at night and after administrator logins with cooldown protection.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'core_checksum_verification', __( 'WordPress core checksum verification', 'codewp-shield-monitor' ), __( 'Compare core files with official WordPress.org checksums every 12 hours.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'block_enumeration', __( 'Block user enumeration', 'codewp-shield-monitor' ), __( 'Restrict public author and REST user discovery.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'disable_xmlrpc', __( 'Disable XML-RPC', 'codewp-shield-monitor' ), __( 'Enable only if the site does not use Jetpack, mobile apps, or remote publishing.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'limit_heartbeat', __( 'Limit Heartbeat XHR', 'codewp-shield-monitor' ), __( 'Slow admin-ajax heartbeat polling and disable it on the public frontend.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'security_headers', __( 'Security headers', 'codewp-shield-monitor' ), __( 'Add safe browser protection headers.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'disable_file_editor', __( 'Disable dashboard file editor', 'codewp-shield-monitor' ), __( 'Prevent code editing from the WordPress dashboard.', 'codewp-shield-monitor' ) ); ?></div><div><h3><?php esc_html_e( 'Email alerts', 'codewp-shield-monitor' ); ?></h3><?php $this->checkbox( 'email_alerts', __( 'Enable email alerts', 'codewp-shield-monitor' ), sprintf( __( 'Send security alerts to %s.', 'codewp-shield-monitor' ), get_option( 'admin_email' ) ) ); ?><?php $this->checkbox( 'alert_admin_login', __( 'Administrator login alerts', 'codewp-shield-monitor' ), __( 'Alert whenever an administrator signs in.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_admin_user_changes', __( 'New administrator alerts', 'codewp-shield-monitor' ), __( 'Alert when a new user receives administrator access.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_lockouts', __( 'Login attack alerts', 'codewp-shield-monitor' ), __( 'Alert when repeated login attempts are blocked.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_file_changes', __( 'File change alerts', 'codewp-shield-monitor' ), __( 'Alert when monitored files are added, edited, or deleted.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_malware_findings', __( 'Suspicious scan alerts', 'codewp-shield-monitor' ), __( 'Alert when the malware scanner finds high or critical suspicious items.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'audit_log', __( 'Local security logs', 'codewp-shield-monitor' ), __( 'Store login and file events locally for 30 days.', 'codewp-shield-monitor' ) ); ?></div></div><div class="sp-scan-schedule"><label><?php esc_html_e( 'Automatic malware scan', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[malware_scan_frequency]"><option value="daily" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'daily' ); ?>><?php esc_html_e( 'Every day', 'codewp-shield-monitor' ); ?></option><option value="weekly" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'weekly' ); ?>><?php esc_html_e( 'Every week', 'codewp-shield-monitor' ); ?></option><option value="monthly" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'monthly' ); ?>><?php esc_html_e( 'Every month', 'codewp-shield-monitor' ); ?></option></select></label><label><?php esc_html_e( 'Scan time', 'codewp-shield-monitor' ); ?><input type="time" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[malware_scan_time]" value="<?php echo esc_attr( $this->settings->get( 'malware_scan_time' ) ); ?>"></label></div><div class="sp-login-limits"><label><?php esc_html_e( 'Maximum login attempts', 'codewp-shield-monitor' ); ?><input type="number" min="3" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $this->settings->get( 'max_attempts' ) ); ?>"></label><label><?php esc_html_e( 'Lockout duration (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="5" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $this->settings->get( 'lockout_minutes' ) ); ?>"></label></div><?php submit_button( __( 'Save security settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?></form><?php $this->backup_status_section(); ?></section>
					</div>

					<div class="sp-panel" data-panel="notifications" hidden>
					<?php $this->notification_settings_section(); ?>
					</div>

					<div class="sp-panel" data-panel="system-info" hidden>
					<?php $this->system_info_panel(); ?>
					</div>

				</div><!-- /.sp-content -->
			</div><!-- /.sp-body -->
		</div>
		<?php
	}
}
