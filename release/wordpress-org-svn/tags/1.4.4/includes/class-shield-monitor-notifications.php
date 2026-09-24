<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Notifications {
	private $settings;
	private $logger;

	public function __construct( $settings, $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register_hooks() {
		add_action( 'wpcf7_mail_sent', array( $this, 'contact_form_7_submission' ) );
		add_action( 'woocommerce_new_order', array( $this, 'woocommerce_order_created' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'custom_post_type_created' ), 10, 3 );
	}

	public function contact_form_7_submission( $contact_form ) {
		if ( ! $this->settings->get( 'notify_contact_form_7' ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$form_id = is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? absint( $contact_form->id() ) : 0;
		$key     = 'shield_monitor_cf7_submission_' . $form_id . '_' . md5( wp_json_encode( $submission->get_posted_data() ) );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );

		$this->logger->record(
			'contact_form_7_submission',
			array(
				'notification'  => true,
				'source'        => 'contact_form_7',
				'form_id'       => $form_id,
				'form_title'    => is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? sanitize_text_field( $contact_form->title() ) : '',
				'submitted_at'  => current_time( 'mysql', true ),
				'posted_fields' => $this->sanitize_form_fields( $submission->get_posted_data() ),
			)
		);
	}

	public function woocommerce_order_created( $order_id, $order = null ) {
		if ( ! $this->settings->get( 'notify_woocommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$key = 'shield_monitor_wc_order_' . absint( $order->get_id() );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		$created = $order->get_date_created();

		$this->logger->record(
			'woocommerce_order_created',
			array(
				'notification'   => true,
				'source'         => 'woocommerce',
				'order_id'       => absint( $order->get_id() ),
				'order_number'   => sanitize_text_field( $order->get_order_number() ),
				'status'         => sanitize_key( $order->get_status() ),
				'total'          => sanitize_text_field( $order->get_total() ),
				'currency'       => sanitize_text_field( $order->get_currency() ),
				'item_count'     => absint( $order->get_item_count() ),
				'payment_method' => sanitize_text_field( $order->get_payment_method_title() ),
				'customer_id'    => absint( $order->get_customer_id() ),
				'billing'        => array(
					'name'     => sanitize_text_field( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
					'email'    => sanitize_email( $order->get_billing_email() ),
					'phone'    => sanitize_text_field( $order->get_billing_phone() ),
					'city'     => sanitize_text_field( $order->get_billing_city() ),
					'state'    => sanitize_text_field( $order->get_billing_state() ),
					'country'  => sanitize_text_field( $order->get_billing_country() ),
					'postcode' => sanitize_text_field( $order->get_billing_postcode() ),
				),
				'created_at'     => $created ? $created->date( DATE_ATOM ) : current_time( 'mysql', true ),
			),
			absint( $order->get_customer_id() )
		);
	}

	public function custom_post_type_created( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'auto-draft' === $new_status ) {
			return;
		}
		$selected = array_map( 'sanitize_key', (array) $this->settings->get( 'notify_post_types' ) );
		if ( ! in_array( sanitize_key( $post->post_type ), $selected, true ) ) {
			return;
		}
		$key = 'shield_monitor_post_type_notification_' . absint( $post->ID );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		$this->logger->record(
			'custom_post_type_created',
			array(
				'notification' => true,
				'source'       => 'post_type',
				'post_id'      => absint( $post->ID ),
				'post_type'    => sanitize_key( $post->post_type ),
				'title'        => sanitize_text_field( get_the_title( $post ) ),
				'status'       => sanitize_key( $post->post_status ),
				'permalink'    => get_permalink( $post ) ? esc_url_raw( get_permalink( $post ) ) : '',
				'created_at'   => get_post_time( DATE_ATOM, true, $post ),
			),
			absint( $post->post_author )
		);
	}

	private function sanitize_form_fields( $posted_data ) {
		$fields = array();
		foreach ( (array) $posted_data as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! $key || 0 === strpos( $key, '_' ) || in_array( $key, array( 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response' ), true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$fields[ $key ] = array_slice( array_map( 'sanitize_text_field', wp_unslash( $value ) ), 0, 10 );
				continue;
			}
			$fields[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		return $fields;
	}
}
