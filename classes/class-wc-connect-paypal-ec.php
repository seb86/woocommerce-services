<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Connect_PayPal_EC' ) ) {

	/**
	 * Modify PPEC plugin behavior to facilitate proxying and authenticating requests via server
	 */
	class WC_Connect_PayPal_EC {

		/**
		 * @var WC_Connect_API_Client
		 */
		private $api_client;

		/**
		 * @var WC_Connect_Nux
		 */
		private $nux;

		public function __construct( WC_Connect_API_Client $api_client, WC_Connect_Nux $nux ) {
			$this->api_client = $api_client;
			$this->nux = $nux;
		}

		public function init() {
			if ( ! function_exists( 'wc_gateway_ppec' ) ) {
				return;
			}

			$this->initialize_settings();

			$settings = wc_gateway_ppec()->settings;
			add_filter( 'woocommerce_paypal_express_checkout_settings', array( $this, 'paypal_ec_settings_meta' ) );
			add_action( 'load-woocommerce_page_wc-settings', array( $this, 'maybe_set_reroute_requests' ) );

			if ( 'yes' === $settings->reroute_requests ) {
				// If empty, populate Sandbox and Live API Subject values with provided email
				if (
					empty( $settings->sandbox_api_subject ) &&
					empty( $settings->sandbox_api_username ) &&
					empty( $settings->api_username )
				) {
					$email = isset( $settings->email ) ? $settings->email : $settings->api_subject;
					$settings->api_subject = $email;
					$settings->sandbox_api_subject = $email;
					$settings->save();
				}

				$username = $settings->get_active_api_credentials()->get_username();
				$subject  = $settings->get_active_api_credentials()->get_subject();

				if ( empty( $username ) && ! empty( $subject ) ) {
					// Reroute Express Checkout requests from the PPEC extension via WCS to pick up API credentials
					add_filter( 'woocommerce_paypal_express_checkout_request_body', array( $this, 'request_body' ) );

					add_filter( 'option_woocommerce_ppec_paypal_settings', array( $this, 'paypal_ec_settings' ) );
					add_filter( 'woocommerce_payment_gateway_supports', array( $this, 'supports' ), 10, 3 );

					add_filter( 'pre_option_wc_gateway_ppce_prompt_to_connect', '__return_empty_string' );
					if ( 'live' === $settings->environment ) {
						add_action( 'woocommerce_order_status_on-hold', array( $this, 'maybe_set_banner' ) );
						add_action( 'woocommerce_payment_complete', array( $this, 'maybe_set_banner' ) );
						add_action( 'current_screen', array( $this, 'maybe_init_banner' ) );
					}
				}
			}
		}

		public function request_body( $body ) {
			$methods_to_proxy = array( 'SetExpressCheckout', 'GetExpressCheckoutDetails', 'DoExpressCheckoutPayment' );
			if ( in_array( $body['METHOD'], $methods_to_proxy ) ) {
				add_filter( 'pre_http_request', array( $this, 'proxy_request' ), 10, 3 );
			} else {
				remove_filter( 'pre_http_request', array( $this, 'proxy_request' ), 10, 3 );
			}
			return $body;
		}

		public function proxy_request( $preempt, $r, $url ) {
			if ( ! preg_match( '/paypal.com\/nvp$/', $url ) ) {
				return false;
			}

			$settings = wc_gateway_ppec()->settings;
			return $this->api_client->proxy_request( 'paypal/nvp/' . $settings->environment, $r );
		}

		/**
		 * Limit supported payment gateway features to payments
		 */
		public function supports( $supported, $feature, $gateway ) {
			return 'ppec_paypal' === $gateway->id ? 'products' === $feature : $supported;
		}

		public function maybe_set_banner( $order_id ) {
			$order          = wc_get_order( $order_id );
			$payment_method = WC_Connect_Compatibility::instance()->get_payment_method( $order );

			if ( 'ppec_paypal' === $payment_method ) {
				update_option( 'wc_connect_banner_ppec', 'yes' );
			}
		}

		/**
		 * Once a payment is received, show prompt to connect a PayPal account on certain screens
		 */
		public function maybe_init_banner( $screen ) {
			if (
				'yes' !== get_option( 'wc_connect_banner_ppec', null ) ||
				'yes' === get_option( 'wc_connect_dismiss_banner_ppec', null )
			) {
				return;
			}

			if ( // Display if on any of these admin pages.
				( // Orders list.
					'shop_order' === $screen->post_type
					&& 'edit' === $screen->base
					)
				|| ( // Edit order page.
					'shop_order' === $screen->post_type
					&& 'post' === $screen->base
					)
				|| ( // WooCommerce settings.
					'woocommerce_page_wc-settings' === $screen->base
					)
				|| ( // WooCommerce featured extension page
					'woocommerce_page_wc-addons' === $screen->base
					&& isset( $_GET['section'] ) && 'featured' === $_GET['section']
					)
				|| ( // WooCommerce payment gateway extension page
					'woocommerce_page_wc-addons' === $screen->base
					&& isset( $_GET['section'] ) && 'payment_gateways' === $_GET['section']
					)
				|| 'plugins' === $screen->base
			) {
				wp_enqueue_style( 'wc_connect_banner' );
				add_action( 'admin_notices', array( $this, 'banner' ) );
			}
		}

		public function banner() {
			$prompt = __( 'Link a new or existing PayPal account to enable PayPal Express Checkout features beyond simply taking payments: issue refunds, capture charges after order completion, and more.', 'woocommerce-services' );

			$this->nux->show_nux_banner( array(
				'title'          => __( 'Connect a PayPal account', 'woocommerce-services' ),
				'description'    => esc_html( $prompt ),
				'button_text'    => __( 'Connect', 'woocommerce-services' ),
				'button_link'    => wc_gateway_ppec()->ips->get_signup_url( 'live' ),
				'image_url'      => plugins_url( 'images/cashier.svg', dirname( __FILE__ ) ),
				'should_show_jp' => false,
				'dismiss_option' => 'ppec',
			) );
		}

		public function initialize_settings() {
			$settings = get_option( 'woocommerce_ppec_paypal_settings', array() );

			if ( ! isset( $settings['button_size'] ) ) {
				$gateway = new WC_Gateway_PPEC_With_PayPal();
				$settings_meta = $gateway->form_fields;
				foreach ( $settings_meta as $key => $setting_meta ) {
					if ( ! isset( $settings[ $key ] ) && isset( $setting_meta['default'] ) ) {
						$settings[ $key ] = $setting_meta['default'];
					}
				}
				if ( ! isset( $settings['reroute_requests'] ) ) {
					$settings['reroute_requests'] = 'no';
				}
				update_option( 'woocommerce_ppec_paypal_settings', $settings );
				wc_gateway_ppec()->settings->load( true );
			}
		}

		public function paypal_ec_settings( $settings ) {
			$settings['paymentaction'] = 'sale';
			return $settings;
		}

		/**
		 * Modify PPEC settings
		 */
		public function paypal_ec_settings_meta( $settings_meta ) {
			$settings = wc_gateway_ppec()->settings;

			if ( 'yes' === $settings->reroute_requests ) {
				// Prevent user from choosing option that will cause requests to fail
				$settings_meta['paymentaction']['disabled'] = true;
				$settings_meta['paymentaction']['description'] = sprintf( __( '%s (Note that "authorizing payment only" requires linking a PayPal account.)', 'woocommerce-services' ), $settings_meta['paymentaction']['description'] );

				// Communicate WCS proxying and provide option to disable
				$reset_link = add_query_arg(
					array( 'reroute_requests' => 'no', 'nonce' => wp_create_nonce( 'reroute_requests' ) ),
					wc_gateway_ppec()->get_admin_setting_link()
				);
				$api_creds_template = __( 'Payments will be authenticated by WooCommerce Services and directed to the following email address. To disable this feature and link a PayPal account, <a href="%s">click here</a>.', 'woocommerce-services' );
				if ( empty( $settings->api_username ) ) {
					$api_creds_text = sprintf( $api_creds_template, add_query_arg( 'environment', 'live', $reset_link ) );
					$settings_meta['api_credentials']['description'] = $api_creds_text;
					unset( $settings_meta['api_username'], $settings_meta['api_password'], $settings_meta['api_signature'], $settings_meta['api_certificate'] );
				}
				if ( empty( $settings->sandbox_api_username ) ) {
					$api_creds_text = sprintf( $api_creds_template, add_query_arg( 'environment', 'sandbox', $reset_link ) );
					$settings_meta['sandbox_api_credentials']['description'] = $api_creds_text;
					unset( $settings_meta['sandbox_api_username'], $settings_meta['sandbox_api_password'], $settings_meta['sandbox_api_signature'], $settings_meta['sandbox_api_certificate'] );
				}

				$api_subject_title = __( 'Payment Email', 'woocommerce-services' );
				$settings_meta['api_subject']['title'] = $api_subject_title;
				$settings_meta['sandbox_api_subject']['title'] = $api_subject_title;

				$api_subject_description = __( 'Enter your email address at which to accept payments. You\'ll need to link your own account in order to perform anything other than "sale" transactions.', 'woocommerce-services' );
				$settings_meta['api_subject']['description'] = $api_subject_description;
				$settings_meta['sandbox_api_subject']['description'] = $api_subject_description;

				$api_subject_placeholder = __( 'Required', 'woocommerce-services' );
				$settings_meta['api_subject']['placeholder'] = $api_subject_placeholder;
				$settings_meta['sandbox_api_subject']['placeholder'] = $api_subject_placeholder;

			} else {
				$reset_link = add_query_arg(
					array( 'reroute_requests' => 'yes', 'nonce' => wp_create_nonce( 'reroute_requests' ) ),
					wc_gateway_ppec()->get_admin_setting_link()
				);
				$api_creds_template = __( 'To authenticate payments with WooCommerce Services, <a href="%s">click here</a>.', 'woocommerce-services' );
				if ( empty( $settings->api_username ) ) {
					$api_creds_text = sprintf( $api_creds_template, add_query_arg( 'environment', 'live', $reset_link ) );
					$settings_meta['api_credentials']['description'] .= '<br /><br />' . $api_creds_text;
				}
				if ( empty( $settings->sandbox_api_username ) ) {
					$api_creds_text = sprintf( $api_creds_template, add_query_arg( 'environment', 'sandbox', $reset_link ) );
					$settings_meta['sandbox_api_credentials']['description'] .= '<br /><br />' . $api_creds_text;
				}
			}

			return $settings_meta;
		}

		public function maybe_set_reroute_requests() {
			if (
				empty( $_GET['reroute_requests'] ) ||
				empty( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'reroute_requests' )
			) {
				return;
			}

			$settings = wc_gateway_ppec()->settings;
			$settings->reroute_requests = 'yes' === $_GET['reroute_requests'] ? 'yes' : 'no';
			if ( isset( $_GET['environment'] ) ) {
				$settings->environment = 'sandbox' === $_GET['environment'] ? 'sandbox' : 'live';
			}
			$settings->save();

			wp_safe_redirect( wc_gateway_ppec()->get_admin_setting_link() );
		}
	}
}
