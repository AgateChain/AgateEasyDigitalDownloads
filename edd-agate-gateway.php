<?php
/*
Plugin Name: 		Agate Easy Digital Downloads (EDD) - Payment Gateway
Plugin URI: 		https://github.com/
Description:		Provides a <a href="https://agate.services">agate.services</a> Payment Gateway for <a href="https://wordpress.org/plugins/easy-digital-downloads/">Easy Digital Downloads 2.4.2+</a>. Direct Integration on your website, no external payment pages opens (as other payment gateways offer). Accept payments online. You will see the payment statistics in one common table on your website. No Chargebacks, Global, Secure. All in automatic mode.
Version: 			1.0.0
Author: 			Agate.io
Author URI: 		https://agate.services
License: 			GPLv2
License URI: 		http://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: 	https://github.com/
*/

// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;


if( !class_exists( 'EDD_Agate' ) ) {

    class EDD_Agate {

        private static $instance;

        /**
         * Get active instance
         *
         * @since       1.0.0
         * @access      public
         * @static
         * @return      object self::$instance
         */
        public static function get_instance() {
            if( !self::$instance )
                self::$instance = new EDD_Agate();

            return self::$instance;
        }


        /**
         * Class constructor
         *
         * @since       1.0.0
         * @access      public
         * @return      void
         */
        public function __construct() {
            // Plugin dir
            define( 'EDD_AGATE_DIR', plugin_dir_path( __FILE__ ) );

            // Plugin URL
            define( 'EDD_AGATE_URL', plugin_dir_url( __FILE__ ) );

            $this->init();
        }


        /**
         * Run action and filter hooks
         *
         * @since       1.0.0
         * @access      private
         * @return      void
         */
        private function init() {
            // Make sure EDD is active
            if( !class_exists( 'Easy_Digital_Downloads' ) ) return;

            global $edd_options;

            // Internationalization
            add_action( 'init', array( $this, 'textdomain' ) );

            // Register settings
            add_filter( 'edd_settings_gateways', array( $this, 'settings' ), 1 );

            // Add the gateway
            add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );

            // Register icon
            add_filter('edd_accepted_payment_icons', array($this, 'pw_edd_payment_icon' ));

            // Remove CC form
            add_action( 'edd_agate_cc_form', '__return_false' );

            // Process payment
            add_action( 'edd_gateway_agate', array( $this, 'process_payment' ) );
            add_action( 'init', array( $this, 'edd_listen_for_agate_ipn' ) );
            add_action( 'edd_verify_agate_ipn', array( $this, 'edd_process_agate_ipn' ) );

            // Display errors
            add_action( 'edd_after_cc_fields', array( $this, 'errors_div' ), 999 );
        }

        /**
         * Register the payment icon
         */
        public function pw_edd_payment_icon($icons) {
            $icons['http://gateway.agate.services/image/icon.png'] = 'Agate';
            return $icons;
        }



        /**
         * Internationalization
         *
         * @since       1.0.0
         * @access      public
         * @static
         * @return      void
         */
        public static function textdomain() {
            // Set filter for language directory
            $lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
            $lang_dir = apply_filters( 'edd_agate_lang_dir', $lang_dir );

            // Load translations
            load_plugin_textdomain( 'edd-agate', false, $lang_dir );
        }


        /**
         * Add settings
         *
         * @since       1.0.0
         * @access      public
         * @param       array $settings The existing plugin settings
         * @return      array
         */
        public function settings( $settings ) {
            $agate_settings = array(
                array(
                    'id'    => 'edd_agate_settings',
                    'name'  => '<strong>' . __( 'Agate Settings', 'edd-agate' ) . '</strong>',
                    'desc'  => __( 'Configure your Agate settings', 'edd-agate' ),
                    'type'  => 'header'
                ),
                array(
                    'id'    => 'edd_agate_api_key',
                    'name'  => __( 'Signature', 'edd-agate' ),
                    'desc'  => __( 'Enter your Agate api_key', 'edd-agate' ),
                    'type'  => 'text'
                )
            );

            return array_merge( $settings, $agate_settings );
        }


        /**
         * Register our new gateway
         *
         * @since       1.0.0
         * @access      public
         * @param       array $gateways The current gateway list
         * @return      array $gateways The updated gateway list
         */
        public function register_gateway( $gateways ) {
            $gateways['agate'] = array(
                'admin_label'       => 'Agate Payments',
                'checkout_label'    => __( 'Agate Payments - Pay with any standard currency', 'edd-agate-gateway' )
            );

            return $gateways;
        }


        /**
         * Process payment submission
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @param       array $purchase_data The data for a specific purchase
         * @return      void
         */
        public function process_payment( $purchase_data ) {
            global $edd_options;

            // Collect payment data
            $payment_data = array(
                'price'         => $purchase_data['price'],
                'date'          => $purchase_data['date'],
                'user_email'    => $purchase_data['user_email'],
                'purchase_key'  => $purchase_data['purchase_key'],
                'currency'      => edd_get_currency(),
                'downloads'     => $purchase_data['downloads'],
                'user_info'     => $purchase_data['user_info'],
                'cart_details'  => $purchase_data['cart_details'],
                'gateway'       => 'agate',
                'status'        => 'pending'
            );

            // Record the pending payment
            $payment = edd_insert_payment( $payment_data );

            // Were there any errors?
            if( !$payment ) {
                // Record the error
                edd_record_gateway_error( __( 'Payment Error', 'edd-agate' ), sprintf( __( 'Payment creation failed before sending buyer to Agate. Payment data: %s', 'edd-agate' ), json_encode( $payment_data ) ), $payment );
                edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
            } else {
                $redirect_url   = add_query_arg( 'payment-confirmation', 'agate', get_permalink( $edd_options['success_page'] ) );
                $order_total    = round( $purchase_data['price'] - $purchase_data['tax'], 2 );
                $baseUri        = "http://gateway.agate.services/" ;
                $convertUrl     = "http://gateway.agate.services/convert/";
                $api_key        = $edd_options['edd_agate_api_key'];
                $currencySymbol = edd_get_currency();

                $amount_iUSD = convertCurToIUSD($convertUrl, $order_total, $api_key, $currencySymbol);

            }

            // Redirect to Agate
            redirectPayment($baseUri, $amount_iUSD, $order_total, $currencySymbol, $api_key, $redirect_url);

            exit;
        }




        /**
         * Listens for a Agate IPN requests and then sends to the processing function
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @return      void
         */
        public function edd_listen_for_agate_ipn() {
            global $edd_options;

            if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'AGATEIPN' ) {
                do_action( 'edd_verify_agate_ipn' );
            }
        }
      }


     function convertCurToIUSD($url, $amount, $api_key, $currencySymbol) {
        error_log("Entered into Convert CAmount");
        error_log($url.'?api_key='.$api_key.'&currency='.$currencySymbol.'&amount='. $amount);
        $ch = curl_init($url.'?api_key='.$api_key.'&currency='.$currencySymbol.'&amount='. $amount);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json')
      );

      $result = curl_exec($ch);
      $data = json_decode( $result , true);
      error_log('Response =>'. var_export($data, TRUE));
      // Return the equivalent bitcoin value acquired from Agate server.
      return (float) $data["result"];

      }


      function redirectPayment($baseUri, $amount_iUSD, $amount, $currencySymbol, $api_key, $redirect_url) {
        error_log("Entered into auto submit-form");
        error_log("Url ".$baseUri . "?api_key=" . $api_key);
        // Using Auto-submit form to redirect user
        echo "<form id='form' method='post' action='". $baseUri . "?api_key=" . $api_key."'>".
                "<input type='hidden' autocomplete='off' name='amount' value='".$amount."'/>".
                "<input type='hidden' autocomplete='off' name='amount_iUSD' value='".$amount_iUSD."'/>".
                "<input type='hidden' autocomplete='off' name='callBackUrl' value='".$redirect_url."'/>".
                "<input type='hidden' autocomplete='off' name='api_key' value='".$api_key."'/>".
                "<input type='hidden' autocomplete='off' name='cur' value='".$currencySymbol."'/>".
               "</form>".
               "<script type='text/javascript'>".
                    "document.getElementById('form').submit();".
               "</script>";
      }


}


function edd_agate_gateway_load() {
    $edd_agate = new EDD_Agate();
}
add_action( 'plugins_loaded', 'edd_agate_gateway_load' );
