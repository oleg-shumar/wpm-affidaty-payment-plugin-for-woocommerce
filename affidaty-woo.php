<?php
/**
 * Plugin Name: Affidaty Woo Integration
 * Plugin URI: https://affidaty.io/woo-integration
 * Description: Affidaty payment gateway for Woo
 * Author: Affidaty
 * Author URI: http://affidaty.io/
 * Version: 0.1.0
 * Text Domain: affidaty-woo
 *
 * Copyright: (c) 2021 Affidaty
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Affidaty
 * @author    Affidaty
 * @category  Admin
 * @copyright Copyright (c) 2021, Affidaty
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * Affidaty payment gateway for Woo
 */

/**
 * Don't start until we have WordPress there
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Don't start until we have WooCommerce there
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Register Payment Gateway
 */
function wc_affidaty_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_Affidaty';

	return $gateways;
}

add_filter( 'woocommerce_payment_gateways', 'wc_affidaty_add_to_gateways' );

/**
 * Register Plugin Settings
 */
function wc_affidaty_gateway_plugin_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=affidaty_gateway' ) . '">' . __( 'Settings', 'affidaty-woo' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_affidaty_gateway_plugin_links' );

/**
 * Init Payment Gateway Hook
 */
add_action( 'plugins_loaded', 'wc_affidaty_gateway_init', 9999 );

/**
 * Init Payment Gateway
 */
function wc_affidaty_gateway_init() {
	class WC_Gateway_Affidaty extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'affidaty_gateway';
			$this->icon               = apply_filters( 'woocommerce_affidaty_icon', '' );
			$this->has_fields         = false;
			$this->method_title       = __( 'Affidaty', 'affidaty-woo' );
			$this->method_description = __( 'Test mode, testing skeleton', 'affidaty-woo' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

			// API Logic
			add_action( 'woocommerce_api_' . strtolower( "WC_Gateway_Affidaty" ), array( $this, 'check_ipn_response' ) );

			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}

		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
			// Apply filters in case it's need to be extended
			$this->form_fields = apply_filters( 'wc_affidaty_form_fields', array(

				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'affidaty-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Affidaty Payment', 'affidaty-woo' ),
					'default' => 'yes'
				),

				'application_id' => array(
					'title'   => __( 'ApplicationID', 'affidaty-woo' ),
					'type'    => 'text',
					'label'   => __( 'Enable Affidaty Payment', 'affidaty-woo' ),
					'default' => ''
				),

				'title' => array(
					'title'       => __( 'Title', 'affidaty-woo' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'affidaty-woo' ),
					'default'     => __( 'Affidaty Payment', 'affidaty-woo' ),
					'desc_tip'    => true,
				),

				'description' => array(
					'title'       => __( 'Description', 'affidaty-woo' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'affidaty-woo' ),
					'default'     => __( 'Test mode, testing skeleton', 'affidaty-woo' ),
					'desc_tip'    => true,
				),

				'instructions' => array(
					'title'       => __( 'Instructions', 'affidaty-woo' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'affidaty-woo' ),
					'default'     => '',
					'desc_tip'    => true,
				),
			) );
		}

		/**
		 * Webhook logic will be here
		 */
		public function web_hook_handler() {
		}

		/**
		 * IPN Check will be here
		 */
		public function check_ipn_response() {
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo esc_html( $this->instructions );
			}
		}

		/**
		 * Add content to the WC emails.
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
				echo esc_html( $this->instructions ) . PHP_EOL;
			}
		}

		/**
		 * Process the payment and return the result
		 */
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );

			// Mark as on-hold (we're awaiting the payment)
			$order->update_status( 'on-hold', __( 'Awaiting response from Affidaty server', 'affidaty-woo' ) );

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order )
			);
		}

	}
}