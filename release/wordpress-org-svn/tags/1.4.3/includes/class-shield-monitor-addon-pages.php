<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single "Premium" submenu page.
 *
 * - Always visible in the menu so users know Pro features exist.
 * - No license: shows upsell page with feature list.
 * - Has license: shows all premium addon UIs in sections/tabs.
 */
class Shield_Monitor_Addon_Pages {
	private $license;
	private $addons;
	private $installer;

	/**
	 * Registry of premium feature sections shown on the upsell page.
	 * Each entry becomes a feature card when no license is active.
	 */
	private $feature_cards = array(
		'media-pro' => array(
			'title'       => 'Media Pro',
			'icon'        => 'dashicons-images-alt2',
			'description' => 'Bulk convert all existing images to WebP and clean up orphaned thumbnails to free disk space.',
			'highlights'  => array(
				'Bulk convert PNG/JPEG to WebP',
				'Clean up orphaned cropped thumbnails',
				'WP-CLI commands for large sites',
				'Safe restore to original format',
			),
		),
		'advanced-firewall' => array(
			'title'       => 'Advanced Firewall',
			'icon'        => 'dashicons-shield',
			'description' => 'Country-based blocking, custom firewall rules, and real-time threat intelligence.',
			'highlights'  => array(
				'Country-based IP blocking',
				'Custom firewall rules',
				'Real-time threat database',
				'Auto-block malicious patterns',
			),
		),
		'white-label' => array(
			'title'       => 'White Label',
			'icon'        => 'dashicons-admin-appearance',
			'description' => 'Remove ShieldPress branding and customize the plugin name for your clients.',
			'highlights'  => array(
				'Custom plugin name & branding',
				'Remove ShieldPress references',
				'Custom admin menu icon',
			),
		),
	);

	public function __construct( $license, $addons, $installer = null ) {
		$this->license   = $license;
		$this->addons    = $addons;
		$this->installer = $installer;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
	}

