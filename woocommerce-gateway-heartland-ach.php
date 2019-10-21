<?php
/*
 * Plugin Name: Woocommerce Heartland Payment Systems ACH Gateway
 * Plugin URI: www.github.com/dbshoupe/woocommerce-heartland-ach-plugin
 * Description: Enables Heartland Payment Systems customers to receive ACH payments on their WooCommerce Website.
 * Version: 1.0
 * Author: Brandon Shoupe
 * Author URI: www.digitalconcrete.io
 *
 */
require_once plugin_dir_path(__FILE__) . 'Hps.php';

function init_woocommerce_heartland_ach_class()
{
    class WC_Gateway_PCP_Heartland_Payment_Systems_ACH_Payments extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'woocommerce_heartland_ach';

            $this->icon = plugin_dir_url(__FILE__) . '/images/checkbook.png';
            $this->has_fields = true;
            $this->method_title = 'Heartland ACH Payments';
            $this->method_description = 'Allows your WooCommerce website to accept ACH payments with your Heartland Payment Systems Account. You must have Heartland Payment Systems for this plugin to function.';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('enabled');
            $this->title = $this->get_option('public_key');
            $this->title = $this->get_option('secret_key');
            $this->title = $this->get_option('description');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'description' => __('Enable Heartland Payment Systems ACH Payments.', 'woocommerce'),
                    'default' => __('no'),
                ),
                'public_key' => array(
                    'title' => __('Public Key', 'woocommerce'),
                    'type' => 'textbox',
                    'description' => __('Enter your Heartland Payment Systems Public Key', 'woocommerce'),
                ),
                'secret_key' => array(
                    'title' => __('Secret Key', 'woocommerce'),
                    'type' => 'textbox',
                    'description' => __('Enter your Heartland Payment Systems Secret Key', 'woocommerce'),
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('Pay with ACH', 'woocommerce'),
                    'default' => __("eCheck", 'woocommerce'),
                ),
            );
        }

        function admin_options()
        {
            ?>
         <h2><?php _e('Heartland Payment Systems ACH Payments', 'woocommerce');?></h2>
         <table class="form-table">
         <?php $this->generate_settings_html();?>
         </table> <?php
}

        function payment_fields()
        {
            ?>
            <div class="securesubmit-header">
                  <div class="secure"></div>
            </div>
            <fieldset>
                  <div class="securesubmit-content">
                        <p class="securesubmit-description">
                              Pay direct, straight from your checking or savings account
                        </p>
                  </div>
                  <div class="securesubmit-content">
                        <div class="dc-form-row">
                              <div class="dc-form-row-first half-row">
                                    <label for="routing_number">Routing Number</label>
                                    <input type="text" name="routing_number" placeholder="⑆XXXXXXXXX⑆" />
                              </div>
                              <div class="dc-form-row-last half-row">
                                    <label for="account_number">Account Number</label>
                                    <input type="text" name="account_number" placeholder="XXXXXXXXX" />
                              </div>
                        </div>
                        <div class="dc-form-row">
                           <div class="dc-form-row-first half-row">
                              <label for="account_type">Account Type</label>
                              <select name="account_type">
                                 <option value="personal">Personal</option>
                                 <option value="business">Business</option>
                              </select>
                           </div>
                           <div class="dc-form-row-last half-row">
                              <label for="checking_savings">Is this a checking or savings account?</label>
                              <select name="checking_savings">
                                 <option value="checking">Checking</option>
                                 <option value="savings">Savings</option>
                              </select>
                           </div>
                        </div>
                  </div>

            </fieldset>
            <?
        }

        private function get_hps_sdk()
        {
            require_once plugin_dir_path(__FILE__) . 'Hps.php';
        }

        function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            $subtotal = 0;
            $discounts = 0;

            // foreach( $order->get_items() as $item ) {
            //       $subtotal += $order->get_item_subtotal($item, false);
            // }
            // $total = $subtotal - $order->get_discount_total();
            $total = $order->get_total();

            $service = new HpsFluentCheckService($this->config());

            $address = new HpsAddress();
            $address->address = $order->get_billing_address_1();
            $address->city = $order->get_billing_city();
            $address->state = $order->get_billing_state();
            $address->zip = $order->get_billing_postcode();

            $checkHolder = new HpsCheckHolder();
            $checkHolder->address = $address;
            $checkHolder->checkName = $_POST['billing_first_name'] . ' ' . $_POST['billing_last_name'];

            $certification = new HpsCheck();
            $certification->routingNumber = $_POST['routing_number'];
            $certification->accountNumber = $_POST['account_number'];
            $certification->checkHolder = $checkHolder;

            $check = $certification;
            $check->secCode = HpsSECCode::WEB;
            $check->dataEntryMode = HpsDataEntryMode::MANUAL;
            $check->checkType = HpsCheckType::PERSONAL;

            $check->checkType = $_POST['account_type'] == 'personal' ? HpsCheckType::PERSONAL : HpsCheckType::BUSINESS;
            $check->accountType = $_POST['checking_savings'] == 'checking' ? HpsAccountType::CHECKING : HpsAccountType::SAVINGS;

            try {

                $response = $service->sale($total)->withCheck($check)->execute();

                // Payment complete
                $order->payment_complete();
                // if($order->payment_complete()) {
                $order->update_status('processing', __('Processing eCheck payment', 'woocommerce'));
                // $order->payment_complete($response['transactionNumber']);
                // Add final note for order
                $order->add_order_note(sprintf(__('%s payment approved!', 'woocommerce'), $this->title));

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
                // }

            } catch (ApiException $e) {
                wc_add_notice(__('Payment error:', 'woothemes') . $e, 'error');
                return;
            }
        }

        private function config()
        {
            $config = new HpsServicesConfig();
            $config->secretApiKey = $this->getSetting('secret_key');
            $config->publicApiKey = $this->getSetting('public_key');
            $config->developerId = '002914';
            $config->versionNumber = '2861';
            return $config;
        }

        protected function getSetting($setting)
        {
            $value = null;
            if (isset($this->settings[$setting])) {
                $value = $this->settings[$setting];
            }
            return $value;
        }
    }
}

function wpse_load_plugin_css()
{
    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style('style1', $plugin_url . 'css/style.css');
}
add_action('wp_enqueue_scripts', 'wpse_load_plugin_css');

add_action('plugins_loaded', 'init_woocommerce_heartland_ach_class');

function add_woocommerce_heartland_ach_class($methods)
{
    $methods[] = 'WC_Gateway_PCP_Heartland_Payment_Systems_ACH_Payments';
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_woocommerce_heartland_ach_class');

?>
