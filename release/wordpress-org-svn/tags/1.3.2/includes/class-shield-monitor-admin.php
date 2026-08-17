<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Admin {
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

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHIELD_MONITOR_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		add_menu_page( __( 'CodeWP Shield Monitor', 'codewp-shield-monitor' ), __( 'CodeWP Shield Monitor', 'codewp-shield-monitor' ), 'manage_options', 'codewp-shield-monitor', array( $this, 'page' ), 'dashicons-shield-alt', 81 );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_codewp-shield-monitor' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'shield_monitor-admin', SHIELD_MONITOR_URL . 'assets/css/admin.css', array(), SHIELD_MONITOR_VERSION );
		wp_enqueue_script( 'shield_monitor-admin', SHIELD_MONITOR_URL . 'assets/js/admin.js', array(), SHIELD_MONITOR_VERSION, true );
		wp_localize_script(
			'shield_monitor-admin',
			'ShieldMonitorAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'shield_monitor_malware_scan' ),
				'i18n'    => array(
					'scanStarting' => __( 'Starting scan...', 'codewp-shield-monitor' ),
					'scanRunning'  => __( 'Scanning...', 'codewp-shield-monitor' ),
					'scanComplete' => __( 'Scan complete. Refreshing report...', 'codewp-shield-monitor' ),
					'scanFailed'   => __( 'Scan could not finish. Please try again later.', 'codewp-shield-monitor' ),
				),
			)
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=codewp-shield-monitor' ) ) . '">' . esc_html__( 'Settings', 'codewp-shield-monitor' ) . '</a>' );
		return $links;
	}

	private function checkbox( $key, $label, $description ) {
		$value = $this->settings->get( $key );
		?>
		<label class="shield_monitor-option">
			<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="0" />
			<input type="checkbox" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value, 1 ); ?> />
			<span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $description ); ?></small></span>
		</label>
		<?php
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'codewp-shield-monitor' ) );
		}
		( new Shield_Monitor_Security_Dashboard( $this->settings, $this->logger, $this->status, $this->api ) )->render();
		return;
		$events = $this->logger->recent( 15 );
		?>
		<div class="wrap shield_monitor-wrap">
			<header><div><h1><?php esc_html_e( 'CodeWP Shield Monitor', 'codewp-shield-monitor' ); ?></h1><p><?php esc_html_e( 'Monitor. Protect. Alert.', 'codewp-shield-monitor' ); ?></p></div><a class="shield_monitor-version" href="<?php echo esc_url( SHIELD_MONITOR_APP_URL ); ?>" target="_blank" rel="noopener noreferrer">shieldpress.net</a></header>
			<?php settings_errors(); ?>
			<div class="shield_monitor-grid">
				<section class="shield_monitor-card">
					<h2><?php esc_html_e( 'Security settings', 'codewp-shield-monitor' ); ?></h2>
					<form action="options.php" method="post">
						<?php settings_fields( 'codewp-shield-monitor' ); ?>
						<?php $this->checkbox( 'login_protection', __( 'Login protection', 'codewp-shield-monitor' ), __( 'Temporarily locks an IP after repeated failed logins.', 'codewp-shield-monitor' ) ); ?>
						<div class="shield_monitor-inline">
							<label><?php esc_html_e( 'Maximum attempts', 'codewp-shield-monitor' ); ?><input type="number" min="3" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $this->settings->get( 'max_attempts' ) ); ?>"></label>
							<label><?php esc_html_e( 'Lockout (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="5" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $this->settings->get( 'lockout_minutes' ) ); ?>"></label>
						</div>
						<?php $this->checkbox( 'block_enumeration', __( 'Block user enumeration', 'codewp-shield-monitor' ), __( 'Restricts public author queries and REST user endpoints.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'security_headers', __( 'Security headers', 'codewp-shield-monitor' ), __( 'Adds safe browser security headers without enforcing a CSP.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'hide_version', __( 'Hide WordPress version', 'codewp-shield-monitor' ), __( 'Removes version generator metadata from public pages.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'disable_file_editor', __( 'Disable file editor', 'codewp-shield-monitor' ), __( 'Disables the built-in plugin and theme code editors.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'disable_xmlrpc', __( 'Disable XML-RPC', 'codewp-shield-monitor' ), __( 'Enable only if your site does not use Jetpack or remote publishing.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'audit_log', __( 'Local audit log', 'codewp-shield-monitor' ), __( 'Stores security events locally; IP addresses are irreversibly hashed.', 'codewp-shield-monitor' ) ); ?>
						<label class="shield_monitor-number"><?php esc_html_e( 'Keep events (days)', 'codewp-shield-monitor' ); ?><input type="number" min="1" max="365" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[data_retention_days]" value="<?php echo esc_attr( $this->settings->get( 'data_retention_days' ) ); ?>"></label>
						<?php submit_button( __( 'Save settings', 'codewp-shield-monitor' ) ); ?>
					</form>
				</section>
				<section class="shield_monitor-card">
					<h2><?php esc_html_e( 'Recent security events', 'codewp-shield-monitor' ); ?></h2>
					<?php if ( empty( $events ) ) : ?><p><?php esc_html_e( 'No security events recorded yet.', 'codewp-shield-monitor' ); ?></p><?php else : ?>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Event', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'User', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Time (UTC)', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody>
					<?php foreach ( $events as $event ) : ?><tr><td><?php echo esc_html( str_replace( '_', ' ', $event->event_type ) ); ?></td><td><?php echo esc_html( $event->user_id ? get_the_author_meta( 'user_login', $event->user_id ) : '—' ); ?></td><td><?php echo esc_html( $event->created_at ); ?></td></tr><?php endforeach; ?>
					</tbody></table><?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}
}
