<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_License_Page {
	private $license;
	private $addons;

	public function __construct( $license, $addons ) {
		$this->license = $license;
		$this->addons  = $addons;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu() {
		add_submenu_page(
			'codewp-shield-monitor',
			__( 'License', 'codewp-shield-monitor' ),
			__( 'License', 'codewp-shield-monitor' ),
			'manage_options',
			'codewp-shield-monitor-license',
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'codewp-shield-monitor' ) );
		}

		$license       = $this->license->get_license();
		$addons        = $this->addons->get_available_addons();
		$warning_level = $this->license->expiry_warning_level();
		$is_active     = in_array( $license['status'], array( 'active', 'unverified' ), true ) && 'free' !== $license['plan'];
		$is_free       = 'free' === $license['plan'] || 'inactive' === $license['status'];

		$message = get_transient( 'shield_monitor_license_message' );
		if ( $message ) {
			delete_transient( 'shield_monitor_license_message' );
		}

		$this->render_styles();
		?>
		<div class="wrap smp-license-wrap">
			<h1 class="wp-heading-inline" style="display:none;"><?php esc_html_e( 'License & Plans', 'codewp-shield-monitor' ); ?></h1>
			<hr class="wp-header-end" style="margin:0;border:0;height:0;">

			<?php if ( $message && is_array( $message ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $message['type'] ? 'success' : 'error'; ?> is-dismissible" id="smp-license-notice">
					<p><?php echo esc_html( $message['message'] ); ?></p>
				</div>
				<script>
				// Scroll notice into view so user sees it.
				document.addEventListener('DOMContentLoaded', function() {
					var n = document.getElementById('smp-license-notice');
					if (n) n.scrollIntoView({ behavior: 'smooth', block: 'center' });
				});
				</script>
			<?php endif; ?>

			<?php $this->render_expiry_warning( $license, $warning_level ); ?>

			<!-- Header -->
			<div class="smp-license-header">
				<div>
					<h1><?php esc_html_e( 'License & Plans', 'codewp-shield-monitor' ); ?></h1>
					<p><?php esc_html_e( 'Manage your ShieldPress license and unlock premium features.', 'codewp-shield-monitor' ); ?></p>
				</div>
				<div class="smp-license-badge-wrap">
					<span class="smp-plan-badge <?php echo $is_active ? 'smp-plan-pro' : 'smp-plan-free'; ?>">
						<?php echo esc_html( strtoupper( $license['plan'] ) ); ?>
					</span>
					<?php if ( $is_active ) : ?>
						<span class="smp-status-dot smp-status-active"></span>
						<span class="smp-status-text"><?php esc_html_e( 'Active', 'codewp-shield-monitor' ); ?></span>
					<?php elseif ( 'expired' === $license['status'] ) : ?>
						<span class="smp-status-dot smp-status-expired"></span>
						<span class="smp-status-text"><?php esc_html_e( 'Expired', 'codewp-shield-monitor' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="smp-license-grid">

				<!-- Left: License Details + Activation -->
				<div class="smp-license-card">
					<?php if ( $is_active ) : ?>
						<?php $this->render_active_license( $license ); ?>
					<?php else : ?>
						<?php $this->render_activate_form( $license ); ?>
					<?php endif; ?>
				</div>

				<!-- Right: Plans -->
				<div class="smp-license-card smp-plans-card">
					<?php $this->render_plans_table( $license, $is_free ); ?>
				</div>
			</div>

			<?php if ( ! empty( $addons ) ) : ?>
				<?php $this->render_addons_section( $addons ); ?>
			<?php endif; ?>

		</div>
		<?php
	}

	private function render_active_license( $license ) {
		?>
		<h2><?php esc_html_e( 'License Details', 'codewp-shield-monitor' ); ?></h2>

		<div class="smp-license-info-grid">
			<?php if ( $license['key'] ) : ?>
			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Key', 'codewp-shield-monitor' ); ?></span>
				<code class="smp-key-display"><?php echo esc_html( $license['key'] ); ?></code>
			</div>
			<?php endif; ?>

			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Plan', 'codewp-shield-monitor' ); ?></span>
				<span class="smp-info-value"><?php echo esc_html( strtoupper( $license['plan'] ) ); ?></span>
			</div>

			<?php if ( $license['billing_type'] ) : ?>
			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Billing', 'codewp-shield-monitor' ); ?></span>
				<span class="smp-info-value"><?php echo esc_html( 'lifetime' === $license['billing_type'] ? __( 'Lifetime', 'codewp-shield-monitor' ) : __( 'Annual', 'codewp-shield-monitor' ) ); ?></span>
			</div>
			<?php endif; ?>

			<?php if ( $license['source'] ) : ?>
			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Source', 'codewp-shield-monitor' ); ?></span>
				<span class="smp-info-value"><?php echo esc_html( 'saas' === $license['source'] ? __( 'SaaS Plan', 'codewp-shield-monitor' ) : __( 'Standalone', 'codewp-shield-monitor' ) ); ?></span>
			</div>
			<?php endif; ?>

			<?php if ( $license['expires_at'] ) : ?>
			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Expires', 'codewp-shield-monitor' ); ?></span>
				<span class="smp-info-value">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $license['expires_at'] ) ) ); ?>
					<?php if ( null !== $license['days_until_expiry'] && $license['days_until_expiry'] > 0 ) : ?>
						<small class="smp-days-left">(<?php echo esc_html( sprintf( __( '%d days left', 'codewp-shield-monitor' ), $license['days_until_expiry'] ) ); ?>)</small>
					<?php endif; ?>
				</span>
			</div>
			<?php endif; ?>

			<?php if ( $license['site_limit'] ) : ?>
			<div class="smp-info-item">
				<span class="smp-info-label"><?php esc_html_e( 'Sites', 'codewp-shield-monitor' ); ?></span>
				<span class="smp-info-value"><?php echo esc_html( $license['activated_count'] . ' / ' . $license['site_limit'] ); ?></span>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( 'saas' === $license['source'] ) : ?>
			<div class="smp-saas-notice">
				<?php
				printf(
					esc_html__( 'Included with your ShieldPress monitoring plan. %s', 'codewp-shield-monitor' ),
					'<a href="' . esc_url( SHIELD_MONITOR_APP_URL ) . '" target="_blank">' . esc_html__( 'Manage subscription', 'codewp-shield-monitor' ) . '</a>'
				);
				?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $license['features'] ) ) : ?>
			<div class="smp-features-list">
				<h3><?php esc_html_e( 'Active Features', 'codewp-shield-monitor' ); ?></h3>
				<ul>
					<?php foreach ( $license['features'] as $feature ) : ?>
						<li><?php echo esc_html( $feature ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="smp-license-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'shield_monitor_deactivate_license' ); ?>
				<input type="hidden" name="action" value="shield_monitor_deactivate_license" />
				<button type="submit" class="button"><?php esc_html_e( 'Deactivate License', 'codewp-shield-monitor' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_activate_form( $license ) {
		$message = get_transient( 'shield_monitor_license_message' );
		// Also check from render() — it may have already consumed it.
		?>
		<div class="smp-activate-section">
			<div class="smp-activate-icon">
				<span class="dashicons dashicons-shield-alt"></span>
			</div>
			<h2><?php esc_html_e( 'Activate Your License', 'codewp-shield-monitor' ); ?></h2>
			<p><?php esc_html_e( 'Enter your license key to unlock premium addons and features.', 'codewp-shield-monitor' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="smp-activate-form" id="smp-activate-form">
				<?php wp_nonce_field( 'shield_monitor_activate_license' ); ?>
				<input type="hidden" name="action" value="shield_monitor_activate_license" />
				<div class="smp-key-input-wrap">
					<input type="text" name="license_key" placeholder="SPMP-XXXX-XXXX-XXXX-XXXX" class="smp-key-input" autocomplete="off" spellcheck="false" />
					<button type="submit" class="button button-primary smp-activate-btn"><?php esc_html_e( 'Activate', 'codewp-shield-monitor' ); ?></button>
				</div>
				<div id="smp-activate-message" style="margin-top:12px;"></div>
			</form>

			<p class="smp-activate-help">
				<?php
				printf(
					esc_html__( 'Don\'t have a key? %s', 'codewp-shield-monitor' ),
					'<a href="' . esc_url( SHIELD_MONITOR_APP_URL . '/shield-monitor-pro' ) . '" target="_blank">' . esc_html__( 'Get a license at shieldpress.net/shield-monitor-pro', 'codewp-shield-monitor' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	private function render_plans_table( $license, $is_free ) {
		$features = array(
			array( __( 'Login Protection', 'codewp-shield-monitor' ),        true,  true,  true ),
			array( __( 'File Integrity Monitoring', 'codewp-shield-monitor' ), true,  true,  true ),
			array( __( 'Malware Scanner', 'codewp-shield-monitor' ),         true,  true,  true ),
			array( __( 'CVE Vulnerability Alerts', 'codewp-shield-monitor' ), true,  true,  true ),
			array( __( 'Mobile App Connection', 'codewp-shield-monitor' ),   true,  true,  true ),
			array( __( 'Premium Addons', 'codewp-shield-monitor' ),          false, true,  true ),
			array( __( 'Advanced Firewall', 'codewp-shield-monitor' ),       false, true,  true ),
			array( __( 'Real-time Threat DB', 'codewp-shield-monitor' ),     false, true,  true ),
			array( __( 'Priority Support', 'codewp-shield-monitor' ),        false, true,  true ),
			array( __( 'White Label', 'codewp-shield-monitor' ),             false, false, true ),
		);
		?>
		<div class="smp-plans-header">
			<div class="smp-plan-col smp-plan-label-col"></div>
			<div class="smp-plan-col smp-plan-col-free <?php echo $is_free ? 'smp-plan-current' : ''; ?>">
				<span class="smp-plan-name">Free</span>
				<span class="smp-plan-price">$0</span>
			</div>
			<div class="smp-plan-col smp-plan-col-pro <?php echo 'pro' === $license['plan'] && ! $is_free ? 'smp-plan-current' : ''; ?>">
				<span class="smp-plan-name">Pro</span>
				<span class="smp-plan-price">$49<small>/yr</small></span>
			</div>
			<div class="smp-plan-col smp-plan-col-biz <?php echo 'business' === $license['plan'] ? 'smp-plan-current' : ''; ?>">
				<span class="smp-plan-name">Business</span>
				<span class="smp-plan-price">$99<small>/yr</small></span>
			</div>
		</div>

		<div class="smp-plans-body">
			<?php foreach ( $features as $row ) : ?>
			<div class="smp-plan-row">
				<div class="smp-plan-col smp-plan-label-col"><?php echo esc_html( $row[0] ); ?></div>
				<div class="smp-plan-col"><?php echo $row[1] ? '<span class="smp-check"></span>' : '<span class="smp-cross"></span>'; ?></div>
				<div class="smp-plan-col"><?php echo $row[2] ? '<span class="smp-check"></span>' : '<span class="smp-cross"></span>'; ?></div>
				<div class="smp-plan-col"><?php echo $row[3] ? '<span class="smp-check"></span>' : '<span class="smp-cross"></span>'; ?></div>
			</div>
			<?php endforeach; ?>

			<div class="smp-plan-row smp-plan-row-sites">
				<div class="smp-plan-col smp-plan-label-col"><strong><?php esc_html_e( 'Sites', 'codewp-shield-monitor' ); ?></strong></div>
				<div class="smp-plan-col">1</div>
				<div class="smp-plan-col"><strong>1</strong></div>
				<div class="smp-plan-col"><strong>5</strong></div>
			</div>

			<div class="smp-plan-row smp-plan-row-lt">
				<div class="smp-plan-col smp-plan-label-col"><?php esc_html_e( 'Lifetime', 'codewp-shield-monitor' ); ?></div>
				<div class="smp-plan-col">—</div>
				<div class="smp-plan-col"><strong>$149</strong></div>
				<div class="smp-plan-col"><strong>$299</strong></div>
			</div>
		</div>

		<?php if ( $is_free ) : ?>
		<div class="smp-plans-cta">
			<a href="<?php echo esc_url( SHIELD_MONITOR_APP_URL . '/shield-monitor-pro' ); ?>" class="button button-primary button-hero" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Upgrade to Pro', 'codewp-shield-monitor' ); ?>
			</a>
		</div>
		<?php endif; ?>
		<?php
	}

	private function render_addons_section( $addons ) {
		?>
		<div class="smp-license-card smp-addons-card">
			<h2><?php esc_html_e( 'Available Addons', 'codewp-shield-monitor' ); ?></h2>
			<div class="smp-addons-grid">
				<?php foreach ( $addons as $addon ) : ?>
				<div class="smp-addon-item <?php echo $addon['active'] ? 'smp-addon-active' : 'smp-addon-inactive'; ?>">
					<div class="smp-addon-icon">
						<span class="dashicons <?php echo $addon['active'] ? 'dashicons-yes-alt' : 'dashicons-lock'; ?>"></span>
					</div>
					<div class="smp-addon-info">
						<strong><?php echo esc_html( $addon['name'] ); ?></strong>
						<span class="smp-addon-desc"><?php echo esc_html( $addon['description'] ); ?></span>
					</div>
					<div class="smp-addon-meta">
						<span class="smp-addon-version">v<?php echo esc_html( $addon['version'] ); ?></span>
						<span class="smp-addon-plan"><?php echo esc_html( strtoupper( $addon['requires_plan'] ) ); ?></span>
						<?php if ( $addon['active'] ) : ?>
							<span class="smp-addon-status-active"><?php esc_html_e( 'Active', 'codewp-shield-monitor' ); ?></span>
						<?php else : ?>
							<span class="smp-addon-status-inactive"><?php esc_html_e( 'Requires license', 'codewp-shield-monitor' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_expiry_warning( $license, $level ) {
		if ( 'none' === $level || 'inactive' === $license['status'] ) {
			return;
		}

		$days = intval( $license['days_until_expiry'] ?? 0 );

		switch ( $level ) {
			case 'unverified':
				$class   = 'notice-warning';
				$message = __( 'License verification temporarily unavailable — your Pro features remain active. This usually resolves automatically within 24 hours.', 'codewp-shield-monitor' );
				break;
			case 'yellow':
				$class   = 'notice-warning';
				$message = sprintf( __( 'Your license expires in %d days. Renew to keep Pro features active.', 'codewp-shield-monitor' ), $days );
				break;
			case 'red':
				$class   = 'notice-error';
				$message = sprintf( __( 'Your license is expiring soon! Only %d days remaining.', 'codewp-shield-monitor' ), $days );
				break;
			case 'grace':
				$class   = 'notice-error';
				$message = __( 'Your license has expired. Grace period active — Pro features will be disabled in a few days.', 'codewp-shield-monitor' );
				break;
			case 'expired':
				$class   = 'notice-error';
				$message = __( 'Your license has expired. Pro features are disabled. Please renew or enter a new license key.', 'codewp-shield-monitor' );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice %s"><p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a></p></div>',
			esc_attr( $class ),
			esc_html__( '[Shield Monitor]', 'codewp-shield-monitor' ),
			esc_html( $message ),
			esc_url( SHIELD_MONITOR_APP_URL . '/shield-monitor-pro' ),
			esc_html__( 'Renew now', 'codewp-shield-monitor' )
		);
	}

	private function render_styles() {
		?>
		<style>
		.smp-license-wrap { max-width: 960px; }
		.smp-license-wrap h1 { font-size: 24px; font-weight: 600; margin: 0 0 4px; }
		.smp-license-wrap > p { color: #646970; margin: 0; }

		/* Header */
		.smp-license-header {
			display: flex; justify-content: space-between; align-items: center;
			margin: 20px 0 24px; padding: 20px 24px;
			background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
		}
		.smp-license-badge-wrap { display: flex; align-items: center; gap: 8px; }
		.smp-plan-badge {
			display: inline-block; padding: 6px 18px; border-radius: 20px;
			font-size: 13px; font-weight: 700; letter-spacing: 1px;
		}
		.smp-plan-free { background: #f0f0f1; color: #50575e; }
		.smp-plan-pro { background: linear-gradient(135deg, #2271b1, #135e96); color: #fff; }
		.smp-status-dot {
			width: 8px; height: 8px; border-radius: 50%; display: inline-block;
		}
		.smp-status-active { background: #00a32a; }
		.smp-status-expired { background: #d63638; }
		.smp-status-text { font-size: 13px; color: #646970; }

		/* Grid */
		.smp-license-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
		@media (max-width: 960px) { .smp-license-grid { grid-template-columns: 1fr; } }

		/* Cards */
		.smp-license-card {
			background: #fff; border: 1px solid #e0e0e0; border-radius: 8px;
			padding: 24px;
		}
		.smp-license-card h2 { margin: 0 0 16px; font-size: 16px; font-weight: 600; }
		.smp-license-card h3 { margin: 16px 0 8px; font-size: 13px; font-weight: 600; color: #646970; text-transform: uppercase; letter-spacing: 0.5px; }

		/* Active License Info */
		.smp-license-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		.smp-info-item {
			display: flex; flex-direction: column; gap: 2px;
			padding: 10px 12px; background: #f9f9f9; border-radius: 6px;
		}
		.smp-info-label { font-size: 11px; font-weight: 600; color: #999; text-transform: uppercase; letter-spacing: 0.5px; }
		.smp-info-value { font-size: 14px; color: #1d2327; }
		.smp-key-display { font-size: 12px; padding: 2px 6px; background: #f0f0f1; border-radius: 3px; word-break: break-all; }
		.smp-days-left { color: #999; font-size: 12px; }
		.smp-saas-notice { margin-top: 16px; padding: 10px 14px; background: #f0f6fc; border-radius: 6px; font-size: 13px; color: #2271b1; }
		.smp-license-actions { margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f1; }
		.smp-features-list ul { margin: 4px 0 0 18px; }
		.smp-features-list li { font-size: 13px; color: #3c434a; line-height: 1.8; }
		.smp-features-list li::marker { color: #00a32a; }

		/* Activate Form */
		.smp-activate-section { text-align: center; padding: 20px 0; }
		.smp-activate-icon { margin-bottom: 12px; }
		.smp-activate-icon .dashicons { font-size: 48px; width: 48px; height: 48px; color: #2271b1; opacity: 0.6; }
		.smp-activate-section h2 { margin: 0 0 8px; }
		.smp-activate-section > p { color: #646970; margin: 0 0 20px; }
		.smp-activate-form { max-width: 380px; margin: 0 auto; }
		.smp-key-input-wrap { display: flex; gap: 8px; }
		.smp-key-input {
			flex: 1; padding: 8px 12px; font-family: monospace; font-size: 14px;
			border: 1px solid #c3c4c7; border-radius: 4px; letter-spacing: 0.5px;
		}
		.smp-key-input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
		.smp-activate-btn { white-space: nowrap; padding: 0 20px !important; height: 38px !important; }
		.smp-activate-help { margin-top: 16px; font-size: 13px; color: #646970; line-height: 40px; }

		/* Plans Table */
		.smp-plans-card { padding: 0 !important; overflow: hidden; }
		.smp-plans-header {
			display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr;
			border-bottom: 2px solid #e0e0e0;
		}
		.smp-plan-col {
			display: flex; align-items: center; justify-content: center;
			padding: 8px 10px; font-size: 13px; text-align: center;
		}
		.smp-plan-label-col { justify-content: flex-start; padding-left: 20px; font-size: 13px; color: #3c434a; }
		.smp-plans-header .smp-plan-col {
			flex-direction: column; gap: 2px; padding: 18px 10px;
		}
		.smp-plan-name { font-size: 14px; font-weight: 600; color: #1d2327; }
		.smp-plan-price { font-size: 20px; font-weight: 700; color: #2271b1; }
		.smp-plan-price small { font-size: 12px; font-weight: 400; color: #999; }
		.smp-plans-header .smp-plan-label-col { visibility: hidden; }
		.smp-plan-current { background: #f0f6fc; }
		.smp-plan-col-pro .smp-plan-name { color: #2271b1; }

		.smp-plans-body .smp-plan-row {
			display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr;
			border-bottom: 1px solid #f0f0f1;
		}
		.smp-plans-body .smp-plan-row:last-child { border-bottom: none; }
		.smp-plan-row:hover { background: #fafafa; }
		.smp-plan-row-sites, .smp-plan-row-lt { background: #f9f9f9 !important; }
		.smp-plan-row-sites .smp-plan-col, .smp-plan-row-lt .smp-plan-col { font-weight: 500; }

		.smp-check, .smp-cross { display: inline-block; width: 18px; height: 18px; border-radius: 50%; }
		.smp-check {
			background: #00a32a; position: relative;
		}
		.smp-check::after {
			content: '';
			position: absolute;
			top: 4px;
			left: 5px;
			width: 6px;
			height: 5px;
			border: solid #fff;
			border-width: 0 2px 2px 0;
			transform: rotate(45deg);
		}
		.smp-cross { background: #e0e0e0; position: relative; }
		.smp-cross::after {
			content: ''; position: absolute; top: 50%; left: 50%;
			width: 8px; height: 2px; background: #999;
			transform: translate(-50%, -50%);
		}

		.smp-plans-cta { padding: 16px 20px; text-align: center; border-top: 1px solid #e0e0e0; }
		.smp-plans-cta .button-hero { font-size: 14px !important; padding: 0px 32px !important; }

		/* Addons */
		.smp-addons-card { margin-top: 20px; }
		.smp-addons-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
		.smp-addon-item {
			display: flex; align-items: center; gap: 14px;
			padding: 14px 16px; border: 1px solid #e0e0e0; border-radius: 6px;
			transition: border-color 0.2s;
		}
		.smp-addon-item:hover { border-color: #c3c4c7; }
		.smp-addon-active { border-left: 3px solid #00a32a; }
		.smp-addon-inactive { border-left: 3px solid #c3c4c7; opacity: 0.85; }
		.smp-addon-icon .dashicons { font-size: 24px; width: 24px; height: 24px; }
		.smp-addon-active .smp-addon-icon .dashicons { color: #00a32a; }
		.smp-addon-inactive .smp-addon-icon .dashicons { color: #999; }
		.smp-addon-info { flex: 1; display: flex; flex-direction: column; gap: 2px; }
		.smp-addon-info strong { font-size: 14px; }
		.smp-addon-desc { font-size: 12px; color: #646970; }
		.smp-addon-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; font-size: 11px; }
		.smp-addon-version { color: #999; }
		.smp-addon-plan { font-weight: 600; color: #2271b1; }
		.smp-addon-status-active { color: #00a32a; font-weight: 600; }
		.smp-addon-status-inactive { color: #646970; font-weight: 500; }
		</style>
		<?php
	}
}
