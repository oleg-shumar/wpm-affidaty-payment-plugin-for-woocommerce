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
 * Virtual page where Affidaty payment page will be displayed
 */
add_filter( 'init', function ( $template ) {
	if ( isset( $_GET['affidaty_id'] ) && isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'display_payment_page' ) && get_post_type( (int) $_GET['affidaty_id'] ) == "shop_order" ) {
		$order_id  = (int) $_GET['affidaty_id'];
		$affidiaty = new WC_Gateway_Affidaty();
		$affidiaty->display_payment_page( $order_id );
		wp_die();
	}
} );

/**
 * Init Payment Gateway
 */
function wc_affidaty_gateway_init() {
	class WC_Gateway_Affidaty extends WC_Payment_Gateway {

		/**
		 * API Root Endpoint
		 */
		const AFFIDATY_API_ROOT_ENDPOINT = 'https://dev.exchange.affidaty.net';

		/**
		 * API Pay Endpoint
		 */
		const AFFIDATY_API_PAY_ENDPOINT = 'https://dev.exchange.affidaty.net/api/fastPay/pay/';

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
			add_action( 'woocommerce_api_' . 'wc_affidaty', array( $this, 'check_ipn_response' ) );

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

				'test_mode' => array(
					'title'   => __( 'Test Mode', 'affidaty-woo' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Affidaty Test Mode', 'affidaty-woo' ),
					'default' => 'yes'
				),

				'application_id' => array(
					'title'   => __( 'Application ID', 'affidaty-woo' ),
					'type'    => 'text',
					'label'   => __( 'Application ID', 'affidaty-woo' ),
					'default' => ''
				),

				'secret_token' => array(
					'title'   => __( 'Secret Token', 'affidaty-woo' ),
					'type'    => 'text',
					'label'   => __( 'Secret Token', 'affidaty-woo' ),
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
			$order_id = (int) $_GET['order_id'];

			if ( function_exists( 'phpversion' ) && version_compare( phpversion(), '5.6', '>=' ) ) {
				$post_data = file_get_contents( 'php://input' );
				$post_data = json_decode( $post_data, true );

				if ( isset( $post_data['status'] ) ) {
					if ( $order_id > 0 ) {
						$affidaty_webhook_triggered = (int) get_post_meta( $order_id, '_affidaty_webhook_triggered', true );
						if ( $affidaty_webhook_triggered == 1 ) {
							exit;
						}
					}

					$order      = new WC_Order( $order_id );
					$order_data = $order->get_data();

					$order->add_meta_data( '_affidaty_webhook_triggered', 1 );
					$order->add_meta_data( 'affidaty_transaction_id', sanitize_title( $post_data['_id'] ) );
					$order->save_meta_data();

					if ( $post_data['status'] == 'COMPLETE' ) {
						$order->update_status( 'processing', __( 'Payment successful. Transaction Id: ' . sanitize_title( $post_data['_id'] ), 'affidaty-woo' ) );
					}
				}
			}

			exit;
		}

		/**
		 * IPN Check will be here
		 */
		public function check_ipn_response() {
			$this->web_hook_handler();
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
		 * Make form and send it to API
		 *
		 * @param $order_id
		 */
		public function display_payment_page( $order_id ) {
			$order_id = (int) $order_id;

			// Grab the order
			$order = wc_get_order( $order_id );

			$order_totals = array();

			foreach ( $order->get_items() as $item_id => $item ) {
				$order_totals[] = array(
					'name'   => $item->get_name(),
					'amount' => $item->get_subtotal(),
				);
			}

			$order_totals[] = array(
				'name'   => 'Shipping',
				'amount' => $order->get_shipping_total(),
			);

			$order_totals[] = array(
				'name'   => 'Tax',
				'amount' => $order->get_total_tax(),
			);

			$params = array(
				'applicationid'   => $this->get_option( 'application_id' ),
				'email'           => $order->get_billing_email(),
				'amount'          => $order->get_total(),
				'currency'        => $order->get_currency(),
				'webhook'         => add_query_arg( array(
					'wc-api'   => 'wc_affidaty',
					'order_id' => $order_id,
				), get_site_url() ),
				'redirectSuccess' => $order->get_checkout_order_received_url(),
				'redirectCancel'  => add_query_arg( array(
					'cancelled' => '1',
				), wc_get_checkout_url() ),
				'hmac'            => $this->get_option( 'secret_token' ),
				'p'               => $order_totals,
			);

			?>
            <p><?php echo __( 'Loading...', 'affidaty-woo' ) ?></p>
            <form id="affidaty_payment_form" action="<?= esc_attr( $this::AFFIDATY_API_PAY_ENDPOINT ) ?>" method="POST">
                <input type="hidden" id="aff_applicationid" name="applicationid" value="<?= esc_attr( $params['applicationid']) ?>" />
                <input type="hidden" id="aff_email" name="email" value="<?= esc_attr( $params['email'] ) ?>">
                <input type="hidden" id="aff_amount" name="amount" value="<?= esc_attr( $params['amount'] ) ?>">
                <input type="hidden" id="aff_currency" name="currency" value="<?= esc_attr( $params['currency'] ) ?>">
                <input type="hidden" id="aff_webhook" name="webhook" value="<?= esc_url( $params['webhook'] ) ?>">
                <input type="hidden" id="aff_redirectSuccess" name="redirectSuccess" value="<?= esc_attr( $params['redirectSuccess'] ) ?>">
                <input type="hidden" id="aff_redirectCancel" name="redirectCancel" value="<?= esc_attr( $params['redirectCancel'] ) ?>">
                <input type="hidden" id="aff_hmac" name="hmac" value="<?= esc_attr( $params['hmac'] ) ?>">
				<?php foreach($order_totals as $key => $order_total){ ?>
                    <input type="hidden" name="p[<?= (int)$key ?>][name]" value="<?= esc_attr( $order_total['name'] )?>" />
                    <input type="hidden" name="p[<?= (int)$key ?>][amount]" value="<?= esc_attr( $order_total['amount'] )?>" />
				<?php } ?>
            </form>
            <script>
                window.onload = function () {
                    document.getElementById('affidaty_payment_form').submit();
                    return false;
                };
            </script>
			<?php
			exit;
		}

		/**
		 * Display description and alert if needed
		 */
		function payment_fields() {
			?>
            <div class="form-row form-row-wide">
                <p><?php echo $this->description; ?></p>
				<?php
				if ( isset( $_REQUEST['cancelled'] ) ) {
					?>
                    <script>
                        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error"><?php echo __( 'Payment canceled by customer', 'affidaty-woo' )?></div></div>';
                        jQuery(document).ready(function () {
                            jQuery('.woocommerce-notices-wrapper:first').html(message);
                        });
                    </script>
					<?php
				}
				?>
            </div>
			<?php
		}

		/**
		 * Process the payment and return the result
		 */
		public function process_payment( $order_id ) {
			return array(
				'result'   => 'success',
				'redirect' => html_entity_decode( wp_nonce_url( site_url( '/affidaty/?affidaty_id=' . $order_id ), 'display_payment_page' ) ),
			);

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