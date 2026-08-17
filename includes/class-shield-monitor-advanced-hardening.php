<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Advanced_Hardening {
	private $settings;
	private $logger;
	private $login_fields_rendered = false;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_filter( 'user_has_cap', array( $this, 'restrict_software_caps' ), 10, 4 );
		add_action( 'login_enqueue_scripts', array( $this, 'login_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
		add_action( 'login_form', array( $this, 'login_fields' ) );
		add_action( 'register_form', array( $this, 'register_fields' ) );
		add_filter( 'login_form_middle', array( $this, 'login_form_middle' ) );
		add_action( 'woocommerce_login_form', array( $this, 'login_fields' ) );
		add_action( 'woocommerce_register_form', array( $this, 'register_fields' ) );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'checkout_fields' ) );
		add_filter( 'authenticate', array( $this, 'verify_captcha' ), 22, 3 );
		add_filter( 'registration_errors', array( $this, 'verify_registration_captcha' ), 10, 3 );
		add_action( 'woocommerce_register_post', array( $this, 'verify_woocommerce_registration_captcha' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'verify_woocommerce_checkout_captcha' ), 10, 2 );
		add_filter( 'authenticate', array( $this, 'verify_admin_pin' ), 60, 3 );

		// Comment anti-spam.
		add_action( 'comment_form_after_fields', array( $this, 'comment_fields' ) );
		add_action( 'comment_form_logged_in_after', array( $this, 'comment_fields' ) );
		add_filter( 'preprocess_comment', array( $this, 'verify_comment_spam' ) );

		// Registration rate limiting.
		add_filter( 'registration_errors', array( $this, 'verify_registration_rate_limit' ), 9, 3 );
		add_action( 'register_form', array( $this, 'registration_rate_limit_fields' ) );
		add_action( 'woocommerce_register_form', array( $this, 'registration_rate_limit_fields' ) );
		add_action( 'woocommerce_register_post', array( $this, 'verify_woocommerce_registration_rate_limit' ), 9, 3 );
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
		if ( $this->login_challenges_disabled() || ( ! $this->captcha_enabled() && ! $this->settings->get( 'admin_pin_enabled' ) ) ) {
			return;
		}

		wp_enqueue_style( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/css/login.css', array(), SHIELD_MONITOR_VERSION );

		if ( $this->settings->get( 'admin_pin_enabled' ) ) {
			wp_enqueue_script( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/js/login.js', array(), SHIELD_MONITOR_VERSION, true );
		}

		$this->enqueue_captcha_script();
	}

	public function frontend_assets() {
		$comment_antispam = $this->settings->get( 'comment_captcha_enabled' ) || $this->settings->get( 'comment_math_captcha_enabled' );
		$login_captcha    = ! $this->login_challenges_disabled() && $this->captcha_enabled() && $this->frontend_captcha_needed();
		$admin_pin        = ! $this->login_challenges_disabled() && $this->settings->get( 'admin_pin_enabled' );

		if ( ! $comment_antispam && ! $login_captcha && ! $admin_pin ) {
			return;
		}

		wp_enqueue_style( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/css/login.css', array(), SHIELD_MONITOR_VERSION );

		if ( $admin_pin ) {
			wp_enqueue_script( 'shield_monitor-login', SHIELD_MONITOR_URL . 'assets/js/login.js', array(), SHIELD_MONITOR_VERSION, true );
		}

		if ( $login_captcha || ( $comment_antispam && $this->captcha_enabled( 'comment' ) ) ) {
			$this->enqueue_captcha_script();
		}
	}

	public function login_fields() {
		$this->login_fields_rendered = true;
		echo $this->login_fields_html( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function register_fields() {
		echo $this->login_fields_html( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function checkout_fields() {
		echo $this->login_fields_html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Filter variant for wp_login_form() shortcode which does not fire the
	 * login_form action.  Skip when login_fields() already rendered the HTML
	 * on the native wp-login.php page to avoid duplicate fields.
	 */
	public function login_form_middle( $content ) {
		if ( ! empty( $this->login_fields_rendered ) ) {
			return $content;
		}
		return $content . $this->login_fields_html( 'login' );
	}

	private function login_fields_html( $context = 'login' ) {
		ob_start();

		if ( 'login' === $context && ! $this->login_challenges_disabled() && $this->settings->get( 'admin_pin_enabled' ) ) {
			?>
			<div class="shield-monitor-login-extra">
				<label><?php esc_html_e( 'Staff PIN', 'codewp-shield-monitor' ); ?></label>
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
		if ( $this->login_challenges_disabled() || ! $this->captcha_enabled( $context ) || ! $site_key ) {
			return ob_get_clean();
		}

		// Marker so verification knows this form had a CAPTCHA rendered.
		echo '<input type="hidden" name="shield_monitor_captcha_form" value="1">';
		echo '<div class="shield-monitor-captcha">';
		if ( 'turnstile' === $provider ) {
			printf( '<div class="cf-turnstile" data-sitekey="%s" data-action="%s"></div>', esc_attr( $site_key ), esc_attr( $this->captcha_action( $context ) ) );
		} elseif ( 'recaptcha_v3' === $provider ) {
			printf( '<input type="hidden" class="shield-monitor-recaptcha-v3" name="shield_monitor_recaptcha_v3_response" data-sitekey="%s" data-action="%s" value="">', esc_attr( $site_key ), esc_attr( $this->captcha_action( $context ) ) );
		} else {
			printf( '<div class="g-recaptcha" data-sitekey="%s"></div>', esc_attr( $site_key ) );
		}
		echo '</div>';

		return ob_get_clean();
	}

	public function verify_captcha( $user, $username, $password ) {
		if ( is_wp_error( $user ) || $this->login_challenges_disabled() || ! $this->captcha_enabled( 'login' ) || empty( $username ) ) {
			return $user;
		}

		$result = $this->verify_captcha_context( 'login' );
		return is_wp_error( $result ) ? $result : $user;
	}

	public function verify_registration_captcha( $errors, $sanitized_user_login, $user_email ) {
		if ( $this->login_challenges_disabled() || ! $this->captcha_enabled( 'register' ) ) {
			return $errors;
		}

		$result = $this->verify_captcha_context( 'register' );
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}

		return $errors;
	}

	public function verify_woocommerce_registration_captcha( $username, $email, $validation_errors ) {
		if ( $this->login_challenges_disabled() || ! $this->captcha_enabled( 'register' ) ) {
			return;
		}

		$result = $this->verify_captcha_context( 'register' );
		if ( is_wp_error( $result ) ) {
			$validation_errors->add( $result->get_error_code(), $result->get_error_message() );
		}
	}

	public function verify_woocommerce_checkout_captcha( $data, $errors ) {
		if ( $this->login_challenges_disabled() || ! $this->captcha_enabled( 'checkout' ) ) {
			return;
		}

		$result = $this->verify_captcha_context( 'checkout' );
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
	}

	public function verify_admin_pin( $user, $username, $password ) {
		if ( is_wp_error( $user ) || $this->login_challenges_disabled() || ! $user instanceof WP_User || ! $this->settings->get( 'admin_pin_enabled' ) ) {
			return $user;
		}

		if ( ! $this->user_requires_pin( $user ) ) {
			return $user;
		}

		$hash = (string) $this->settings->get( 'admin_pin_hash' );
		if ( ! $hash ) {
			return new WP_Error( 'shield_monitor_pin_missing', __( 'Staff PIN is enabled but no PIN has been configured.', 'codewp-shield-monitor' ) );
		}

		// PIN field absent from POST = form did not render PIN (e.g. AJAX
		// modal login, third-party widget).  Skip so users are not locked out.
		if ( ! isset( $_POST['shield_monitor_pin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $user;
		}

		$pin = $this->posted_pin();
		if ( ! preg_match( '/^\d{6}$/', $pin ) || ! wp_check_password( $pin, $hash ) ) {
			return new WP_Error( 'shield_monitor_pin_failed', __( 'Invalid administrator PIN.', 'codewp-shield-monitor' ) );
		}

		return $user;
	}

	private function verify_captcha_context( $context ) {
		$token = $this->posted_captcha_token();

		// No token submitted — the CAPTCHA widget did not render, the JS
		// had not initialised yet, or the form does not include CAPTCHA
		// fields (AJAX modal, third-party widget).  Let the attempt through;
		// other protections (login lockout, honeypot, Staff PIN) still apply.
		if ( ! $token ) {
			return true;
		}

		// A token was submitted — verify it with the provider.
		if ( ! $this->remote_verify_captcha( $token, $context ) ) {
			return new WP_Error( 'shield_monitor_captcha_failed', __( 'Security challenge failed. Please try again.', 'codewp-shield-monitor' ) );
		}

		return true;
	}

	private function remote_verify_captcha( $token, $context = 'login' ) {
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
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return false;
		}

		if ( 'recaptcha_v3' === $provider ) {
			$expected_action = $this->captcha_action( $context );
			$threshold       = (float) $this->settings->get( 'captcha_score_threshold' );
			if ( isset( $body['action'] ) && $expected_action !== (string) $body['action'] ) {
				return false;
			}
			if ( ! isset( $body['score'] ) || (float) $body['score'] < $threshold ) {
				return false;
			}
		}

		return true;
	}

	private function captcha_enabled( $context = null ) {
		$provider = $this->settings->get( 'captcha_provider' );
		$provider = 'recaptcha' === $provider ? 'recaptcha_v2' : $provider;
		if ( ! $this->settings->get( 'captcha_enabled' ) || ! in_array( $provider, array( 'recaptcha_v2', 'recaptcha_v3', 'turnstile' ), true ) ) {
			return false;
		}

		return null === $context || in_array( sanitize_key( $context ), $this->captcha_forms(), true );
	}

	private function login_challenges_disabled() {
		return defined( 'CODEWP_SHIELD_MONITOR_DISABLE_LOGIN_CHALLENGES' ) && CODEWP_SHIELD_MONITOR_DISABLE_LOGIN_CHALLENGES;
	}

	private function frontend_captcha_needed() {
		// Always load on checkout — is_checkout() may not be reliable at
		// wp_enqueue_scripts time with some themes/page builders.
		return $this->captcha_enabled( 'login' ) || $this->captcha_enabled( 'register' ) || $this->captcha_enabled( 'comment' ) || $this->captcha_enabled( 'checkout' );
	}

	private function enqueue_captcha_script() {
		$provider = $this->settings->get( 'captcha_provider' );
		$provider = 'recaptcha' === $provider ? 'recaptcha_v2' : $provider;
		$site_key = trim( (string) $this->settings->get( 'captcha_site_key' ) );
		if ( ! $this->captcha_enabled() || ! $site_key ) {
			return;
		}

		if ( 'turnstile' === $provider ) {
			wp_enqueue_script( 'shield_monitor-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=smTurnstileReady', array(), null, true );
			wp_add_inline_script( 'shield_monitor-turnstile', $this->turnstile_script(), 'before' );
		} elseif ( 'recaptcha_v3' === $provider ) {
			wp_enqueue_script( 'shield_monitor-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ), array(), null, true );
			wp_add_inline_script( 'shield_monitor-recaptcha-v3', $this->recaptcha_v3_script(), 'after' );
		} else {
			wp_enqueue_script( 'shield_monitor-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=shieldMonitorRecaptchaReady&render=explicit', array(), null, true );
			wp_add_inline_script( 'shield_monitor-recaptcha', $this->recaptcha_v2_script(), 'after' );
		}
	}

	private function turnstile_script() {
		return "function smTurnstileRender(){if(!window.turnstile)return;document.querySelectorAll('.cf-turnstile[data-sitekey]').forEach(function(el){if(el.dataset.smRendered)return;el.dataset.smRendered='1';turnstile.render(el,{sitekey:el.getAttribute('data-sitekey'),action:el.getAttribute('data-action')||'shield_monitor'});});}window.smTurnstileReady=smTurnstileRender;document.addEventListener('DOMContentLoaded',function(){smTurnstileRender();if(window.jQuery){jQuery(document.body).on('updated_checkout updated_shipping_method',function(){document.querySelectorAll('.cf-turnstile[data-sitekey]').forEach(function(el){el.removeAttribute('data-sm-rendered');el.innerHTML='';});setTimeout(smTurnstileRender,300);});}});";
	}

	private function recaptcha_v3_script() {
		return "(function(){var tries=0;function run(){if(!window.grecaptcha||!grecaptcha.ready){if(tries++<40){window.setTimeout(run,250);}return;}grecaptcha.ready(function(){document.querySelectorAll('.shield-monitor-recaptcha-v3[data-sitekey]').forEach(function(input){var sitekey=input.getAttribute('data-sitekey'),action=input.getAttribute('data-action')||'shield_monitor_login',form=input.closest('form');function refresh(){grecaptcha.execute(sitekey,{action:action}).then(function(v){input.value=v;});}refresh();window.setInterval(refresh,90000);if(form&&!form.dataset.smV3){form.dataset.smV3='1';form.addEventListener('submit',function(e){if(input.value)return;e.preventDefault();grecaptcha.execute(sitekey,{action:action}).then(function(v){input.value=v;form.submit();});});}});});}run();document.addEventListener('DOMContentLoaded',run);if(window.jQuery){jQuery(document.body).on('updated_checkout',run);}})();";
	}

	private function recaptcha_v2_script() {
		return "window.shieldMonitorRecaptchaReady=function(){document.querySelectorAll('.g-recaptcha[data-sitekey]').forEach(function(el){if(el.dataset.smRendered)return;el.dataset.smRendered='1';grecaptcha.render(el,{sitekey:el.getAttribute('data-sitekey')});});};(function(){var t=0;function poll(){if(window.grecaptcha&&grecaptcha.render){shieldMonitorRecaptchaReady();return;}if(t++<40)setTimeout(poll,250);}poll();document.addEventListener('DOMContentLoaded',poll);if(window.jQuery){jQuery(document.body).on('updated_checkout',function(){document.querySelectorAll('.g-recaptcha[data-sitekey]').forEach(function(el){el.removeAttribute('data-sm-rendered');el.innerHTML='';});setTimeout(shieldMonitorRecaptchaReady,300);});}})();";
	}

	private function captcha_forms() {
		$forms = array_map( 'sanitize_key', (array) $this->settings->get( 'captcha_forms' ) );
		$forms = array_values( array_intersect( $forms, array( 'login', 'register', 'checkout', 'comment' ) ) );
		return $forms ?: array( 'login' );
	}

	private function captcha_action( $context ) {
		return 'shield_monitor_' . sanitize_key( $context );
	}

	/**
	 * Get the CAPTCHA token from POST data.
	 *
	 * Returns the token string when the field exists (may be empty if the
	 * user did not complete the challenge), or null when the field is absent
	 * entirely (form did not render a CAPTCHA widget).
	 *
	 * @return string|null
	 */
	private function posted_captcha_token() {
		$provider = $this->settings->get( 'captcha_provider' );
		$provider = 'recaptcha' === $provider ? 'recaptcha_v2' : $provider;

		if ( 'turnstile' === $provider ) {
			$key = 'cf-turnstile-response';
		} elseif ( 'recaptcha_v3' === $provider ) {
			$key = 'shield_monitor_recaptcha_v3_response';
		} else {
			$key = 'g-recaptcha-response';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	private function user_requires_pin( WP_User $user ) {
		$roles = array_map( 'sanitize_key', (array) $user->roles );
		if ( empty( $roles ) || in_array( 'customer', $roles, true ) ) {
			return false;
		}

		$protected_roles = array_map( 'sanitize_key', (array) $this->settings->get( 'admin_pin_roles' ) );
		$requires_pin    = (bool) array_intersect( $roles, $protected_roles );

		/**
		 * Filter whether a successfully password-authenticated user must also enter the Shield Monitor PIN.
		 *
		 * Customers are skipped before this filter so storefront logins do not require or show a PIN by default.
		 */
		return (bool) apply_filters( 'shield_monitor_user_requires_pin', $requires_pin, $user, $roles );
	}

	private function posted_pin() {
		if ( ! isset( $_POST['shield_monitor_pin'] ) ) {
			return '';
		}

		$posted = wp_unslash( $_POST['shield_monitor_pin'] );
		if ( is_array( $posted ) ) {
			return preg_replace( '/\D/', '', implode( '', array_map( 'sanitize_text_field', $posted ) ) );
		}

		return preg_replace( '/\D/', '', sanitize_text_field( $posted ) );
	}

	// ── Comment anti-spam ────────────────────────────────────────────────

	public function comment_fields() {
		if ( current_user_can( 'moderate_comments' ) ) {
			return;
		}

		// Provider CAPTCHA for comments.
		if ( $this->settings->get( 'comment_captcha_enabled' ) && $this->captcha_enabled( 'comment' ) ) {
			echo $this->login_fields_html( 'comment' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		// Math CAPTCHA when rate limited or always-on.
		if ( $this->settings->get( 'comment_math_captcha_enabled' ) ) {
			$show = $this->settings->get( 'comment_rate_limit_enabled' )
				? $this->is_ip_rate_limited( 'comment' )
				: true;
			if ( $show ) {
				echo $this->render_math_captcha(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	public function verify_comment_spam( $commentdata ) {
		if ( current_user_can( 'moderate_comments' ) ) {
			return $commentdata;
		}

		// Provider CAPTCHA verification.
		if ( $this->settings->get( 'comment_captcha_enabled' ) && $this->captcha_enabled( 'comment' ) ) {
			$result = $this->verify_captcha_context( 'comment' );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Comment Blocked', 'codewp-shield-monitor' ), array( 'back_link' => true, 'response' => 403 ) );
			}
		}

		// Rate limit check + math CAPTCHA verification.
		if ( $this->settings->get( 'comment_rate_limit_enabled' ) ) {
			$rate_limited = $this->is_ip_rate_limited( 'comment' );

			if ( $rate_limited && $this->settings->get( 'comment_math_captcha_enabled' ) ) {
				if ( ! $this->verify_math_captcha() ) {
					wp_die( esc_html__( 'Incorrect math answer. Please go back and try again.', 'codewp-shield-monitor' ), esc_html__( 'Comment Blocked', 'codewp-shield-monitor' ), array( 'back_link' => true, 'response' => 403 ) );
				}
			} elseif ( $rate_limited ) {
				wp_die( esc_html__( 'You are commenting too quickly. Please wait a few minutes and try again.', 'codewp-shield-monitor' ), esc_html__( 'Comment Blocked', 'codewp-shield-monitor' ), array( 'back_link' => true, 'response' => 429 ) );
			}

			$this->increment_ip_rate( 'comment' );
		} elseif ( $this->settings->get( 'comment_math_captcha_enabled' ) ) {
			// Math CAPTCHA always-on (no rate limit).
			if ( ! $this->verify_math_captcha() ) {
				wp_die( esc_html__( 'Incorrect math answer. Please go back and try again.', 'codewp-shield-monitor' ), esc_html__( 'Comment Blocked', 'codewp-shield-monitor' ), array( 'back_link' => true, 'response' => 403 ) );
			}
		}

		return $commentdata;
	}

	// ── Registration rate limiting ───────────────────────────────────────

	public function registration_rate_limit_fields() {
		if ( ! $this->settings->get( 'registration_rate_limit_enabled' ) ) {
			return;
		}
		if ( $this->is_ip_rate_limited( 'register' ) ) {
			echo $this->render_math_captcha(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function verify_registration_rate_limit( $errors, $sanitized_user_login, $user_email ) {
		if ( ! $this->settings->get( 'registration_rate_limit_enabled' ) ) {
			return $errors;
		}

		if ( $this->is_ip_rate_limited( 'register' ) ) {
			if ( ! $this->verify_math_captcha() ) {
				$errors->add( 'shield_monitor_reg_rate_limit', __( 'Too many registration attempts. Please solve the math question to continue.', 'codewp-shield-monitor' ) );
			}
		}

		$this->increment_ip_rate( 'register' );
		return $errors;
	}

	public function verify_woocommerce_registration_rate_limit( $username, $email, $validation_errors ) {
		if ( ! $this->settings->get( 'registration_rate_limit_enabled' ) ) {
			return;
		}

		if ( $this->is_ip_rate_limited( 'register' ) ) {
			if ( ! $this->verify_math_captcha() ) {
				$validation_errors->add( 'shield_monitor_reg_rate_limit', __( 'Too many registration attempts. Please solve the math question to continue.', 'codewp-shield-monitor' ) );
			}
		}

		$this->increment_ip_rate( 'register' );
	}

	// ── Math CAPTCHA helpers ─────────────────────────────────────────────

	private function render_math_captcha() {
		$a     = wp_rand( 1, 20 );
		$b     = wp_rand( 1, 20 );
		$nonce = wp_create_nonce( 'shield_monitor_math' );
		$answer = $a + $b;

		set_transient( 'sm_math_' . $nonce, $answer, 600 );

		return sprintf(
			'<div class="shield-monitor-math-captcha">'
			. '<label>%s</label>'
			. '<input type="number" name="shield_monitor_math_answer" required autocomplete="off">'
			. '<input type="hidden" name="shield_monitor_math_nonce" value="%s">'
			. '</div>',
			esc_html( sprintf( __( 'Anti-spam: %1$d + %2$d = ?', 'codewp-shield-monitor' ), $a, $b ) ),
			esc_attr( $nonce )
		);
	}

	private function verify_math_captcha() {
		$nonce  = isset( $_POST['shield_monitor_math_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['shield_monitor_math_nonce'] ) ) : '';
		$answer = isset( $_POST['shield_monitor_math_answer'] ) ? absint( wp_unslash( $_POST['shield_monitor_math_answer'] ) ) : -1;

		if ( ! $nonce ) {
			return false;
		}

		$expected = get_transient( 'sm_math_' . $nonce );
		delete_transient( 'sm_math_' . $nonce );

		return false !== $expected && $answer === (int) $expected;
	}

	// ── Rate limiting helpers ────────────────────────────────────────────

	private function current_ip_hash() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	private function is_ip_rate_limited( $type ) {
		$max    = (int) $this->settings->get( $type . '_rate_limit_max' );
		$hash   = $this->current_ip_hash();
		$key    = 'sm_rate_' . $type . '_' . substr( $hash, 0, 32 );
		$count  = (int) get_transient( $key );
		return $count >= $max;
	}

	private function increment_ip_rate( $type ) {
		$window = (int) $this->settings->get( $type . '_rate_limit_window' );
		$hash   = $this->current_ip_hash();
		$key    = 'sm_rate_' . $type . '_' . substr( $hash, 0, 32 );
		$count  = (int) get_transient( $key );
		set_transient( $key, $count + 1, $window * MINUTE_IN_SECONDS );
	}

}
