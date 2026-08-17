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
			'plugin_installed' => __( 'Plugin installed', 'codewp-shield-monitor' ), 'plugin_updated' => __( 'Plugin updated', 'codewp-shield-monitor' ),
			'plugin_activated' => __( 'Plugin activated', 'codewp-shield-monitor' ), 'plugin_deactivated' => __( 'Plugin deactivated', 'codewp-shield-monitor' ),
			'theme_installed' => __( 'Theme installed', 'codewp-shield-monitor' ), 'theme_updated' => __( 'Theme updated', 'codewp-shield-monitor' ),
			'theme_activated' => __( 'Theme activated', 'codewp-shield-monitor' ),
			'malware_scan_completed' => __( 'Malware scan completed', 'codewp-shield-monitor' ), 'malware_scan_suspicious' => __( 'Suspicious scan findings', 'codewp-shield-monitor' ),
			'malware_report_cleared' => __( 'Malware report cleared', 'codewp-shield-monitor' ),
			'contact_form_7_submission' => __( 'Contact Form 7 submission', 'codewp-shield-monitor' ),
			'woocommerce_order_created' => __( 'WooCommerce order', 'codewp-shield-monitor' ),
			'custom_post_type_created' => __( 'Custom post type created', 'codewp-shield-monitor' ),
		);
		return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	private function event_category( $type ) {
		if ( in_array( $type, array( 'admin_login', 'login_success', 'login_failed', 'login_lockout', 'login_ip_blocked', 'login_ip_unlocked' ), true ) ) {
			return 'login';
		}
		if ( in_array( $type, array( 'quick_login_link_created', 'quick_login_used' ), true ) ) {
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
		?>
		<section id="login-access" class="shield_monitor-section sp-login-access">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'ACCESS CONTROL', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Hidden login, quick login, and lockouts', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Move the public login path, enable app/web quick-login, and manage IPs with repeated failed attempts.', 'codewp-shield-monitor' ); ?></p></div><span class="sp-status <?php echo $this->settings->get( 'hide_login' ) ? 'is-on' : 'is-off'; ?>"><i></i><?php echo esc_html( $this->settings->get( 'hide_login' ) ? __( 'Hidden login active', 'codewp-shield-monitor' ) : __( 'Default login visible', 'codewp-shield-monitor' ) ); ?></span></div>
			<form action="options.php" method="post" class="sp-login-access-form">
				<?php settings_fields( 'codewp-shield-monitor' ); ?>
				<input type="hidden" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[quick_login_submitted]" value="1">
				<div class="sp-settings-grid">
					<div>
						<h3><?php esc_html_e( 'Login path', 'codewp-shield-monitor' ); ?></h3>
						<?php $this->checkbox( 'hide_login', __( 'Hide default login paths', 'codewp-shield-monitor' ), __( 'Block anonymous access to /wp-login.php and /wp-admin, then use the custom path below.', 'codewp-shield-monitor' ) ); ?>
						<label class="sp-field"><?php esc_html_e( 'Custom login slug', 'codewp-shield-monitor' ); ?><input type="text" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[login_slug]" value="<?php echo esc_attr( $login_slug ); ?>" pattern="[a-zA-Z0-9_-]+" autocomplete="off"><small><?php echo esc_html( $login_url ); ?></small></label>
					</div>
					<div>
						<h3><?php esc_html_e( 'Failed login rules', 'codewp-shield-monitor' ); ?></h3>
						<?php $this->checkbox( 'login_protection', __( 'Login protection', 'codewp-shield-monitor' ), __( 'Lock repeated failed login attempts.', 'codewp-shield-monitor' ) ); ?>
						<div class="sp-login-limits"><label><?php esc_html_e( 'Maximum login attempts', 'codewp-shield-monitor' ); ?><input type="number" min="3" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $this->settings->get( 'max_attempts' ) ); ?>"></label><label><?php esc_html_e( 'Lockout duration (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="5" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $this->settings->get( 'lockout_minutes' ) ); ?>"></label></div>
					</div>
				</div>
				<div class="sp-quick-login-box">
					<div>
						<h3><?php esc_html_e( 'App/Web quick login', 'codewp-shield-monitor' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Paired CodeWP Shield Monitor clients can request a one-time admin login link without asking for a WordPress username, password, CAPTCHA, or PIN.', 'codewp-shield-monitor' ); ?></p>
					</div>
					<div>
						<?php $this->checkbox( 'quick_login_enabled', __( 'Enable quick login', 'codewp-shield-monitor' ), __( 'Creates 60-second, one-use admin login links through the token-protected API.', 'codewp-shield-monitor' ) ); ?>
						<label class="sp-field"><?php esc_html_e( 'Login as administrator', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[quick_login_user_id]"><?php $this->admin_user_options( $quick_login_user_id ); ?></select><small><?php esc_html_e( 'Defaults to the first administrator account when no account is selected.', 'codewp-shield-monitor' ); ?></small></label>
					</div>
				</div>
				<?php submit_button( __( 'Save login access settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
			</form>
			<div class="sp-lockout-list"><h3><?php esc_html_e( 'Failed-login IPs', 'codewp-shield-monitor' ); ?></h3>
			<?php if ( empty( $lockouts ) ) : ?>
				<div class="sp-empty"><p><?php esc_html_e( 'No failed-login IPs have been recorded yet.', 'codewp-shield-monitor' ); ?></p></div>
			<?php else : ?>
				<div class="sp-table-wrap"><table class="sp-events"><thead><tr><th><?php esc_html_e( 'IP', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Attempts', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Status', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Last failed (UTC)', 'codewp-shield-monitor' ); ?></th><th><?php esc_html_e( 'Action', 'codewp-shield-monitor' ); ?></th></tr></thead><tbody><?php foreach ( $lockouts as $row ) : ?><tr><td><code><?php echo esc_html( $row['ip'] ); ?></code></td><td><?php echo esc_html( $row['attempts'] ); ?></td><td><?php echo esc_html( $row['locked'] ? sprintf( __( 'Blocked until %s', 'codewp-shield-monitor' ), $row['locked_until'] ) : __( 'Watching', 'codewp-shield-monitor' ) ); ?></td><td><?php echo esc_html( $row['last_failed_at'] ?: '-' ); ?></td><td><?php if ( $row['locked'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_login_unlock_ip"><input type="hidden" name="ip_hash" value="<?php echo esc_attr( $row['hash'] ); ?>"><?php wp_nonce_field( 'shield_monitor_login_unlock_ip' ); ?><button class="button" type="submit"><?php esc_html_e( 'Unlock', 'codewp-shield-monitor' ); ?></button></form><?php else : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_login_block_ip"><input type="hidden" name="ip_hash" value="<?php echo esc_attr( $row['hash'] ); ?>"><?php wp_nonce_field( 'shield_monitor_login_block_ip' ); ?><button class="button" type="submit"><?php esc_html_e( 'Block', 'codewp-shield-monitor' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
			<?php endif; ?></div>
		</section>
		<?php
	}

	private function advanced_hardening_panel() {
		?>
		<section id="advanced-hardening" class="shield_monitor-section sp-advanced-hardening">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'ADVANCED HARDENING', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Install locks, CAPTCHA, and admin PIN', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Add extra controls around administrator access and high-risk WordPress maintenance actions.', 'codewp-shield-monitor' ); ?></p></div></div>
			<form action="options.php" method="post" class="sp-settings-form">
				<?php settings_fields( 'codewp-shield-monitor' ); ?>
				<div class="sp-settings-grid">
					<div>
						<h3><?php esc_html_e( 'Software and file changes', 'codewp-shield-monitor' ); ?></h3>
						<?php $this->checkbox( 'disable_software_installation', __( 'Block plugin and theme installation', 'codewp-shield-monitor' ), __( 'Removes install, upload, and delete capabilities for plugins and themes.', 'codewp-shield-monitor' ) ); ?>
						<?php $this->checkbox( 'disable_file_editor', __( 'Disable plugin/theme file editor', 'codewp-shield-monitor' ), __( 'Prevents code editing from the WordPress dashboard.', 'codewp-shield-monitor' ) ); ?>
					</div>
					<div>
						<h3><?php esc_html_e( 'Admin login challenge', 'codewp-shield-monitor' ); ?></h3>
						<?php $this->checkbox( 'captcha_enabled', __( 'Enable login CAPTCHA', 'codewp-shield-monitor' ), __( 'Require Google reCAPTCHA or Cloudflare Turnstile on the WordPress login form.', 'codewp-shield-monitor' ) ); ?>
						<div class="sp-captcha-grid">
							<label class="sp-field"><?php esc_html_e( 'Provider', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_provider]"><option value="turnstile" <?php selected( $this->settings->get( 'captcha_provider' ), 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'codewp-shield-monitor' ); ?></option><option value="recaptcha" <?php selected( $this->settings->get( 'captcha_provider' ), 'recaptcha' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2', 'codewp-shield-monitor' ); ?></option></select></label>
							<label class="sp-field"><?php esc_html_e( 'Site key', 'codewp-shield-monitor' ); ?><input type="text" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_site_key]" value="<?php echo esc_attr( $this->settings->get( 'captcha_site_key' ) ); ?>" autocomplete="off"></label>
							<label class="sp-field"><?php esc_html_e( 'Secret key', 'codewp-shield-monitor' ); ?><input type="password" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[captcha_secret_key]" value="<?php echo esc_attr( $this->settings->get( 'captcha_secret_key' ) ); ?>" autocomplete="new-password"></label>
						</div>
						<?php $this->checkbox( 'admin_pin_enabled', __( 'Require 6-digit admin PIN', 'codewp-shield-monitor' ), __( 'Administrators must enter the PIN together with their password.', 'codewp-shield-monitor' ) ); ?>
						<label class="sp-field sp-pin-field"><?php esc_html_e( 'Set new PIN', 'codewp-shield-monitor' ); ?><input type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[admin_pin]" value="" autocomplete="new-password" placeholder="123456"><small><?php echo esc_html( $this->settings->get( 'admin_pin_hash' ) ? __( 'A PIN is configured. Leave blank to keep it.', 'codewp-shield-monitor' ) : __( 'Enter exactly 6 digits before enabling PIN login.', 'codewp-shield-monitor' ) ); ?></small></label>
					</div>
				</div>
				<?php submit_button( __( 'Save advanced hardening', 'codewp-shield-monitor' ), 'primary sp-save' ); ?>
			</form>
		</section>
		<?php
	}

	private function database_security_panel() {
		global $wpdb;
		?>
		<section id="database-security" class="shield_monitor-section sp-database-security">
			<div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'DATABASE SECURITY', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Database table prefix', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Review database-hardening status without modifying WordPress configuration files.', 'codewp-shield-monitor' ); ?></p></div></div>
			<div class="sp-prefix-tool">
				<div>
					<h3><?php esc_html_e( 'Database table prefix', 'codewp-shield-monitor' ); ?></h3>
					<p><?php echo esc_html( sprintf( __( 'Current prefix: %s', 'codewp-shield-monitor' ), $wpdb->prefix ) ); ?></p>
					<p class="description"><?php esc_html_e( 'Changing the table prefix requires database changes and a matching wp-config.php update. For safety and WordPress.org policy compatibility, this plugin does not perform that operation automatically.', 'codewp-shield-monitor' ); ?></p>
				</div>
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
		?>
		<div class="wrap shield_monitor-wrap sp-dashboard">
			<header class="sp-hero"><div class="sp-brand"><div><h1><?php esc_html_e( 'CodeWP Shield Monitor Security', 'codewp-shield-monitor' ); ?></h1><p><?php esc_html_e( 'Protection, integrity monitoring, and security alerts for WordPress.', 'codewp-shield-monitor' ); ?></p><small class="sp-brand-meta"><a href="<?php echo esc_url( SHIELD_MONITOR_APP_URL ); ?>" target="_blank" rel="noopener noreferrer">shieldpress.net</a><span>v<?php echo esc_html( SHIELD_MONITOR_VERSION ); ?></span></small></div></div><div class="sp-score sp-score-<?php echo esc_attr( $score_tone ); ?>"><div class="sp-score-ring" style="--score:<?php echo esc_attr( $data['health_score'] ); ?>"><strong><?php echo esc_html( $data['health_score'] ); ?></strong><small>/100</small></div><div class="sp-score-copy"><em><?php esc_html_e( 'Security score', 'codewp-shield-monitor' ); ?></em><span><?php echo esc_html( $score_label ); ?></span><small><?php echo esc_html( $score_detail ); ?></small></div></div></header>
			<nav class="shield_monitor-nav"><a href="#overview"><?php esc_html_e( 'Overview', 'codewp-shield-monitor' ); ?></a><a href="#login-access"><?php esc_html_e( 'Login access', 'codewp-shield-monitor' ); ?></a><a href="#advanced-hardening"><?php esc_html_e( 'Advanced', 'codewp-shield-monitor' ); ?></a><a href="#database-security"><?php esc_html_e( 'Database', 'codewp-shield-monitor' ); ?></a><a href="#integrity"><?php esc_html_e( 'File integrity', 'codewp-shield-monitor' ); ?></a><a href="#malware"><?php esc_html_e( 'Malware scan', 'codewp-shield-monitor' ); ?></a><a href="#threats"><?php esc_html_e( 'Threats', 'codewp-shield-monitor' ); ?></a><a href="#logs"><?php esc_html_e( '30-day logs', 'codewp-shield-monitor' ); ?></a><a href="#recommendations"><?php esc_html_e( 'Recommendations', 'codewp-shield-monitor' ); ?></a><a href="#connect"><?php esc_html_e( 'App / Web', 'codewp-shield-monitor' ); ?></a><a href="#settings"><?php esc_html_e( 'Settings', 'codewp-shield-monitor' ); ?></a><a href="#notifications"><?php esc_html_e( 'Notifications', 'codewp-shield-monitor' ); ?></a></nav>
			<?php settings_errors(); ?>

			<section id="overview" class="sp-stat-grid">
				<?php $this->stat_card( 'dashicons-lock', __( 'HTTPS', 'codewp-shield-monitor' ), $security['https'] ? __( 'Secure', 'codewp-shield-monitor' ) : __( 'Not secure', 'codewp-shield-monitor' ), __( 'Encrypted website connection', 'codewp-shield-monitor' ), $security['https'] ? 'green' : 'red' ); ?>
				<?php $this->stat_card( 'dashicons-admin-users', __( 'Admin logins', 'codewp-shield-monitor' ), $security['admin_logins_30_days'], __( 'Recorded in the last 30 days', 'codewp-shield-monitor' ), 'blue' ); ?>
				<?php $this->stat_card( 'dashicons-dismiss', __( 'Failed logins', 'codewp-shield-monitor' ), $security['failed_logins_30_days'], __( 'Failed or blocked in 30 days', 'codewp-shield-monitor' ), $security['failed_logins_30_days'] ? 'amber' : 'green' ); ?>
				<?php $this->stat_card( 'dashicons-media-code', __( 'File changes', 'codewp-shield-monitor' ), $files['changes_30_days'], __( 'Detected in the last 30 days', 'codewp-shield-monitor' ), $files['changes_30_days'] ? 'amber' : 'green' ); ?>
				<?php $this->stat_card( 'dashicons-warning', __( 'Threat findings', 'codewp-shield-monitor' ), absint( $threats['summary']['total'] ?? 0 ), __( 'Review signals from local intelligence checks', 'codewp-shield-monitor' ), ! empty( $threats['summary']['critical'] ) || ! empty( $threats['summary']['high'] ) ? 'red' : ( ! empty( $threats['summary']['medium'] ) ? 'amber' : 'green' ) ); ?>
			</section>

			<section class="shield_monitor-section sp-score-tasks"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'SCORE REVIEW', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Items affecting your security score', 'codewp-shield-monitor' ); ?></h2></div></div><ul><?php foreach ( $score_tasks as $task ) : ?><li><span><?php echo esc_html( $task['title'] ); ?></span><em><?php echo $task['impact'] ? esc_html( sprintf( __( '-%d pts', 'codewp-shield-monitor' ), absint( $task['impact'] ) ) ) : esc_html__( 'OK', 'codewp-shield-monitor' ); ?></em></li><?php endforeach; ?></ul></section>

			<?php $this->login_access_panel( $login_lockouts ); ?>
			<?php $this->advanced_hardening_panel(); ?>
			<?php $this->database_security_panel(); ?>

			<section id="integrity" class="shield_monitor-section sp-integrity"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'FILE PROTECTION', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Important file integrity', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'CodeWP Shield Monitor checks cryptographic hashes every five minutes and alerts you when a monitored file changes.', 'codewp-shield-monitor' ); ?></p></div><span class="sp-status <?php echo $files['enabled'] ? 'is-on' : 'is-off'; ?>"><i></i><?php echo esc_html( $files['enabled'] ? __( 'Monitoring active', 'codewp-shield-monitor' ) : __( 'Monitoring disabled', 'codewp-shield-monitor' ) ); ?></span></div><div class="sp-integrity-grid"><div><strong><?php echo esc_html( $files['monitored_files'] ); ?></strong><span><?php esc_html_e( 'Important files monitored', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $files['writable_files'] ); ?></strong><span><?php esc_html_e( 'Currently writable files', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $files['last_scan'] ?: __( 'Waiting for first scan', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last integrity scan', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $files['baseline_summary']['modified'] ?? 0 ) + absint( $files['baseline_summary']['deleted'] ?? 0 ) + absint( $files['baseline_summary']['added'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Changed baseline files', 'codewp-shield-monitor' ); ?></span></div></div><div class="sp-integrity-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_trust_file_baseline"><?php wp_nonce_field( 'shield_monitor_trust_file_baseline' ); ?><button class="button" type="submit"><?php esc_html_e( 'Trust current baseline', 'codewp-shield-monitor' ); ?></button></form></div><div class="sp-note"><p><?php esc_html_e( 'CodeWP Shield Monitor monitors wp-config.php, .htaccess, WordPress bootstrap/login files, the active theme, and active plugin entry files. It stores path, size, MD5, SHA-256, first seen time, last seen time, last changed time, and status; never file contents.', 'codewp-shield-monitor' ); ?></p></div></section>

			<section id="logs" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( '30-DAY HISTORY', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Security audit history', 'codewp-shield-monitor' ); ?></h2></div><span class="sp-retention"><?php esc_html_e( 'Automatically deleted after 30 days', 'codewp-shield-monitor' ); ?></span></div><?php $this->events_table( $history_logs ); ?></section>

			<section id="malware" class="shield_monitor-section sp-malware-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'MALWARE SCAN', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Suspicious files and database records', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Manual scans run in small batches with progress updates to reduce CPU and memory spikes.', 'codewp-shield-monitor' ); ?></p></div><div class="sp-malware-summary"><strong><?php echo esc_html( strtoupper( $malware_summary['risk_level'] ?? 'unknown' ) ); ?></strong><span><?php echo esc_html( sprintf( __( '%d findings', 'codewp-shield-monitor' ), absint( $malware_summary['total'] ?? 0 ) ) ); ?></span><div class="sp-malware-actions"><button class="button button-primary" id="shield_monitor-malware-scan-start" type="button"><?php esc_html_e( 'Run scan now', 'codewp-shield-monitor' ); ?></button><?php if ( ! empty( $malware['download_url'] ) ) : ?><a class="button" href="<?php echo esc_url( $malware['download_url'] ); ?>"><?php esc_html_e( 'Download report', 'codewp-shield-monitor' ); ?></a><?php endif; ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_clear_malware_report"><?php wp_nonce_field( 'shield_monitor_clear_malware_report' ); ?><button class="button" type="submit"><?php esc_html_e( 'Clear log', 'codewp-shield-monitor' ); ?></button></form></div></div></div><div class="sp-scan-progress" id="shield_monitor-malware-progress" hidden><div class="sp-scan-progress-head"><strong><?php esc_html_e( 'Manual scan progress', 'codewp-shield-monitor' ); ?></strong><span id="shield_monitor-malware-progress-label">0%</span></div><div class="sp-scan-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i style="width:0%"></i></div><small id="shield_monitor-malware-progress-detail"><?php esc_html_e( 'Preparing scan...', 'codewp-shield-monitor' ); ?></small></div><div class="sp-malware-metrics"><div><strong><?php echo esc_html( absint( $malware_summary['critical'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Critical', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $malware_summary['high'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'High', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $malware_summary['medium'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Medium', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $malware['last_scan_at'] ?: __( 'Not run yet', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last scan', 'codewp-shield-monitor' ); ?></span></div></div><?php $this->malware_table( $malware ); ?></section>

			<section id="threats" class="shield_monitor-section sp-malware-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'THREAT INTELLIGENCE', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'WordPress compromise signals', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Local checks for administrator anomalies, executable uploads, suspicious options, cron entries, fake CAPTCHA content, external scripts, cloaking signals, and hardening gaps.', 'codewp-shield-monitor' ); ?></p></div><div class="sp-malware-summary"><strong><?php echo esc_html( strtoupper( $threats['summary']['risk_level'] ?? 'clean' ) ); ?></strong><span><?php echo esc_html( sprintf( __( '%d findings', 'codewp-shield-monitor' ), absint( $threats['summary']['total'] ?? 0 ) ) ); ?></span></div></div><div class="sp-malware-metrics"><div><strong><?php echo esc_html( absint( $threats['summary']['critical'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Critical', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $threats['summary']['high'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'High', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( absint( $threats['summary']['medium'] ?? 0 ) ); ?></strong><span><?php esc_html_e( 'Medium', 'codewp-shield-monitor' ); ?></span></div><div><strong><?php echo esc_html( $threats['generated_at'] ?? __( 'Not run yet', 'codewp-shield-monitor' ) ); ?></strong><span><?php esc_html_e( 'Last intelligence check', 'codewp-shield-monitor' ); ?></span></div></div><?php $this->threat_table( $threats ); ?><?php if ( ! empty( $threats['incident_report']['next_steps'] ) ) : ?><div class="sp-note"><strong><?php esc_html_e( 'Incident response next steps', 'codewp-shield-monitor' ); ?></strong><ul><?php foreach ( $threats['incident_report']['next_steps'] as $step ) : ?><li><?php echo esc_html( $step ); ?></li><?php endforeach; ?></ul></div><?php endif; ?></section>

			<section id="recommendations" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'HARDENING GUIDE', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Recommended security improvements', 'codewp-shield-monitor' ); ?></h2></div></div><ul class="sp-recommendations"><?php $this->recommendation( $security['https'], __( 'Use HTTPS everywhere', 'codewp-shield-monitor' ), __( 'Install a valid SSL certificate and redirect all HTTP traffic to HTTPS.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( 0 === $updates, __( 'Keep WordPress software updated', 'codewp-shield-monitor' ), $updates ? sprintf( __( '%d WordPress, plugin, or theme updates are pending.', 'codewp-shield-monitor' ), $updates ) : __( 'WordPress core, plugins, and themes have no known pending updates.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['login_protection'], __( 'Protect the login form', 'codewp-shield-monitor' ), __( 'Limit repeated login attempts and use strong unique administrator passwords.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $files['enabled'], __( 'Monitor important files', 'codewp-shield-monitor' ), __( 'Detect unexpected edits, additions, and deletions to security-sensitive files.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $this->settings->get( 'email_alerts' ), __( 'Enable security email alerts', 'codewp-shield-monitor' ), __( 'Receive alerts for administrator logins, blocked attacks, file changes, and scan findings.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['two_factor_authentication']['detected'] ), __( 'Enable two-factor authentication', 'codewp-shield-monitor' ), __( 'Require a second authentication factor for every administrator account.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['backup']['detected'] ), __( 'Install a backup solution', 'codewp-shield-monitor' ), __( 'Keep automatic off-site backups and regularly test that restoration works.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! empty( $data['integrations']['cache']['detected'] ), __( 'Install a cache plugin', 'codewp-shield-monitor' ), __( 'Use a trusted cache/performance plugin to reduce server load during traffic spikes and automated attacks.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( ! $cf7_active || $captcha_active, __( 'Protect Contact Form 7 with CAPTCHA', 'codewp-shield-monitor' ), __( 'If Contact Form 7 is active, configure reCAPTCHA, hCaptcha, Turnstile, or another anti-spam challenge to reduce automated spam.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['xmlrpc_disabled'], __( 'Disable XML-RPC when unused', 'codewp-shield-monitor' ), __( 'Turn off XML-RPC if the site does not use Jetpack, mobile apps, or remote publishing.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['heartbeat_limited'], __( 'Limit WordPress Heartbeat XHR', 'codewp-shield-monitor' ), __( 'Reduce background admin-ajax polling to lower load on shared hosting.', 'codewp-shield-monitor' ) ); ?><?php $this->recommendation( $security['rest_api_restricted'], __( 'Restrict public REST API if possible', 'codewp-shield-monitor' ), __( 'Block anonymous REST API access when the theme and plugins do not need public REST endpoints.', 'codewp-shield-monitor' ) ); ?></ul></section>

			<section id="connect" class="shield_monitor-section shield_monitor-connect"><div><span class="sp-kicker"><?php esc_html_e( 'REMOTE MONITORING', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Connect CodeWP Shield Monitor App or Web', 'codewp-shield-monitor' ); ?></h2><p><?php esc_html_e( 'Scan the QR code, or enter the website URL and pairing code manually. The code works once and expires in 10 minutes.', 'codewp-shield-monitor' ); ?></p><label class="shield_monitor-manual-label"><?php esc_html_e( 'Website URL', 'codewp-shield-monitor' ); ?><code><?php echo esc_html( home_url( '/' ) ); ?></code></label><label class="shield_monitor-manual-label"><?php esc_html_e( 'Manual pairing code', 'codewp-shield-monitor' ); ?><code class="shield_monitor-pair-code"><?php echo esc_html( $pairing['code'] ); ?></code></label></div><div id="shield_monitor-qr" class="shield_monitor-qr" data-payload="<?php echo esc_attr( wp_json_encode( $pairing ) ); ?>"><span><?php esc_html_e( 'Generating QR code…', 'codewp-shield-monitor' ); ?></span></div><div class="shield_monitor-devices"><h3><?php esc_html_e( 'Connected clients', 'codewp-shield-monitor' ); ?></h3><?php if ( empty( $tokens ) ) : ?><p><?php esc_html_e( 'No App or Web client is connected yet.', 'codewp-shield-monitor' ); ?></p><?php else : ?><?php foreach ( $tokens as $id => $token ) : ?><div class="shield_monitor-device"><div><strong><?php echo esc_html( $token['label'] ); ?></strong><small><?php echo esc_html( sprintf( __( 'Created %s · Last used %s', 'codewp-shield-monitor' ), $token['created_at'], $token['last_used'] ?: __( 'never', 'codewp-shield-monitor' ) ) ); ?></small><small><?php echo esc_html( sprintf( __( 'Expires %s · Scopes: %s', 'codewp-shield-monitor' ), $token['expires_at'] ?? __( 'after next use', 'codewp-shield-monitor' ), implode( ', ', $token['scopes'] ?? array( 'status:read', 'events:read', 'token:rotate' ) ) ) ); ?></small></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="shield_monitor_revoke_token"><input type="hidden" name="token_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'shield_monitor_revoke_token' ); ?><button class="button" type="submit"><?php esc_html_e( 'Revoke', 'codewp-shield-monitor' ); ?></button></form></div><?php endforeach; ?><?php endif; ?></div></section>

			<section id="settings" class="shield_monitor-section"><div class="sp-section-head"><div><span class="sp-kicker"><?php esc_html_e( 'CONFIGURATION', 'codewp-shield-monitor' ); ?></span><h2><?php esc_html_e( 'Security and alert settings', 'codewp-shield-monitor' ); ?></h2></div><span class="sp-retention"><?php esc_html_e( 'Logs retained for 30 days', 'codewp-shield-monitor' ); ?></span></div><form action="options.php" method="post" class="sp-settings-form"><?php settings_fields( 'codewp-shield-monitor' ); ?><div class="sp-settings-grid"><div><h3><?php esc_html_e( 'Protection', 'codewp-shield-monitor' ); ?></h3><?php $this->checkbox( 'login_protection', __( 'Login protection', 'codewp-shield-monitor' ), __( 'Lock repeated failed login attempts.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'file_monitoring', __( 'Important file monitoring', 'codewp-shield-monitor' ), __( 'Scan important file hashes every five minutes.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'malware_scanning', __( 'Suspicious code and database scanning', 'codewp-shield-monitor' ), __( 'Run lightweight heuristic scans at night and after administrator logins with cooldown protection.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'core_checksum_verification', __( 'WordPress core checksum verification', 'codewp-shield-monitor' ), __( 'Compare core files with official WordPress.org checksums every 12 hours.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'block_enumeration', __( 'Block user enumeration', 'codewp-shield-monitor' ), __( 'Restrict public author and REST user discovery.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'disable_xmlrpc', __( 'Disable XML-RPC', 'codewp-shield-monitor' ), __( 'Enable only if the site does not use Jetpack, mobile apps, or remote publishing.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'restrict_rest_api', __( 'Restrict public REST API', 'codewp-shield-monitor' ), __( 'Block anonymous REST API calls except CodeWP Shield Monitor pairing and public score endpoints.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'limit_heartbeat', __( 'Limit Heartbeat XHR', 'codewp-shield-monitor' ), __( 'Slow admin-ajax heartbeat polling and disable it on the public frontend.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'security_headers', __( 'Security headers', 'codewp-shield-monitor' ), __( 'Add safe browser protection headers.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'disable_file_editor', __( 'Disable dashboard file editor', 'codewp-shield-monitor' ), __( 'Prevent code editing from the WordPress dashboard.', 'codewp-shield-monitor' ) ); ?></div><div><h3><?php esc_html_e( 'Email alerts', 'codewp-shield-monitor' ); ?></h3><?php $this->checkbox( 'email_alerts', __( 'Enable email alerts', 'codewp-shield-monitor' ), sprintf( __( 'Send security alerts to %s.', 'codewp-shield-monitor' ), get_option( 'admin_email' ) ) ); ?><?php $this->checkbox( 'alert_admin_login', __( 'Administrator login alerts', 'codewp-shield-monitor' ), __( 'Alert whenever an administrator signs in.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_admin_user_changes', __( 'New administrator alerts', 'codewp-shield-monitor' ), __( 'Alert when a new user receives administrator access.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_lockouts', __( 'Login attack alerts', 'codewp-shield-monitor' ), __( 'Alert when repeated login attempts are blocked.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_file_changes', __( 'File change alerts', 'codewp-shield-monitor' ), __( 'Alert when monitored files are added, edited, or deleted.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'alert_malware_findings', __( 'Suspicious scan alerts', 'codewp-shield-monitor' ), __( 'Alert when the malware scanner finds high or critical suspicious items.', 'codewp-shield-monitor' ) ); ?><?php $this->checkbox( 'audit_log', __( 'Local security logs', 'codewp-shield-monitor' ), __( 'Store login and file events locally for 30 days.', 'codewp-shield-monitor' ) ); ?></div></div><div class="sp-scan-schedule"><label><?php esc_html_e( 'Automatic malware scan', 'codewp-shield-monitor' ); ?><select name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[malware_scan_frequency]"><option value="daily" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'daily' ); ?>><?php esc_html_e( 'Every day', 'codewp-shield-monitor' ); ?></option><option value="weekly" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'weekly' ); ?>><?php esc_html_e( 'Every week', 'codewp-shield-monitor' ); ?></option><option value="monthly" <?php selected( $this->settings->get( 'malware_scan_frequency' ), 'monthly' ); ?>><?php esc_html_e( 'Every month', 'codewp-shield-monitor' ); ?></option></select></label><label><?php esc_html_e( 'Scan time', 'codewp-shield-monitor' ); ?><input type="time" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[malware_scan_time]" value="<?php echo esc_attr( $this->settings->get( 'malware_scan_time' ) ); ?>"></label></div><div class="sp-login-limits"><label><?php esc_html_e( 'Maximum login attempts', 'codewp-shield-monitor' ); ?><input type="number" min="3" max="20" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[max_attempts]" value="<?php echo esc_attr( $this->settings->get( 'max_attempts' ) ); ?>"></label><label><?php esc_html_e( 'Lockout duration (minutes)', 'codewp-shield-monitor' ); ?><input type="number" min="5" max="1440" name="<?php echo esc_attr( Shield_Monitor_Settings::OPTION ); ?>[lockout_minutes]" value="<?php echo esc_attr( $this->settings->get( 'lockout_minutes' ) ); ?>"></label></div><?php submit_button( __( 'Save security settings', 'codewp-shield-monitor' ), 'primary sp-save' ); ?></form></section>
			<?php $this->notification_settings_section(); ?>
		</div>
		<?php
	}
}
