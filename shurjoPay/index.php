<?php
/**
 * Plugin Name: WooCommerce shurjoPay gateway
 * Plugin URI: http://shurjopay.com/
 * Description: Extends WooCommerce with shurjoPay gateway.
 * Version: 2.0.1
 * Author: shurjoPay
 * Author URI: http://shurjopay.com/
 * Text Domain: shurjopay
 */
defined('ABSPATH') OR exit('Direct access not allowed');
if (!defined('SHURJOPAY_PATH')) {
    define('SHURJOPAY_PATH', plugin_dir_path(__FILE__));
}

if (!defined('SHURJOPAY_URL')) {
    define('SHURJOPAY_URL', plugins_url('', __FILE__));
}


require_once( 'admin_page.php' );


add_action('plugins_loaded', 'woocommerce_shurjopay_init', 0);

    global $sp_db_version;
    $sp_db_version = '1.0';

 function db_install() {
    global $wpdb;
    global $sp_db_version;

    $table_name = $wpdb->prefix . 'sp_orders';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        transaction_id varchar(155) DEFAULT '' NOT NULL,
        order_id varchar(155) DEFAULT '' NOT NULL,
        invoice_id varchar(155) DEFAULT '' NOT NULL,
        currency varchar(10) DEFAULT '' NOT NULL,
        amount varchar(10) DEFAULT '' NOT NULL,
        instrument varchar(20) DEFAULT '' NOT NULL,
        bank_status varchar(155) DEFAULT '' NOT NULL,
        bank_trx_id varchar(155) DEFAULT '' NOT NULL,
        transaction_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        retunr_url varchar(55) DEFAULT '' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    add_option( 'sp_db_version', $sp_db_version );
}

register_activation_hook( __FILE__, 'db_install' );

function woocommerce_shurjopay_init()
{
    if (!class_exists('WC_Payment_Gateway')) return;
    include_once 'classes/class-shurjopay-gateway.php';

    /**
     * Add the Gateway to WooCommerce
     * @param $methods
     * @return array
     */
    function woocommerce_add_shurjopay_gateway($methods)
    {
        $methods[] = 'WC_Shurjopay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_shurjopay_gateway');
}

/**
 * -----------------------
 * Plugin Activation Hook
 * -----------------------
 */
register_activation_hook(__FILE__, 'pluginActivate');
function pluginActivate()
{
    update_option("shurjopay_version", "3.0.1");
}

/**
 * -------------------------
 * Plugin Deactivation Hook
 * -------------------------
 */
register_deactivation_hook(__FILE__, 'pluginDeactivate');
function pluginDeactivate()
{

}

/**
 * ----------------------
 * Plugin Uninstall Hook
 * ----------------------
 */
register_uninstall_hook(__FILE__, 'pluginUninstall');
function pluginUninstall()
{

}
