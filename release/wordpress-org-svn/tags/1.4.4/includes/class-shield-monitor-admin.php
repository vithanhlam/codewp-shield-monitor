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
		add_action( 'wp_ajax_shield_monitor_export_sysinfo', array( $this, 'ajax_export_sysinfo' ) );
	}

	public function menu() {
		add_menu_page( __( 'Shield Monitor', 'codewp-shield-monitor' ), __( 'Shield Monitor', 'codewp-shield-monitor' ), 'manage_options', 'codewp-shield-monitor', array( $this, 'page' ), 'dashicons-shield-alt', 81 );
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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'shield_monitor_malware_scan' ),
				'sysinfoNonce' => wp_create_nonce( 'shield_monitor_sysinfo' ),
				'i18n'         => array(
					'scanStarting' => __( 'Starting scan...', 'codewp-shield-monitor' ),
					'scanRunning'  => __( 'Scanning...', 'codewp-shield-monitor' ),
					'scanComplete' => __( 'Scan complete. Refreshing report...', 'codewp-shield-monitor' ),
					'scanFailed'   => __( 'Scan could not finish. Please try again later.', 'codewp-shield-monitor' ),
				),
			)
		);
	}

	public function ajax_export_sysinfo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		check_ajax_referer( 'shield_monitor_sysinfo', 'nonce' );

		$dashboard = new Shield_Monitor_Security_Dashboard( $this->settings, $this->logger, $this->status, $this->api );
		$info      = $dashboard->collect_system_info_public();
		wp_send_json_success( $info );
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
	}
}
