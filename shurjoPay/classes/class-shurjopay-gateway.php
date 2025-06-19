<?php
    /**
     * ShurjoPay Payment Gateway
     */
    defined('ABSPATH') or exit('Direct access not allowed');
    if (! class_exists("WC_Shurjopay")) {

        class WC_Shurjopay extends WC_Payment_Gateway
        {
            public static $log_enabled    = false;
            public static $log            = false;
            protected $templateFields     = [];
            private $domainName           = "";
            private $test_mode            = false;
            private $merchant_id          = "";
            private $api_login            = "";
            private $trans_key            = "";
            private $api_ip               = "";
            private $api_return_url       = "";
            private $api_unique_id        = "";
            private $after_payment_status = "";
            private $gw_api_url           = "";
            private $gw_bank_api_url      = "";
            private $decrypt_url          = "";
            private $msg                  = [];
            private $order_status_messege = [
                "wc-processing" => "Awaiting admin confirmation.",
                "wc-on-hold"    => "Awaiting admin confirmation.",
                "wc-cancelled"  => "Order Cancelled",
                "wc-completed"  => "Successful",
                "wc-pending"    => "Awaiting admin confirmation.",
                "wc-failed"     => "Order Failed",
                "wc-refunded"   => "Payment Refunded.",
            ];
            private $sanbox_url         = 'https://sandbox.shurjopayment.com/';
            private $live_url           = 'https://engine.shurjopayment.com/';
            private $transaction_prefix = "";
            private $currency           = "";
            private $verification_url   = '';
            private $return_url         = '';

            public function __construct()
            {
                $this->id                 = 'wc_shurjopay';
                $this->icon               = plugins_url('shurjoPay/template/images/logo.png', SHURJOPAY_PATH);
                $this->method_title       = __('ShurjoPay', 'shurjopay');
                $this->method_description = __('ShurjoPay is most popular payment gateway for online shopping in Bangladesh.', 'shurjopay');
                $this->has_fields         = false;

                $url              = get_site_url(null, '/wc-api/WC_Shurjopay', 'http');
                $this->return_url = $url;

                $this->init_form_fields();
                $this->init_settings();

                // enable/ disable
                // title
                $this->title = $this->get_option('title');
                // Description
                $this->description = $this->get_option('description');
                // Api username
                $this->api_email = $this->get_option('api_username');
                // API password
                $this->api_password = $this->get_option('api_password');
                // Transaction prefix
                $this->transaction_prefix = $this->get_option('transaction_prefix');
                // Success url
                $this->success_url = '';
                // cancel url
                $this->cancel_url = '';
                // payment mode
                $this->test_mode = 'yes' === $this->get_option('environment', 'no');
                // after paymetn status
                $this->after_payment_status = $this->get_option('after_payment_status');
                // currency
                $this->currency = $this->get_option('currency');

                if ($this->test_mode) {
                    self::$log_enabled = true;
                    $this->description .= ' ' . sprintf(__('TEST MODE ENABLED. You can use test credentials. See the <a href="%s">Testing Guide</a> for more details.', 'shurjopay'), 'https://shurjopay.com');

                    $this->description = trim($this->description);
                    $this->domainName  = $this->sanbox_url;

                } else {
                    $this->domainName = $this->live_url;
                }

                $this->token_url        = $this->domainName . "api/get_token";
                $this->payment_url      = $this->domainName . "api/secret-pay";
                $this->verification_url = $this->domainName . "api/verification/";

                $this->msg['message'] = "";
                $this->msg['class']   = "";

                if ($this->is_valid_for_use()) {
                    //IPN actions
                    $this->notify_url = str_replace('https:', 'http:', home_url('/wc-api/WC_Shurjopay'));
                    add_action('woocommerce_api_wc_shurjopay', [$this, 'check_shurjopay_response']);
                    add_action('woocommerce_ipn_wc_shurjopay', [$this, 'shurjopay_ipn_response']);
                    add_action('valid-shurjopay-request', [$this, 'successful_request']);
                } else {
                    $this->enabled = 'no';
                }

                //save admin settings
                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
                } else {
                    add_action('woocommerce_update_options_payment_gateways', [ &$this, 'process_admin_options']);
                }
                flush_rewrite_rules();
                add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
                add_action('rest_api_init', [$this, 'register_rest_route']);
            }
            public function register_rest_route()
            {error_log('register_rest_route function is being called');
                register_rest_route('shurjopay/v1', '/check-response/', [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'shurjopay_ipn'],
                    'permission_callback' => '__return_true', // Allowing all users to access for now
                ]);}

            public function shurjopay_ipn(WP_REST_Request $request)
            {
                global $woocommerce;

                // Default response
                $response = [
                    'status'  => 'error',
                    'message' => 'Transaction has been declined.',
                ];
                $order_id = $request->get_param('order_id');
                //return ($order_id);
                // Check if order_id is provided
                if (empty($order_id)) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Order ID is missing from the request.',
                    ], 400); // Bad Request
                }
                // Check if order_id is provided in the request
                if (! isset($order_id) || empty($order_id)) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Payment data not found.',
                    ], 400); // Bad Request
                }

                $this->logger("Response Order ID:" . $order_id);

                // Decrypt the payment data and validate
                $decryptValues = json_decode($this->decrypt_and_validate($order_id));
                if ($decryptValues == false) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Payment data not found.',
                    ], 400); // Bad Request
                }

                // Extract decrypted data
                $data_dycrpt = array_shift(json_decode($decryptValues));

                // Retrieve the order object
                $order = $this->get_order_from_response($data_dycrpt);
                //return($data_dycrpt);
                // Check if the order is valid
                if ($order == false) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Order not found.',
                    ], 404); // Not Found
                }

                // Ensure the amount matches
                if ((string) $data_dycrpt->amount != $order->get_total()) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Unauthorized data access.',
                    ], 403); // Forbidden
                }

                // Update the transaction in the database
                $this->update_transaction($data_dycrpt->customer_order_id, $data_dycrpt->sp_message, $data_dycrpt->bank_trx_id, $data_dycrpt->method);

                // Prepare the order status message
                $order_status_sp_msg = "Payment Status = {$data_dycrpt->sp_message}<br/>
                            Bank trx id = {$data_dycrpt->bank_trx_id}<br/>
                            Invoice id = {$data_dycrpt->invoice_no}<br/>
                            Your order id = {$data_dycrpt->customer_order_id}<br/>
                            Payment Date = {$data_dycrpt->sp_code}<br/>
                            Card Number = {$data_dycrpt->card_number}<br/>
                            Card Type = {$data_dycrpt->method}<br/>
                            Payment Gateway = shurjoPay";

                try {
                    switch (strtolower($data_dycrpt->sp_code)) {

                        case "1000": // Successful Payment
                            $order->add_order_note($order_status_sp_msg);
                            $order->update_status($this->after_payment_status, $this->order_status_messege[$this->after_payment_status]);
                            //$woocommerce->cart->empty_cart(); // Empty the cart
                            return new WP_REST_Response([
                                'status'           => 'success',
                                'message'          => 'Payment successful.',
                                'order_id'         => $data_dycrpt->customer_order_id,
                                'shurjopay_status' => $data_dycrpt->sp_message,
                            ], 200);     // OK
                        case "1002": // Canceled by Client
                        case "1001": // Failed Transaction
                            $order->add_order_note($order_status_sp_msg);
                            $order->update_status('failed');
                            return new WP_REST_Response([
                                'status'           => 'error',
                                'message'          => 'Transaction canceled or failed.',
                                'order_id'         => $data_dycrpt->customer_order_id,
                                'shurjopay_status' => $data_dycrpt->sp_message,
                            ], 400); // Bad Request
                        default:
                            $order->add_order_note("Bank transaction not successful.");
                            return new WP_REST_Response([
                                'status'           => 'error',
                                'message'          => 'Transaction failed with status ' . $data_dycrpt->sp_message,
                                'order_id'         => $data_dycrpt->customer_order_id,
                                'shurjopay_status' => $data_dycrpt->sp_message,
                            ], 500); // Internal Server Error
                    }
                } catch (Exception $e) {
                    return new WP_REST_Response([
                        'status'  => 'error',
                        'message' => 'Exception occurred during transaction: ' . $e->getMessage(),
                    ], 500); // Internal Server Error
                }
            }

            /**
             * Admin Form Fields
             */
            public function init_form_fields()
            {

                $this->form_fields = [
                    'enabled'              => [
                        'title'   => __('Enable / Disable', 'shurjopay'),
                        'label'   => __('Enable this payment gateway', 'shurjopay'),
                        'type'    => 'checkbox',
                        'default' => 'no',
                    ],
                    'title'                => [
                        'title'    => __('Title', 'shurjopay'),
                        'type'     => 'text',
                        'desc_tip' => __('Payment title the customer will see during the checkout process.', 'shurjopay'),
                        'default'  => __('ShurjoPay', 'shurjopay'),
                    ],
                    'description'          => [
                        'title'    => __('Description', 'shurjopay'),
                        'type'     => 'textarea',
                        'desc_tip' => __('Payment description the customer will see during the checkout process.', 'shurjopay'),
                        'default'  => __('Pay securely using ShurjoPay', 'shurjopay'),
                        'css'      => 'max-width:350px;',
                    ],
                    'api_username'         => [
                        'title'    => __('API Username', 'shurjopay'),
                        'type'     => 'text',
                        'desc_tip' => __('This is the API Login provided by ShurjoPay when you signed up for an account.', 'shurjopay'),
                    ],
                    'api_password'         => [
                        'title'    => __('API Password', 'shurjopay'),
                        'type'     => 'password',
                        'desc_tip' => __('This is the Transaction Key provided by ShurjoPay when you signed up for an account.', 'shurjopay'),
                    ],
                    'transaction_prefix'   => [
                        'title'    => __('Transaction Prefix', 'shurjopay'),
                        'type'     => 'text',
                        'desc_tip' => __('This is the Transaction Key provided by ShurjoPay when you signed up for an account.', 'shurjopay'),
                    ],
                    'currency'             => [
                        'title'       => __('Payment Currency', 'shurjopay'),
                        'type'        => 'select',
                        'description' => __('Payment Currency', 'shurjopay'),
                        'options'     => $this->get_store_currencies(),
                    ],
                    'after_payment_status' => [
                        'title'       => __('Payment Status', 'shurjopay'),
                        'type'        => 'select',
                        'description' => __('After Successful Payment Status', 'shurjopay'),
                        'options'     => [
                            "wc-processing" => "Processing",
                            "wc-on-hold"    => "On-Hold",
                            "wc-cancelled"  => "Cancelled",
                            "wc-completed"  => "Completed",
                            "wc-pending"    => "Pending",
                            "wc-failed"     => "Failed",
                            "wc-refunded"   => "Refunded",
                        ],
                        'default'     => 'wc-completed',
                    ],
                    'ipn'                  => [
                        'title'             => __('IPN', 'shurjopay'),
                        'type'              => 'textarea',
                        'description'       => __('Instant Payment Notification (IPN) URL for Updating Your Payment Remotely. To set up this URL as your IPN:
                            <ol>
                                <li>Login to your ShurjoPay merchant panel.</li>
                                <li>Go to "Profile" > "IPN & Profile Photo" > "IPN Setup".</li>
                            </ol>
                            Once set up, this URL will receive updates about your payment status.', 'shurjopay'),
                        'default'           => home_url('/wp-json/shurjopay/v1/check-response'),
                        'desc_tip'          => __('This URL will be used for IPN to get responses from ShurjoPay after a payment is completed.', 'shurjopay'),
                        'custom_attributes' => [
                            'readonly' => 'readonly', // Makes the field readonly
                        ],
                        'css'      => 'max-width:350px;',                    // Adjusting height and width for better appearance
                       
                    ],

                    'environment'          => [
                        'title'       => __('Test Mode', 'shurjopay'),
                        'label'       => __('Enable Test Mode', 'shurjopay'),
                        'type'        => 'checkbox',
                        'description' => __('Place the payment gateway in test mode.', 'shurjopay'),
                        'default'     => 'no',
                    ],
                ];
            }

            /**
             * Only allowed for only BDT currency
             */
            public function is_valid_for_use()
            {
                return in_array(get_woocommerce_currency(), ['BDT', 'USD'], true);
            }

            public function get_store_currencies()
            {
                return get_woocommerce_currencies();
            }

            /**
             * Logger for ShurjoPay
             * @param $message
             * @param string $level
             */
            public static function log($message, $level = 'info')
            {
                if (self::$log_enabled) {
                    if (empty(self::$log)) {
                        self::$log = wc_get_logger();
                    }
                    self::$log->log($level, $message, ['source' => 'shurjopay']);
                }
            }

            /**
             * Processes and saves options.
             * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
             *
             * @return bool was anything saved?
             */
            public function process_admin_options()
            {
                $saved = parent::process_admin_options();
                return $saved;
            }

            /**
             * Admin Panel Options.
             * - Options for bits like 'title' and availability on a country-by-country basis
             **/
            public function admin_options()
            {
                if ($this->is_valid_for_use()) {
                    parent::admin_options();
                } else {
                ?>
                <div class="shurjopay_error">
                    <p>
                        <strong><?php esc_html_e('Gateway disabled', 'shurjopay'); ?></strong>:<?php esc_html_e('ShurjoPay does not support your store currency.', 'shurjopay'); ?>
                    </p>
                </div>
                <?php
                    }
                            }

                            /**
                             *  There are no payment fields for ShurjoPay, but we want to show the description if set.
                             **/
                            public function payment_fields()
                            {
                                if ($this->description) {
                                    echo wpautop(wptexturize($this->description));
                                }

                            }

                            /**
                             * Receipt Page.
                             * @param $order
                             */
                            public function receipt_page($order)
                            {

                                echo '<p>' . __('Thank you for your order, please click the button below to pay with shurjoPay.', 'shurjopay') . '</p>';
                                $this->generate_shurjopay_form($order);
                            }

                            /**
                             * Process the payment and return the result.
                             * @param $order_id
                             * @return array
                             */
                            public function process_payment($order_id)
                            {
                                $order = new WC_Order($order_id);
                                return ['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)];
                            }

                            /**
                             * Check for valid ShurjoPay server callback
                             **/
                            public function check_shurjopay_response()
                            {
                                global $woocommerce;
                                $this->msg['class']   = 'error';
                                $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
                                //$this->logger("shurjoPay Response :".json_encode($_REQUEST));
                                if (! isset($_REQUEST['order_id']) || empty($_REQUEST['order_id'])) {
                                    $this->msg['class']   = 'error';
                                    $this->msg['message'] = "Payment data not found.";
                                    return $this->redirect_with_msg(false, false);
                                }
                                $order_id = $_REQUEST["order_id"];
                                $this->logger("Response Order ID:" . $order_id);

                                $decryptValues = json_decode($this->decrypt_and_validate($order_id));
                                if ($decryptValues == false) {
                                    $this->msg['class']   = 'error';
                                    $this->msg['message'] = "Payment data not found.";
                                    return $this->redirect_with_msg(false, false);
                                }

                                $data_dycrpt = array_shift(json_decode($decryptValues));

                                $order = $this->get_order_from_response($data_dycrpt);

                                if ($order == false) {
                                    $this->msg['class']   = 'error';
                                    $this->msg['message'] = "Order not found.";
                                    return $this->redirect_with_msg($order, false);
                                }

                                if ((string) $data_dycrpt->amount != $order->get_total()) {
                                    $this->msg['class']   = 'error';
                                    $this->msg['message'] = "Unauthorized data access.";
                                    return $this->redirect_with_msg($order, $data_dycrpt->bank_status);
                                }
                                // update in database
                                $this->update_transaction($data_dycrpt->customer_order_id, $data_dycrpt->sp_massage, $data_dycrpt->bank_trx_id, $data_dycrpt->method);

                                $order_status_sp_msg = "Payment Status = {$data_dycrpt->sp_massage}<br/>
                                    Bank trx id = {$data_dycrpt->bank_trx_id}<br/>
                                    Invoice id = {$data_dycrpt->invoice_no}<br/>
                                    Your order id = {$data_dycrpt->customer_order_id}<br/>
                                    Payment Date = {$data_dycrpt->sp_code}<br/>
                                    Card Number = {$data_dycrpt->card_number}<br/>
                                    Card Type = {$data_dycrpt->method}<br/>
                                    Payment Gateway = shurjoPay";

                                try {

                                    switch (strtolower($data_dycrpt->sp_code)) {
                                        case "1000":
                                            $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
                                            $this->msg['class']   = 'success';
                                            $woocommerce->cart->empty_cart();

                                            $order->add_order_note($order_status_sp_msg);
                                            // $order->add_order_note("ShurjoPay payment successful.<br/> Bank Ref Number: " . $data_dycrpt->bank_trx_id);
                                            $order->update_status($this->after_payment_status, $this->order_status_messege[$this->after_payment_status]);
                                            do_action('woocommerce_reduce_order_stock', $order);
                                            break;
                                        case "1002":
                                            $this->msg['message'] = "Your Transaction <b>Canceled</b>.<br/>Invoice ID: " . $data_dycrpt->invoice_no . ".<br/>Customer Order ID: " . $data_dycrpt->customer_order_id . "<br/> Payment Method: " . $data_dycrpt->method . ".<br/>We will keep you posted regarding the status of your order through e-mail";
                                            $this->msg['class']   = 'error';
                                            $order->add_order_note($order_status_sp_msg);
                                            // $order->add_order_note("Transaction Canceled by client.");
                                            $order->update_status('cancelled');
                                            break;
                                        case "1001":
                                            $this->msg['class']   = 'error';
                                            $this->msg['message'] = "Your Transaction <b>Canceled</b>.<br/>Invoice ID: " . $data_dycrpt->invoice_no . ".<br/>Customer Order ID: " . $data_dycrpt->customer_order_id . "<br/> Payment Method: " . $data_dycrpt->method . ".<br/>We will keep you posted regarding the status of your order through e-mail";
                                            $order->add_order_note($order_status_sp_msg);
                                            // $order->add_order_note("Transaction Failed.");
                                            $order->update_status('failed');
                                            break;
                                        default:
                                            $this->msg['class']   = 'error';
                                            $this->msg['message'] = "Thank you for shopping with us.<br/>Invoice ID: '" . $data_dycrpt->invoice_no . "'.<br/>!!However, the transaction has been " . $data_dycrpt->sp_message . ".";
                                            $order->add_order_note("Bank transaction not successful.");
                                            break;
                                    }

                                } catch (Exception $e) {
                                    $order->add_order_note("Exception occurred during transaction");
                                    $this->msg['class']   = 'error';
                                    $this->msg['message'] = "Thank you for shopping with us.<br/>Bank Ref Number: '" . $data_dycrpt->bank_trx_id . "'.<br/>However, the transaction has been failed/declined.";
                                }
                                return $this->redirect_with_msg($order, $data_dycrpt->sp_massage);
                            }

                            /**
                             * Get response from shurjopay IPN.
                             */
                            public function shurjopay_ipn_response()
                            {
                                echo "IPN FUNCTION!";
                            }
                            /**
                             * From payment status it redirects to relivent page to customer.
                             * @param $order,$bankTxStatus
                             * @return URL
                             */

                            private function redirect_with_msg($order, $bankTxStatus)
                            {

                                global $woocommerce;
                                // $redirect = home_url('checkout/order-received/');
                                $woocommerce->session->set('wc_notices', []);
                                if (function_exists('wc_add_notice')) {
                                    wc_add_notice($this->msg['message'], $this->msg['class']);
                                } else {
                                    if ($this->msg['class'] == 'success') {
                                        $woocommerce->add_message($this->msg['message']);
                                    } else {
                                        $woocommerce->add_error($this->msg['message']);
                                    }
                                    $woocommerce->set_messages();
                                }

                                if ($order) {

                                    if ((strtolower($order->status) == 'completed') || (strtolower($order->status) == 'processing') || (strtolower($bankTxStatus) == 'successful')) {
                                        $redirect = $order->get_checkout_order_received_url();

                                    } elseif ($order->status == 'cancelled') {
                                        $redirect = wc_get_checkout_url();
                                    } elseif ($order->status == 'fail' or $order->status == 'pending') {
                                        $redirect = wc_get_checkout_url();
                                    } else {
                                        $redirect = wc_get_checkout_url();
                                    }
                                } else {
                                    $redirect = wc_get_checkout_url();
                                }

                                wp_redirect($redirect);
                            }

                            /**
                             * @param string $data
                             * @return bool|WC_Order
                             */
                            private function get_order_from_response($data = "")
                            {

                                // var_dump($data);exit;
                                if (empty($data)) {
                                    return false;
                                }

                                if (! isset($data->customer_order_id)) {
                                    return false;
                                }

                                // $order_id = explode('|', $data->customer_order_id);
                                // $order_id = (int)str_replace($this->transaction_prefix, '', $order_id[0]);
                                // $order = wc_get_order($order_id);
                                $order = wc_get_order($data->customer_order_id);
                                if (empty($order)) {
                                    return false;
                                }

                                return $order;
                            }

                            /**
                             * Generate ShurjoPay button link
                             * @param $order
                             * @return null
                             */
                            private function generate_shurjopay_form($order)
                            {
                                if (empty($order)) {
                                    return null;
                                }

                                if (! is_object($order)) {
                                    $order = new WC_Order($order);
                                }

                                $order_data = $order->get_data(); // The Order data
                                $token      = json_decode($this->getToken(), true);
                                                              // Generate order id
                                $order_id = $order->get_id(); //$this->transaction_prefix.$order->get_id()."|".uniqid();
                                if (! isset($token['token']) || empty($token['token'])) {
                                    die("Error occured! Please check logs");
                                }

                                $first_name    = ! empty($order_data['billing']['first_name']) ? $order_data['billing']['first_name'] : 'N/A';
                                $last_name     = ! empty($order_data['billing']['last_name']) ? $order_data['billing']['last_name'] : 'N/A';
                                $createpaybody = http_build_query(
                                    [
                                        // store information
                                        'token'             => $token['token'],
                                        'store_id'          => $token['store_id'],
                                        'prefix'            => $this->transaction_prefix,
                                        'currency'          => $this->currency,
                                        'return_url'        => $this->return_url,
                                        'cancel_url'        => $this->return_url,
                                        'amount'            => $order->get_total(),
                                        // Order information
                                        'order_id'          => $order_id,
                                        'discsount_amount'  => 0,
                                        //'disc_percent' => 5,
                                        // Customer information
                                        'client_ip'         => $this->get_option('api_ip', $_SERVER['REMOTE_ADDR']),
                                        'customer_name'     => $first_name . " " . $last_name,
                                        'customer_phone'    => ! empty($order_data['billing']['phone']) ? $order_data['billing']['phone'] : 'N/A',
                                        'customer_email'    => ! empty($order_data['billing']['email']) ? $order_data['billing']['email'] : 'N/A',
                                        'customer_address'  => ! empty($order_data['billing']['address_1']) ? $order_data['billing']['address_1'] : 'N/A',
                                        'customer_city'     => ! empty($order_data['billing']['city']) ? $order_data['billing']['city'] : 'N/A',
                                        'customer_state'    => ! empty($order_data['billing']['state']) ? $order_data['billing']['state'] : 'N/A',
                                        'customer_postcode' => ! empty($order_data['billing']['postcode']) ? $order_data['billing']['postcode'] : 'N/A',
                                        'customer_country'  => ! empty($order_data['billing']['country']) ? $order_data['billing']['country'] : 'N/A',

                                    ]
                                );

                                $header = [
                                    'Content-Type:application/x-www-form-urlencoded',
                                    'Authorization: Bearer ' . $token['token'],
                                ];

                                // $this->logger("Header:".$header."Request_body".json_encode($createpaybody)."<br>");
                                $this->save_transaction(
                                    $order_id, $this->currency,
                                    $order->get_total(),
                                    $this->return_url
                                );

                                $ch          = curl_init();
                                $payment_url = $token['execute_url'];
                                curl_setopt($ch, CURLOPT_URL, $payment_url);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $createpaybody);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                                $response = curl_exec($ch);
                                $urlData  = json_decode($response);

                                //$this->logger("Create Payment:".json_encode($urlData)."<br>");
                                curl_close($ch);
                                if (! isset($urlData->checkout_url) || empty($urlData->checkout_url)) {
                                    die("Error occured! Please check logs!");
                                }
                                header('Location: ' . $urlData->checkout_url);
                            }

                            /**
                             *   Get token from shurjoPay
                             *   @param $username
                             *   @param $password
                             **/

                            private function getToken()
                            {
                                $token_url = $this->token_url;

                                $postFields = [
                                    'username' => $this->api_email,
                                    'password' => $this->api_password,
                                ];
                                // logger
                                //$this->logger($token_url.":".json_encode($postFields)."<br>");
                                if (empty($token_url) || empty($postFields)) {
                                    return null;
                                }

                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $token_url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                                $response = curl_exec($ch);
                                $this->logger(json_encode($response));
                                curl_close($ch);
                                return $response;

                            }

                            /**
                             * @param string $data
                             * @return bool|SimpleXMLElement
                             */
                            private function decrypt_and_validate($order_id)
                            {
                                // echo $order_id;exit;

                                $token  = json_decode($this->getToken(), true);
                                $header = [
                                    'Content-Type:application/json',
                                    'Authorization: Bearer ' . $token['token'],
                                ];
                                $postFields = json_encode(
                                    [
                                        'order_id' => $order_id,
                                    ]
                                );
                                $verification_url = $this->domainName . 'api/verification';
                                //$this->logger(json_encode($postFields)."\n");
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $verification_url);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/0 (Windows; U; Windows NT 0; zh-CN; rv:3)");
                                $response = curl_exec($ch);
                                if (curl_exec($ch) === false) {
                                    echo 'Curl error: ' . curl_error($ch);
                                }
                                curl_close($ch);
                                //$this->logger("Verification_response:".json_encode($response)."<br>");
                                return json_encode($response);
                            }

                            /**
                             * Decrypt response
                             * @param string $encryptedText
                             * @return bool|null|string
                             */

                            private function decrypt($encryptedText = "")
                            {
                                if (empty($encryptedText)) {
                                    return null;
                                }

                                $url = $this->decrypt_url . '?data=' . $encryptedText;
                                $ch  = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                                $response_decrypted = curl_exec($ch);
                                curl_close($ch);
                                return $response_decrypted;
                            }

                            /**
                             * Submit Gateway Data
                             * @param string $url
                             * @param string $postFields
                             * @return mixed|null
                             */
                            private function shurjopay_submit_data($url = "", $postFields = "")
                            {
                                if (empty($url) || empty($postFields)) {
                                    return null;
                                }

                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $url);
                                curl_setopt($ch, CURLOPT_POST, 1);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                                $response = curl_exec($ch);
                                curl_close($ch);
                                return $response;
                            }

                            /**
                             * Save date into database for admin panel
                             * @param $order_id,$currency,$amount,$return_url
                             * @return true|false
                             */

                            public function save_transaction($order_id, $currency, $amount, $return_url)
                            {
                                global $wpdb;
                                $table_name = $wpdb->prefix . 'sp_orders';

                                $wpdb->insert(
                                    $table_name,
                                    [
                                        'transaction_id'   => $order_id,
                                        'order_id'         => $order_id,
                                        'invoice_id'       => $order_id,
                                        'currency'         => $currency,
                                        'amount'           => $amount,
                                        'bank_status'      => 'Initiate',
                                        'transaction_time' => current_time('mysql'),
                                        'retunr_url'       => $return_url,
                                    ],
                                    [
                                        '%s',
                                        '%s',
                                        '%s',
                                        '%s',
                                        '%s',
                                        '%s',
                                        '%s',
                                        '%s',
                                    ]
                                );
                            }

                            /**
                             * Update date into database for admin panel
                             * @param $order_id,$status,$bank_trx_id,$instrument
                             * @return true|false
                             */
                            public function update_transaction($order_id, $status, $bank_trx_id, $instrument)
                            {
                                global $wpdb;
                                $table_name = $wpdb->prefix . 'sp_orders';

                                $wpdb->update(
                                    $table_name,
                                    [
                                        'bank_status' => $status,
                                        'bank_trx_id' => $bank_trx_id,
                                        'instrument'  => $instrument,
                                    ],
                                    [
                                        'order_id' => $order_id,
                                    ],
                                    [
                                        '%s',
                                        '%s',
                                        '%s',
                                    ],
                                    [
                                        '%s',
                                    ]
                                );
                            }

                            /**
                             * shurjoPay communication logs
                             * @param $logmsg
                             * @return true|false
                             */
                            public function logger($logmsg)
                            {
                                /*
            try
            {
                $logmsg = "\n\n".date("Y.n.j H:i:s")."#".$logmsg."\n\n";
                file_put_contents('/var/www/html/wordpress/wp-content/plugins/shurjoPay/classes/log/'.date("Y.n.j").'.log',$logmsg,FILE_APPEND);
            } catch(Exception $e) {
                file_put_contents('/var/www/html/wordpress/wp-content/plugins/shurjoPay/classes/log/'.date("Y.n.j").'.log',$e->getMessage(),FILE_APPEND);
            }
		*/
                            }
                        }
                }
                $shurjopay_gateway = new WC_Shurjopay();