	public function register_menu() {
		$is_active = $this->license->is_active();
		$addon_badge = ' <span style="display:inline-block;background:#f0ad4e;color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:2px;vertical-align:middle;line-height:14px;">Addon</span>';

		add_submenu_page(
			'codewp-shield-monitor',
			__( 'Premium', 'codewp-shield-monitor' ),
			$is_active
				? __( 'Premium', 'codewp-shield-monitor' ) . $addon_badge
				: __( 'Premium', 'codewp-shield-monitor' ) . ' <span style="display:inline-block;background:#2271b1;color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:2px;vertical-align:middle;line-height:14px;">PRO</span>',
			'manage_options',
			'codewp-shield-monitor-premium',
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'codewp-shield-monitor' ) );
		}

		if ( $this->license->is_active() ) {
			$this->render_premium_features();
		} else {
			$this->render_upsell();
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Active License: Show premium feature sections
	// ─────────────────────────────────────────────────────────────────

	private function render_premium_features() {
		$loaded    = $this->addons->get_loaded_addons();
		$installed = $this->installer ? $this->installer->get_installed_slugs() : array_keys( $loaded );
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		if ( ! $tab ) {
			$tab = 'addons';
		}

		$base_url = admin_url( 'admin.php?page=codewp-shield-monitor-premium' );
		?>
		<style>
		.smp-premium-tabs {
			display: flex; align-items: center; gap: 0;
			background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
			padding: 4px; margin: 16px 0 24px; width: fit-content;
		}
		.smp-premium-tab {
			display: inline-flex; align-items: center; gap: 6px;
			padding: 8px 18px; border-radius: 6px;
			font-size: 13px; font-weight: 500; color: #50575e;
			text-decoration: none; transition: all 0.15s;
			border: none; background: none;
		}
		.smp-premium-tab:hover { color: #1d2327; background: #f6f7f7; }
		.smp-premium-tab:focus { outline: none; box-shadow: 0 0 0 2px #2271b1; }
		.smp-premium-tab--active {
			background: #2271b1; color: #fff; font-weight: 600;
		}
		.smp-premium-tab--active:hover { background: #135e96; color: #fff; }
		.smp-premium-tab .dashicons { font-size: 16px; width: 16px; height: 16px; }
		.smp-premium-tab--addon { position: relative; }
		.smp-premium-tab--addon .smp-tab-dot {
			width: 6px; height: 6px; border-radius: 50%; background: #00a32a;
			display: inline-block; margin-left: 2px;
		}
		</style>

		<div class="wrap">
			<h1><?php esc_html_e( 'Premium Features', 'codewp-shield-monitor' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			// Detect installed-but-not-loaded addons for diagnostic.
			$not_loaded = array_diff( $installed, array_keys( $loaded ) );
			?>

			<div class="smp-premium-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'addons', $base_url ) ); ?>"
				   class="smp-premium-tab <?php echo 'addons' === $tab ? 'smp-premium-tab--active' : ''; ?>">
					<span class="dashicons dashicons-admin-plugins"></span>
					<?php esc_html_e( 'Addons', 'codewp-shield-monitor' ); ?>
				</a>
				<?php foreach ( $loaded as $slug => $headers ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					   class="smp-premium-tab smp-premium-tab--addon <?php echo $tab === $slug ? 'smp-premium-tab--active' : ''; ?>">
						<?php echo esc_html( $headers['name'] ); ?>
						<span class="smp-tab-dot" title="<?php esc_attr_e( 'Active', 'codewp-shield-monitor' ); ?>"></span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $not_loaded ) ) : ?>
				<?php
				$license_data = $this->license->get_license();
				$plan_info    = ucfirst( $license_data['plan'] ) . ' / ' . $license_data['status'];
				$features_info = empty( $license_data['features'] )
					? __( 'all features allowed', 'codewp-shield-monitor' )
					: implode( ', ', (array) $license_data['features'] );
				?>
				<div class="notice notice-warning" style="margin:0 0 16px;padding:12px 16px;">
					<p style="margin:0 0 4px;">
						<strong><?php esc_html_e( 'Addons installed but not active:', 'codewp-shield-monitor' ); ?></strong>
						<?php echo esc_html( implode( ', ', $not_loaded ) ); ?>
					</p>
					<p style="margin:0;font-size:12px;color:#646970;">
						<?php
						printf(
							/* translators: 1: plan/status, 2: features list */
							esc_html__( 'License: %1$s — Features: %2$s', 'codewp-shield-monitor' ),
							'<code>' . esc_html( $plan_info ) . '</code>',
							'<code>' . esc_html( $features_info ) . '</code>'
						);
						?>
						&nbsp;—&nbsp;
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=codewp-shield-monitor-license' ) ); ?>">
							<?php esc_html_e( 'Check License', 'codewp-shield-monitor' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 'addons' === $tab ) : ?>
				<?php $this->render_addon_marketplace( $installed ); ?>
			<?php else : ?>
				<?php $this->render_addon_content( $tab ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the addon marketplace grid.
	 */
	private function render_addon_marketplace( $installed_slugs ) {
		?>
		<style>
		/* Grid */
		.smp-addons-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
			gap: 20px;
			margin-top: 10px;
		}

		/* Card */
		.smp-card {
			background: #fff;
			border: 1px solid #e2e4e7;
			border-radius: 12px;
			padding: 24px;
			display: flex;
			flex-direction: column;
			position: relative;
			transition: box-shadow 0.2s, border-color 0.2s;
		}
		.smp-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.07); }
		.smp-card--featured { border-color: #d4a017; }

		/* Featured badge */
		.smp-card-badge {
			position: absolute; top: 12px; right: 12px;
			font-size: 10px; font-weight: 700; padding: 3px 8px;
			border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;
			background: linear-gradient(135deg, #f5d020, #f5ab23); color: #fff;
		}

		/* Header */
		.smp-card-header { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
		.smp-card-icon {
			width: 52px; height: 52px; border-radius: 12px; background: #f0f6fc;
			display: flex; align-items: center; justify-content: center; flex-shrink: 0;
			overflow: hidden; border: 1px solid #e8eef3;
		}
		.smp-card-icon img { width: 100%; height: 100%; object-fit: cover; }
		.smp-card-icon .dashicons { font-size: 26px; width: 26px; height: 26px; color: #2271b1; }
		.smp-card-info { flex: 1; min-width: 0; }
		.smp-card-info h3 {
			margin: 0; font-size: 15px; font-weight: 600; color: #1d2327;
			display: flex; align-items: center; gap: 8px; line-height: 1.3;
		}

		/* Status badges inline with name */
		.smp-card-status {
			display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 7px;
			border-radius: 3px; vertical-align: middle; letter-spacing: 0.3px;
		}
		.smp-card-status-active { background: #edf7ed; color: #0a6c0a; }
		.smp-card-status-installed { background: #fff8e1; color: #8a6d00; }

		/* Meta row */
		.smp-card-meta {
			display: flex; align-items: center; gap: 0; margin-top: 4px; flex-wrap: wrap;
			font-size: 12px; color: #999;
		}
		.smp-card-meta-sep {
			display: inline-block; width: 3px; height: 3px; border-radius: 50%;
			background: #ccc; margin: 0 8px;
		}

		/* Description */
		.smp-card-desc {
			font-size: 13px; color: #646970; line-height: 1.6;
			margin: 0 0 18px; flex: 1;
		}

		/* Update notice */
		.smp-card-update {
			background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px;
			padding: 8px 12px; margin-bottom: 14px;
			font-size: 12px; color: #8a6d00; display: flex; align-items: center; gap: 6px;
		}
		.smp-card-update .dashicons { font-size: 16px; width: 16px; height: 16px; color: #d4a017; }

		/* Actions */
		.smp-card-actions {
			display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
			padding-top: 16px; border-top: 1px solid #f0f0f1;
		}

		/* Buttons */
		.smp-card-btn {
			display: inline-flex; align-items: center; gap: 5px;
			padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;
			cursor: pointer; border: none; text-decoration: none;
			transition: all 0.15s; line-height: 1;
		}
		.smp-card-btn .dashicons { font-size: 15px; width: 15px; height: 15px; }
		.smp-card-btn:disabled { opacity: 0.6; cursor: not-allowed; }
		.smp-card-btn:focus { outline: 2px solid #2271b1; outline-offset: 2px; }

		.smp-card-btn-install { background: #2271b1; color: #fff; }
		.smp-card-btn-install:hover { background: #135e96; color: #fff; }

		.smp-card-btn-update { background: #d4a017; color: #fff; }
		.smp-card-btn-update:hover { background: #b8860b; color: #fff; }

		.smp-card-btn-settings { background: #f0f0f1; color: #1d2327; }
		.smp-card-btn-settings:hover { background: #e0e0e0; color: #1d2327; }

		.smp-card-btn-activate { background: #00a32a; color: #fff; }
		.smp-card-btn-activate:hover { background: #008a20; color: #fff; }

		.smp-card-btn-remove {
			background: none; color: #b32d2e; padding: 8px 12px;
			border: 1px solid #e0e0e0; margin-left: auto;
		}
		.smp-card-btn-remove:hover { background: #fcecec; border-color: #b32d2e; }

		.smp-card-btn-upgrade { background: #f0f0f1; color: #646970; cursor: default; }

		/* Success flash */
		.smp-card-success {
			display: inline-flex; align-items: center; gap: 6px;
			color: #00a32a; font-weight: 600; font-size: 13px;
		}
		.smp-card-success .dashicons { font-size: 18px; width: 18px; height: 18px; }

		/* Error message */
		.smp-card-error {
			margin-top: 10px; padding: 8px 12px; border-radius: 6px;
			background: #fcecec; color: #b32d2e; font-size: 12px;
		}

		/* Loading / Empty */
		.smp-addons-loading {
			text-align: center; padding: 60px 20px; color: #646970; font-size: 14px;
			display: flex; align-items: center; justify-content: center; gap: 8px;
		}
		.smp-addons-empty {
			text-align: center; padding: 60px 20px; color: #999;
		}
		.smp-addons-empty .dashicons { font-size: 40px; width: 40px; height: 40px; margin-bottom: 10px; }
		.smp-addons-empty p { margin: 0; font-size: 14px; }

		/* Pro install prompt */
		.smp-pro-prompt {
			display: flex; align-items: center; gap: 20px;
			background: linear-gradient(135deg, #f0f6fc 0%, #e8f4fd 100%);
			border: 1px solid #c3ddf1; border-radius: 12px;
			padding: 24px 28px; margin-bottom: 8px;
		}
		.smp-pro-prompt-icon {
			width: 56px; height: 56px; border-radius: 50%; flex-shrink: 0;
			background: linear-gradient(135deg, #2271b1, #135e96);
			display: flex; align-items: center; justify-content: center;
		}
		.smp-pro-prompt-icon .dashicons { font-size: 28px; width: 28px; height: 28px; color: #fff; }
		.smp-pro-prompt-content { flex: 1; }
		.smp-pro-prompt-content h3 { margin: 0 0 6px; font-size: 16px; font-weight: 600; color: #1d2327; }
		.smp-pro-prompt-content p { margin: 0 0 14px; font-size: 13px; color: #50575e; line-height: 1.5; }
		.smp-pro-prompt-btn {
			display: inline-flex; align-items: center; gap: 6px;
			padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600;
			background: #2271b1; color: #fff; text-decoration: none;
			transition: background 0.15s;
		}
		.smp-pro-prompt-btn:hover { background: #135e96; color: #fff; }
		.smp-pro-prompt-btn .dashicons { font-size: 16px; width: 16px; height: 16px; }

		/* Feature highlights in static cards */
		.smp-card-highlights {
			margin: 0 0 16px; padding: 0; list-style: none;
		}
		.smp-card-highlights li {
			font-size: 12px; color: #50575e; padding: 3px 0;
			display: flex; align-items: center; gap: 6px;
		}
		.smp-card-highlights li::before {
			content: ''; display: inline-block; width: 14px; height: 14px; min-width: 14px;
			border-radius: 50%; background: #00a32a;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M6.5 11.5L3 8l1-1 2.5 2.5 5-5 1 1z' fill='%23fff'/%3E%3C/svg%3E");
			background-size: 12px; background-position: center; background-repeat: no-repeat;
		}
		</style>

		<?php if ( $this->installer ) : ?>
			<!-- Pro plugin active: load addons from API via JS -->
			<div id="smp-addon-marketplace">
				<div class="smp-addons-loading" id="smp-addons-loader">
					<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
					<?php esc_html_e( 'Loading available addons...', 'codewp-shield-monitor' ); ?>
				</div>
			</div>
			<noscript>
				<div class="notice notice-warning" style="margin-top:10px;">
					<p><?php esc_html_e( 'JavaScript is required to manage addons.', 'codewp-shield-monitor' ); ?></p>
				</div>
			</noscript>
			<script>
			// Fallback: if JS addon-manager doesn't load within 10s, show helpful message.
			setTimeout(function() {
				var loader = document.getElementById('smp-addons-loader');
				if (loader && loader.parentNode.children.length === 1) {
					loader.innerHTML = '<div class="notice notice-error" style="margin:0;"><p><?php echo esc_js( __( 'Could not load addons. Please check your internet connection or try reloading the page.', 'codewp-shield-monitor' ) ); ?></p></div>';
				}
			}, 10000);
			</script>
		<?php else : ?>
			<!-- No Pro plugin: show install prompt + static feature preview -->
			<div class="smp-pro-prompt">
				<div class="smp-pro-prompt-icon">
					<span class="dashicons dashicons-superhero-alt"></span>
				</div>
				<div class="smp-pro-prompt-content">
					<h3><?php esc_html_e( 'Install ShieldPress Pro to manage addons', 'codewp-shield-monitor' ); ?></h3>
					<p><?php esc_html_e( 'Your license is active! Install the ShieldPress Pro companion plugin to browse, install, and update premium addons directly from this page.', 'codewp-shield-monitor' ); ?></p>
					<a href="https://app.shieldpress.net/dashboard/licenses" class="smp-pro-prompt-btn" target="_blank" rel="noopener noreferrer">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Download ShieldPress Pro', 'codewp-shield-monitor' ); ?>
					</a>
				</div>
			</div>

			<h3 style="margin:28px 0 12px;font-size:14px;color:#1d2327;"><?php esc_html_e( 'Available Premium Addons', 'codewp-shield-monitor' ); ?></h3>
			<div class="smp-addons-grid">
				<?php foreach ( $this->feature_cards as $slug => $card ) : ?>
				<div class="smp-card">
					<div class="smp-card-header">
						<div class="smp-card-icon">
							<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
						</div>
						<div class="smp-card-info">
							<h3><?php echo esc_html( $card['title'] ); ?></h3>
						</div>
					</div>
					<p class="smp-card-desc"><?php echo esc_html( $card['description'] ); ?></p>
					<?php if ( ! empty( $card['highlights'] ) ) : ?>
					<ul class="smp-card-highlights">
						<?php foreach ( $card['highlights'] as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
					<div class="smp-card-actions">
						<?php if ( in_array( $slug, $installed_slugs, true ) ) : ?>
							<span class="smp-card-btn smp-card-btn-settings" style="cursor:default;">
								<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> <?php esc_html_e( 'Active', 'codewp-shield-monitor' ); ?>
							</span>
						<?php else : ?>
							<span class="smp-card-btn smp-card-btn-upgrade" style="cursor:default;">
								<span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Requires ShieldPress Pro', 'codewp-shield-monitor' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render addon content by slug.
	 * Each addon class should have a public render_page() method.
	 */
	private function render_addon_content( $slug ) {
		// Try to render from loaded addon class.
		$class_map = array(
			'media-pro' => 'Shield_Monitor_Media_Pro',
		);

		if ( isset( $class_map[ $slug ] ) && class_exists( $class_map[ $slug ] ) ) {
			$instance = new $class_map[ $slug ]();
			if ( method_exists( $instance, 'render_page' ) ) {
				$instance->render_page();
				return;
			}
		}

		// Addon not loaded — diagnose why and show helpful message.
		$addon_file   = SHIELD_MONITOR_PATH . 'addons/' . $slug . '/addon.php';
		$file_exists  = file_exists( $addon_file );
		$is_allowed   = $this->license->is_feature_allowed( $slug );
		$license      = $this->license->get_license();
		$addons_url   = admin_url( 'admin.php?page=codewp-shield-monitor-premium&tab=addons' );

		// Determine the specific reason.
		if ( ! $file_exists ) {
			$icon    = 'dashicons-download';
			$title   = __( 'Addon not installed', 'codewp-shield-monitor' );
			$message = __( 'This addon is not installed yet. Go to the Addons tab to install it.', 'codewp-shield-monitor' );
			$btn_url = $addons_url;
			$btn_txt = __( 'Browse Addons', 'codewp-shield-monitor' );
		} elseif ( ! $is_allowed ) {
			$icon    = 'dashicons-lock';
			$title   = __( 'License does not cover this addon', 'codewp-shield-monitor' );
			$message = sprintf(
				/* translators: 1: current plan, 2: license status */
				__( 'Your current plan (%1$s) or license status (%2$s) does not include this addon. Please upgrade your plan or verify your license.', 'codewp-shield-monitor' ),
				'<strong>' . esc_html( ucfirst( $license['plan'] ) ) . '</strong>',
				'<strong>' . esc_html( $license['status'] ) . '</strong>'
			);
			$btn_url = admin_url( 'admin.php?page=codewp-shield-monitor-license' );
			$btn_txt = __( 'Check License', 'codewp-shield-monitor' );
		} else {
			$icon    = 'dashicons-warning';
			$title   = __( 'Addon could not be loaded', 'codewp-shield-monitor' );
			$message = __( 'The addon files exist and your license allows it, but the addon failed to load. The addon file may be corrupted — try removing and reinstalling it.', 'codewp-shield-monitor' );
			$btn_url = $addons_url;
			$btn_txt = __( 'Go to Addons', 'codewp-shield-monitor' );
		}
		?>
		<style>
		.smp-addon-not-ready {
			text-align: center; padding: 60px 24px;
			background: #fff; border: 1px solid #e2e4e7; border-radius: 12px;
			max-width: 520px; margin: 40px auto;
		}
		.smp-addon-not-ready-icon {
			width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 16px;
			background: #f0f6fc; display: flex; align-items: center; justify-content: center;
		}
		.smp-addon-not-ready-icon .dashicons { font-size: 28px; width: 28px; height: 28px; color: #2271b1; }
		.smp-addon-not-ready h3 { margin: 0 0 8px; font-size: 16px; font-weight: 600; }
		.smp-addon-not-ready p { margin: 0 0 20px; color: #646970; font-size: 13px; line-height: 1.6; }
		.smp-addon-not-ready .button { display: inline-flex; align-items: center; gap: 4px; }
		.smp-addon-not-ready .button .dashicons { font-size: 16px; width: 16px; height: 16px; }
		</style>
		<div class="smp-addon-not-ready">
			<div class="smp-addon-not-ready-icon">
				<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			</div>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo wp_kses( $message, array( 'strong' => array() ) ); ?></p>
			<a href="<?php echo esc_url( $btn_url ); ?>" class="button button-primary">
				<?php echo esc_html( $btn_txt ); ?>
			</a>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────
	// No License: Upsell page
	// ─────────────────────────────────────────────────────────────────

	private function render_upsell() {
		?>
		<style>
		.smp-premium-wrap { max-width: 900px; }
		.smp-premium-hero {
			text-align: center; padding: 40px 24px 32px;
			background: #fff; border: 1px solid #e0e0e0; border-radius: 12px;
			margin: 20px 0 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
		}
		.smp-premium-hero-icon {
			width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 16px;
			background: linear-gradient(135deg, #2271b1, #135e96);
			display: flex; align-items: center; justify-content: center;
		}
		.smp-premium-hero-icon .dashicons { font-size: 32px; width: 32px; height: 32px; color: #fff; }
		.smp-premium-hero h1 { font-size: 26px; font-weight: 700; margin: 0 0 8px; }
		.smp-premium-hero p { font-size: 15px; color: #646970; margin: 0 0 24px; max-width: 500px; margin-left: auto; margin-right: auto; }
		.smp-premium-hero-actions { display: flex; gap: 12px; justify-content: center; align-items: center; }
		.smp-btn-pro {
			display: inline-block; padding: 12px 32px;
			background: linear-gradient(135deg, #2271b1, #135e96); color: #fff;
			font-size: 15px; font-weight: 600; border-radius: 6px; text-decoration: none;
			transition: opacity 0.2s; border: none; cursor: pointer;
		}
		.smp-btn-pro:hover, .smp-btn-pro:focus { opacity: 0.9; color: #fff; }
		.smp-btn-key { color: #2271b1; font-size: 14px; text-decoration: none; }
		.smp-btn-key:hover { text-decoration: underline; }

		.smp-features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 16px; }
		.smp-feature-card {
			background: #fff; border: 1px solid #e0e0e0; border-radius: 10px;
			padding: 24px; transition: box-shadow 0.2s;
		}
		.smp-feature-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
		.smp-feature-card-icon {
			width: 44px; height: 44px; border-radius: 10px; margin-bottom: 14px;
			background: #f0f6fc; display: flex; align-items: center; justify-content: center;
		}
		.smp-feature-card-icon .dashicons { font-size: 22px; width: 22px; height: 22px; color: #2271b1; }
		.smp-feature-card h3 { margin: 0 0 6px; font-size: 15px; font-weight: 600; }
		.smp-feature-card p { margin: 0 0 12px; font-size: 13px; color: #646970; line-height: 1.5; }
		.smp-feature-card ul {
			margin: 0; padding: 0; list-style: none;
		}
		.smp-feature-card li {
			font-size: 13px; color: #3c434a; padding: 4px 0;
			display: flex; align-items: center; gap: 8px;
		}
		.smp-feature-card li::before {
			content: ''; display: inline-block; width: 16px; height: 16px; min-width: 16px;
			border-radius: 50%; background: #00a32a;
			background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M6.5 11.5L3 8l1-1 2.5 2.5 5-5 1 1z' fill='%23fff'/%3E%3C/svg%3E");
			background-size: 14px; background-position: center; background-repeat: no-repeat;
		}
		</style>

		<div class="wrap smp-premium-wrap">
			<h1 class="wp-heading-inline" style="display:none;"><?php esc_html_e( 'Premium', 'codewp-shield-monitor' ); ?></h1>
			<hr class="wp-header-end" style="margin:0;border:0;height:0;">
			<!-- Hero -->
			<div class="smp-premium-hero">
				<div class="smp-premium-hero-icon">
					<span class="dashicons dashicons-star-filled"></span>
				</div>
				<h1><?php esc_html_e( 'Unlock Premium Features', 'codewp-shield-monitor' ); ?></h1>
				<p><?php esc_html_e( 'Get a Pro license to access advanced security tools, media optimization, and more.', 'codewp-shield-monitor' ); ?></p>
				<div class="smp-premium-hero-actions">
					<a href="<?php echo esc_url( SHIELD_MONITOR_APP_URL . '/shield-monitor-pro' ); ?>" class="smp-btn-pro" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get Pro License', 'codewp-shield-monitor' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=codewp-shield-monitor-license' ) ); ?>" class="smp-btn-key">
						<?php esc_html_e( 'I have a license key', 'codewp-shield-monitor' ); ?>
					</a>
				</div>
			</div>

			<!-- Feature Cards -->
			<div class="smp-features-grid">
				<?php foreach ( $this->feature_cards as $slug => $card ) : ?>
				<div class="smp-feature-card">
					<div class="smp-feature-card-icon">
						<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
					</div>
					<h3><?php echo esc_html( $card['title'] ); ?></h3>
					<p><?php echo esc_html( $card['description'] ); ?></p>
					<?php if ( ! empty( $card['highlights'] ) ) : ?>
					<ul>
						<?php foreach ( $card['highlights'] as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
