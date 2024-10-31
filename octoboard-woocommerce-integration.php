<?php
/**
 * Plugin Name: Octoboard
 * Plugin URI: https://www.octoboard.com/support/connecting-to-woocommerce
 * Description: One-click WooCommerce integration with Octoboard eCommerce Analytics
 * Version: 2.0.1
 * Author: Octoboard
 * Author URI: https://www.octoboard.com/
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'Octoboard_Woo_Analytics' ) ) :

class Octoboard_Woo_Analytics {


	public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
    //     add_filter('query_vars', array($this, 'add_clear_query_var'), 10, 1);
    //     add_filter('query_vars', array($this, 'add_endpoint_query_vars'), 10, 1);
	}

	public function init(){
		// Checks if WooCommerce is installed and activated.
		if ( class_exists( 'WC_Integration' ) ) {
			// Include our integration class.
			include_once 'includes/integration.php';

			// Register the integration.
			add_filter( 'woocommerce_integrations', array( $this, 'add_integration' ) );
		} else {
			// throw an admin error if you like
		}
	}

  // public function add_clear_query_var($vars){
  //   $vars[] = 'octoboard_clear';
  //   return $vars;
  // }

  // public function add_endpoint_query_vars($vars){
  //   $vars[] = 'octoboard_endpoint';
  //   $vars[] = 'req_id';
  //   $vars[] = 'recent_orders_sync_days';
  //   $vars[] = 'octoboard_order_ids';
  // return $vars;
  // }

	public function add_integration($integrations){
		$integrations[] = 'Octoboard_Woo_Analytics_Integration';
		return $integrations;
	}

}

$OctoboardWooAnalytics = new Octoboard_Woo_Analytics(__FILE__);


endif;

?>
