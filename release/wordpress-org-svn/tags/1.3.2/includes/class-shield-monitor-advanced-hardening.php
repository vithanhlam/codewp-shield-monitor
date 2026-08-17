<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Advanced_Hardening {
	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_filter( 'user_has_cap', array( $this, 'restrict_software_caps' ), 10, 4 );
		add_action( 'login_enqueue_scripts', array( $this, 'login_assets' ) );
		add_action( 'login_form', array( $this, 'login_fields' ) );
		add_filter( 'authenticate', array( $this, 'verify_captcha' ), 22, 3 );
		add_filter( 'authenticate', array( $this, 'verify_admin_pin' ), 60, 3 );
	}

	public function restrict_software_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $this->settings->get( 'disable_software_installation' ) ) {
			return $allcaps;
		}

		foreach ( array( 'install_plugins', 'upload_plugins', 'delete_plugins', 'install_themes', 'upload_themes', 'delete_themes' ) as $cap ) {
			$allcaps[ $cap ] = false;
		}

		if ( $this->settings->get( 'disable_file_editor' ) ) {
			foreach ( array( 'edit_plugins', 'edit_themes', 'edit_files' ) as $cap ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	public function login_assets() {
		if ( ! $this->captcha_enabled() && ! $this->settings->get( 'admin_pin_enabled' ) ) {
			return;
		}

		wp_enqueue_style( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/css/login.css', array(), SHIELD_MONITOR_VERSION );

		if ( $this->settings->get( 'admin_pin_enabled' ) ) {
			wp_enqueue_script( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/js/login.js', array(), SHIELD_MONITOR_VERSION, true );
		}

		$provider = $this->settings->get( 'captcha_provider' );
		$site_key = trim( (string) $this->settings->get( 'captcha_site_key' ) );
		if ( ! $this->captcha_enabled() || ! $site_key ) {
			return;
		}

		if ( 'turnstile' === $provider ) {
			wp_enqueue_script( 'shield_monitor-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'strategy' => 'defer' ) );
		} else {
			wp_enqueue_script( 'shield_monitor-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, array( 'strategy' => 'defer' ) );
		}
	}

	public function login_fields() {
		if ( $this->settings->get( 'admin_pin_enabled' ) ) {
			?>
			<div class="shield-monitor-login-extra">
				<label><?php esc_html_e( 'Admin PIN', 'codewp-shield-monitor' ); ?></label>
				<div class="shield-monitor-pin">
					<?php for ( $i = 0; $i < 6; $i++ ) : ?>
						<input type="password" name="shield_monitor_pin[]" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code" aria-label="<?php echo esc_attr( sprintf( __( 'PIN digit %d', 'codewp-shield-monitor' ), $i + 1 ) ); ?>">
					<?php endfor; ?>
				</div>
			</div>
			<?php
		}

		$provider = $this->settings->get( 'captcha_provider' );
		$site_key = trim( (string) $this->settings->get( 'captcha_site_key' ) );
		if ( ! $this->captcha_enabled() || ! $site_key ) {
			return;
		}

		echo '<div class="shield-monitor-captcha">';
		if ( 'turnstile' === $provider ) {
			printf( '<div class="cf-turnstile" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		} else {
			printf( '<div class="g-recaptcha" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		}
		echo '</div>';
	}

	public function verify_captcha( $user, $username, $password ) {
		if ( is_wp_error( $user ) || ! $this->captcha_enabled() || empty( $username ) ) {
			return $user;
		}

		$token = '';
		if ( 'turnstile' === $this->settings->get( 'captcha_provider' ) ) {
			$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		} else {
			$token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
		}

		if ( ! $token ) {
			return new WP_Error( 'shield_monitor_captcha_required', __( 'Please complete the security challenge.', 'codewp-shield-monitor' ) );
		}

		if ( ! $this->remote_verify_captcha( $token ) ) {
			return new WP_Error( 'shield_monitor_captcha_failed', __( 'Security challenge failed. Please try again.', 'codewp-shield-monitor' ) );
		}

		return $user;
	}

	public function verify_admin_pin( $user, $username, $password ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User || ! $this->settings->get( 'admin_pin_enabled' ) ) {
			return $user;
		}

		if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
			return $user;
		}

		$hash = (string) $this->settings->get( 'admin_pin_hash' );
		if ( ! $hash ) {
			return new WP_Error( 'shield_monitor_pin_missing', __( 'Admin PIN is enabled but no PIN has been configured.', 'codewp-shield-monitor' ) );
		}

		$pin = $this->posted_pin();
		if ( ! preg_match( '/^\d{6}$/', $pin ) || ! wp_check_password( $pin, $hash ) ) {
			return new WP_Error( 'shield_monitor_pin_failed', __( 'Invalid administrator PIN.', 'codewp-shield-monitor' ) );
		}

		return $user;
	}

	private function remote_verify_captcha( $token ) {
		$provider = $this->settings->get( 'captcha_provider' );
		$secret   = trim( (string) $this->settings->get( 'captcha_secret_key' ) );
		if ( ! $secret ) {
			return false;
		}

		$endpoint = 'turnstile' === $provider ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://www.google.com/recaptcha/api/siteverify';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}

	private function captcha_enabled() {
		return (bool) $this->settings->get( 'captcha_enabled' ) && in_array( $this->settings->get( 'captcha_provider' ), array( 'recaptcha', 'turnstile' ), true );
	}

	private function posted_pin() {
		$digits = isset( $_POST['shield_monitor_pin'] ) && is_array( $_POST['shield_monitor_pin'] ) ? wp_unslash( $_POST['shield_monitor_pin'] ) : array();
		return preg_replace( '/\D/', '', implode( '', array_map( 'sanitize_text_field', $digits ) ) );
	}

}
