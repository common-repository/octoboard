<?php


if ( ! class_exists( 'Octoboard_Woo_Analytics_Integration' ) ) :

class Octoboard_Woo_Analytics_Integration extends WC_Integration {
    private $integration_version = '2.0.1';
    private $events_queue = array();
    private $single_item_tracked = false;
    private $has_events_in_cookie = false;
    private $identify_call_data = false;
    private $woo = false;
    private $possible_events = array(
        'logged_out_customer' => 'Logged-out Customer',
        'logged_in_customer' => 'Logged-in Customer',
        'created_visitor' => 'Created Visitor',
        'view_product' => 'Viewed A Product',
        'add_to_cart' => 'Added To The Cart',
        'remove_from_cart' => 'Removed From The Cart',
        'modified_cart' => 'Modified Cart',
        'empty_cart' => 'Emptied Cart',
        'checked_out_cart' => 'Checked-out Cart',
        'entered_store' => 'Entered Store',
        'checkout_start' => 'Started Checkout',
        'placed_order' => 'Placed Order',
    );
    private $endpoint_domain = 'https://flow.octoboard.com/in/api/v1';

    /**
     *
     *
     * Initialization and hooks
     *
     *
     */

    public function __construct() {
        global $woocommerce, $octoboard_woo_analytics_integration;

        $this->woo = function_exists('WC') ? WC() : $woocommerce;

        $this->id = 'octoboard-woo-analytics';
        $this->method_title = __('Octoboard', 'octoboard-woo-analytics');
        $this->method_description = __('Use this plugin to send live events to the Octoboard E-commerce Suite. Data events will be used to create sales funnels and to build shopping basket analytics metrics in real-time.', 'octoboard-woo-analytics');
        $this->accept_tracking = true;
        $this->cbuid = null;
        $this->is_identify = false;

        // Fetch the integration settings
        $this->api_key = $this->get_option('api_key', false);
        $this->ignore_for_roles = $this->get_option('ignore_for_roles', false);
        $this->ignore_for_events = $this->get_option('ignore_for_events', false);

        // ensure correct plugin path
        $this->ensure_path();

        // initiate woocommerce hooks and activities
        add_action('woocommerce_init', array($this, 'on_woocommerce_init'));

        // Load the settings.
        $this->init_form_fields();

        // hook to integration settings update
        add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
    }

    public function ensure_uid()
    {
        $this->cbuid = sanitize_key($this->session_get('ensure_octo_cbuid'));
        if (!$this->cbuid) {
            $this->cbuid = sanitize_key(md5(uniqid(rand(), true)) . rand());
            $this->session_set('ensure_octo_cbuid', $this->cbuid);
        }
    }

    public function on_woocommerce_init() {
        // check if I should clear the events cookie queue
        $this->check_for_octoboard_clear();

        // ensure session identification of visitor
        $this->ensure_uid();

        // ensure identification
        $this->ensure_identify();

        // check if API token are entered && ensure octoboard account identification
        $this->check_for_key();

        // hook to WooCommerce models
        $this->ensure_hooks();

        // process cookie events
        $this->process_cookie_events();
    }

    public function admin_key_notice() {
        $message = 'Almost done! Just enter your Octoboard Secret Key';
        echo '<div class="updated"><p>'.$message.' <a href="'.admin_url('admin.php?page=wc-settings&tab=integration&section=octoboard-woo-analytics').'">here</a></p></div>';
    }

    public function admin_key_error_message() {
        return 'The Octoboard Secret Key you have entered is invalid. You can find the correct one in your Octoboard account.';
    }

    public function no_find_store_error_message() {
        return 'Your store not found in Octoboard.'; // TODO change text
    }

    public function ensure_hooks() {
        // general tracking snippet hook
        add_filter('wp_head', array($this, 'render_snippet'));
        add_filter('wp_head', array($this, 'woocommerce_tracking'));
        add_filter('wp_footer', array($this, 'woocommerce_footer_tracking'));

        // background events tracking
        add_action('woocommerce_add_to_cart', array($this, 'add_to_cart'), 10, 6);
        add_action('woocommerce_remove_cart_item', array($this, 'remove_from_cart'), 10, 2);
        add_action('woocommerce_cart_is_empty', array($this, 'empty_cart'), 10, 0);
        add_action('woocommerce_update_cart_action_cart_updated', array($this, 'modified_cart'), 10);
        add_action('woocommerce_cart_updated', array($this, 'modified_cart_1'), 10);
        add_action('woocommerce_after_cart_item_quantity_update', array($this, 'modified_cart_2'), 10, 3);
        add_action('wp_logout', array($this, 'logged_out_customer'));

        // hook on new order placed
        add_action('woocommerce_checkout_order_processed', array($this, 'placed_order'), 10);

        // cookie clearing actions
        // add_action('wp_ajax_octoboard_chunk_sync', array($this, 'sync_orders_chunk'));

        // add_action('admin_menu', array($this, 'setup_admin_pages'));
    }

    public function ensure_path() {
        define('OCTOBOARD_PLUGIN_PATH', dirname(__FILE__));
    }

    public function ensure_identify() {
        // if user is logged in - set identify_call_data
        if (!is_admin() && is_user_logged_in()) {
            $user = wp_get_current_user();
            $this->identify_call_data = array('id' => strval($user->id), 'params' => array('email' => $user->user_email, 'name' => $user->display_name));
            if ($user->user_firstname != '' && $user->user_lastname) {
                $this->identify_call_data['params']['first_name'] = $user->user_firstname;
                $this->identify_call_data['params']['last_name'] = $user->user_lastname;
            }
        }
    }

    public function check_for_key() {
        if (is_admin()) {
            if (empty($this->api_key) && empty($_POST['save'])) {
                add_action('admin_notices', array($this, 'admin_key_notice'));
            }
        }

        if (!empty($this->api_key)) {
            # submit to Octoboard to validate credentials
            $request_body = array(
                'eventOwner' => array(
                    'storeId' => home_url(),
                    'visitorId' => $this->cbuid,
                    'customerId' => $this->get_customer_id(),
                    'ip' => WC_Geolocation::get_ip_address(),
                ));
            $this->is_identify = sanitize_key($this->session_get($this->get_identify_cookie_name()));
            if (!$this->is_identify) {
                $check_request = array(
                    'url' => $this->endpoint_domain . '/visitor/events/check-store',
                    'event' => 'check-store',
                    'params' => $request_body,
                );
                $response = $this->send_single_request($check_request);
                if ($response['response']['code'] >= 200 && $response['response']['code'] <= 299) {
                    $this->session_set($this->get_identify_cookie_name(), 'true');
                    $this->is_identify = true;
                }
                if ($response['response']['code'] === 404) {
                    // display error 404 message
                    WC_Admin_Settings::add_error($this->no_find_store_error_message());
                    $this->api_key = '';
                }
                if ($response['response']['code'] === 406) {
                    // display error 406 message
                    WC_Admin_Settings::add_error($this->admin_key_error_message());
                    $this->api_key = '';
                }
            }
        }
    }

    /**
     *
     *
     * Events tracking methods, event hooks
     *
     *
    */


    public function woocommerce_tracking() {
        // check if woocommerce is installed
        if(class_exists('WooCommerce')){
            /** check certain tracking scenarios **/

            // if visitor is viewing product
            if(!$this->single_item_tracked && function_exists('is_product') && is_product()){
                $product_id = strval(get_queried_object_id());
                if(!$this->check_existed_events_in_cookie('view_product'.$product_id.$this->cbuid)){
                    $params = array(
                        'eventOwner' => array(
                            'storeId' => home_url(),
                            'visitorId' => $this->cbuid,
                            'customerId' => $this->get_customer_id(),
                            'ip' => WC_Geolocation::get_ip_address(),
                        ),
                        'productId' => $product_id,
                    );
                    $this->put_event_in_queue('view_product', $params);
                    $this->single_item_tracked = true;
                }
            }

            // if visitor is entered store
            if(!$this->single_item_tracked && function_exists('is_shop') && is_shop()){
                if(!$this->check_existed_events_in_cookie('entered_store'.$this->cbuid)) {
                    $source = wp_get_referer();
                    if (!$source) {
                        $source = '';
                    }
                    $params = array(
                        'eventOwner' => array(
                            'storeId' => home_url(),
                            'visitorId' => $this->cbuid,
                            'customerId' => $this->get_customer_id(),
                            'ip' => WC_Geolocation::get_ip_address(),
                        ),
                        'source' => $source,
                    );
                    $this->put_event_in_queue('entered_store', $params);
                    $this->single_item_tracked = true;
                }
            }

            if(!$this->single_item_tracked && function_exists('is_checkout') && is_checkout()){
                $params = $this->get_cart_products_params();
                $this->put_event_in_queue('checked_out_cart', $params);
                $this->single_item_tracked = true;
            }
        }

        // check if there are events in the queue to be sent to Octoboard
        if($this->identify_call_data !== false && !$this->check_existed_events_in_cookie('logged_in_customer'.$this->cbuid)){
            $params = array(
                'eventOwner' => array(
                    'storeId' => home_url(),
                    'visitorId' => $this->cbuid,
                    'customerId' => $this->get_customer_id(),
                    'ip' => WC_Geolocation::get_ip_address(),
                ));
            $this->put_event_in_queue('logged_in_customer', $params);
        };
        if($this->cbuid && !$this->check_existed_events_in_cookie('created_visitor'.$this->cbuid)){
            $params = array(
                'eventOwner' => array(
                    'storeId' => home_url(),
                    'visitorId' => $this->cbuid,
                    'customerId' => $this->get_customer_id(),
                    'ip' => WC_Geolocation::get_ip_address(),
                ),
                'userAgent' => strval(wc_get_user_agent()),
            );
            $this->put_event_in_queue('created_visitor', $params);
        }
        if(count($this->events_queue) > 0) $this->send_events();
    }

    public function woocommerce_footer_tracking(){
        if(count($this->events_queue) > 0) $this->render_footer_events();
    }

    public function put_event_in_queue($event = '', $params = array()){
        if($this->check_if_event_should_be_ignored($event)){
            return true;
        }
        $this->events_queue[] = $this->prepare_event_for_queue($event, $params);
        return true;
    }

    public function put_event_in_cookie_queue($event, $params){
        if($this->check_if_event_should_be_ignored($event)){
            return true;
        }
        $this->add_item_to_cookie($this->prepare_event_for_queue($event, $params));
        return true;
    }

    public function prepare_event_for_queue($event, $params){
        $request_url = '';
        switch ($event) {
            case 'view_product':
                $request_url = $this->endpoint_domain.'/visitor/events/viewed-product';
                break;
            case 'logged_in_customer':
                $request_url = $this->endpoint_domain.'/visitor/events/logged-in-customer';
                break;
            case 'add_to_cart':
                $request_url = $this->endpoint_domain.'/visitor/events/added-product';
                break;
            case 'remove_from_cart':
                $request_url = $this->endpoint_domain.'/visitor/events/removed-product';
                break;
            case 'empty_cart':
                $request_url = $this->endpoint_domain.'/visitor/events/emptied-cart';
                break;
            case 'modified_cart':
                $request_url = $this->endpoint_domain.'/visitor/events/modified-cart';
                break;
            case 'entered_store':
                $request_url = $this->endpoint_domain.'/visitor/events/entered-store';
                break;
            case 'created_visitor':
                $request_url = $this->endpoint_domain.'/visitor/events/created-visitor';
                break;
            case 'logged_out_customer':
                $request_url = $this->endpoint_domain.'/visitor/events/logged-out-customer';
                break;
            case 'placed_order':
                $request_url = $this->endpoint_domain.'/visitor/events/placed-order';
                break;
            case 'checked_out_cart':
                $request_url = $this->endpoint_domain.'/visitor/events/checked-out-cart';
                break;
        }
        return array('url' => $request_url, 'event' => $event, 'params' => $params);
    }

    public function check_if_event_should_be_ignored($event){
        if(empty($this->ignore_for_events)){
            return false;
        }
        if(in_array($event, $this->ignore_for_events)){
            return true;
        }
        return false;
    }

    public function add_to_cart($cart_item_key, $product_id, $quantity, $variation_id = false, $variation = false, $cart_item_data = false){
        $params = array(
            'eventOwner' => array(
                'storeId' => home_url(),
                'visitorId' => $this->cbuid,
                'customerId' => $this->get_customer_id(),
                'ip' => WC_Geolocation::get_ip_address(),
            ),
            'product' => array(
                'productId' => strval($product_id),
                'quantity' => $quantity,
            ),
        );
        $this->put_event_in_cookie_queue('add_to_cart', $params);
    }

    public function remove_from_cart($key_id){
        if (!is_object($this->woo->cart)) {
            return true;
        }
        $cart_items = $this->woo->cart->get_cart();
        $removed_cart_item = isset($cart_items[$key_id]) ? $cart_items[$key_id] : false;
        if($removed_cart_item) {
            $params = array(
                'eventOwner' => array(
                    'storeId' => home_url(),
                    'visitorId' => $this->cbuid,
                    'customerId' => $this->get_customer_id(),
                    'ip' => WC_Geolocation::get_ip_address(),
                ),
                'productId' => strval($removed_cart_item['product_id']),
            );
            $this->put_event_in_cookie_queue('remove_from_cart', $params);
        }
    }

    public function empty_cart(){
        $params = array(
            'eventOwner' => array(
                'storeId' => home_url(),
                'visitorId' => $this->cbuid,
                'customerId' => $this->get_customer_id(),
                'ip' => WC_Geolocation::get_ip_address(),
            ),
        );
        $this->put_event_in_cookie_queue('empty_cart', $params);
    }

    public function modified_cart(){
        $params = $this->get_cart_products_params();
        $paramsHash = hash('md5', json_encode($params, JSON_UNESCAPED_UNICODE));
        if($this->cbuid && !$this->check_existed_events_in_cookie('modified_cart'.$this->cbuid.$paramsHash)) {
            $this->put_event_in_cookie_queue('modified_cart', $params);
        }
    }

    public function modified_cart_1(){
        $params = $this->get_cart_products_params();
        $paramsHash = hash('md5', json_encode($params, JSON_UNESCAPED_UNICODE));
        if($this->cbuid && !$this->check_existed_events_in_cookie('modified_cart'.$this->cbuid.$paramsHash)) {
            $this->put_event_in_cookie_queue('modified_cart', $params);
        }
    }

    public function modified_cart_2($cart_item_key, $quantity, $old_quantity) {
        $oldParams = $this->get_cart_products_params();
        $newProducts = [];
        foreach($oldParams['products'] as $product){
            $productId = $product['productId'];
            $productQuantity = $product['quantity'];
            if($productId === strval($cart_item_key) && $productQuantity !== $quantity) {
                $productQuantity = $quantity;
            }
            $newProducts[] = array(
                'productId' => $productId,
                'quantity' => $productQuantity,
            );
        }
        $params[] = array(
            'eventOwner' => $oldParams['eventOwner'],
            'products' => $newProducts,
        );
        $paramsHash = hash('md5', json_encode($params, JSON_UNESCAPED_UNICODE));
        if($this->cbuid && !$this->check_existed_events_in_cookie('modified_cart'.$this->cbuid.$paramsHash)) {
            $this->put_event_in_cookie_queue('modified_cart', $params);
        }
    }

    public function logged_out_customer(){
        $params = array(
            'eventOwner' => array(
                'storeId' => home_url(),
                'visitorId' => $this->cbuid,
                'customerId' => $this->get_customer_id(),
                'ip' => WC_Geolocation::get_ip_address(),
            ),
        );
        $this->send_single_request($this->prepare_event_for_queue('logged_out_customer', $params));
        $this->session_set($this->get_do_identify_cookie_name(), json_encode(array()));
    }

    public function placed_order($order_id){
        // fetch the order
        $order = new WC_Order($order_id);
        $order_items = $order->get_items();
        foreach($order_items as $product){
            $products[] = array(
                'productId' => strval($product['product_id']),
                'quantity' => $product['qty']
            );
        }
        $params = array(
            'eventOwner' => array(
                'storeId' => home_url(),
                'visitorId' => $this->cbuid,
                'customerId' => $this->get_customer_id(),
                'ip' => WC_Geolocation::get_ip_address(),
            ),
            'products' => $products,
        );
        $this->put_event_in_cookie_queue('placed_order', $params);
        $this->session_set($this->get_do_identify_cookie_name(), wp_json_encode($this->identify_call_data, JSON_UNESCAPED_UNICODE));
    }

    /**
     *
     *
     * Tracking code rendering
     *
     *
     */

    public function send_events(){
        if($this->accept_tracking) {
            foreach ($this->events_queue as $event) {
                $this->send_single_request($event);
            }
        }
    }

    public function send_single_request($request){
        $body = json_encode($request['params']);
        $signature = base64_encode(hash_hmac('sha256', $body, $this->api_key, true));
        $response = null;
        if($this->accept_tracking) {
            $response = wp_remote_request(
                $request['url'],
                array(
                    'method' => 'POST',
                    'body' => $body,
                    'headers' => array('content-type' => 'application/json', 'X-Octoboard-Hmac-Sha256' => $signature),
                ));
        }
        return $response;
    }

    public function render_footer_events(){
        include_once(OCTOBOARD_PLUGIN_PATH . '/render_footer_tracking_events.php');
    }

    public function render_snippet(){
        // check if we should track data for this user (if user is available)
        // also check if we should track data for this shop (if owner have account on octoboard)
        if (!$this->is_identify) {
            $this->accept_tracking = false;
            return;
        }
        if( !is_admin() && is_user_logged_in()){
            $user = wp_get_current_user();
            if($user->roles && $this->ignore_for_roles){
                foreach($user->roles as $r){
                    if(in_array($r, $this->ignore_for_roles)){
                        $this->accept_tracking = false;
                    }
                }
            }
        }
    }

    /**
     *
     *
     * Session and cookie handling
     *
     *
     */

    public function session_get($k){
        if(!is_object($this->woo->session)){
            if(isset($_COOKIE[$k])) {
                if (!is_string($_COOKIE[$k])) {
                    return false;
                } else {
                  return sanitize_key($_COOKIE[$k]);
                }
            } else {
                return false;
            }
        }
        return $this->woo->session->get($k);
    }

    public function session_set($k, $v){
        if(!is_object($this->woo->session)){
            @setcookie($k, $v, time() + 43200, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE[$k] = $v;
            return true;
        }
        return $this->woo->session->set($k, $v);
    }

    public function add_item_to_cookie($data){
        $items = $this->get_items_in_cookie();
        if(empty($items)) $items = array();
        $items[] = $data;
        $encoded_items = json_encode($items, JSON_UNESCAPED_UNICODE);
        $this->session_set($this->get_cookie_name(), $encoded_items);
    }

    public function get_items_in_cookie(){
        $items = array();
        $data = $this->session_get($this->get_cookie_name());
        if(!empty($data)){
            $data = stripslashes($data);
            $items = json_decode($data, true);
        }
        return $items;
    }

    public function get_identify_data_in_cookie(){
        $identify = array();
        $data = $this->session_get($this->get_do_identify_cookie_name());
        if(!empty($data)){
            $data = stripslashes($data);
            $identify = json_decode($data, true);
        }
        return $identify;
    }

    public function check_existed_events_in_cookie($ev){
        $events = array();
        $data = $this->session_get($this->get_existed_events_cookie_name());
        // $data = $_COOKIE['octoboardexevid_'];
        if(!empty($data)){
            $data = stripslashes($data);
            $events = json_decode($data, true);
        }

        if (!empty($events) && in_array($ev, $events)) {
            return true;
        }
        $events[] = $ev;
        $encoded_items = json_encode($events, JSON_UNESCAPED_UNICODE);
        $this->session_set($this->get_existed_events_cookie_name(), $encoded_items);
        return false;
    }

    public function clear_items_in_cookie(){
        $this->session_set($this->get_cookie_name(), json_encode(array()));
        $this->session_set($this->get_do_identify_cookie_name(), json_encode(array()));
    }

    private function get_cookie_name(){
        return 'octoboardqueue_' . COOKIEHASH;
    }

    private function get_identify_cookie_name(){
        return 'octo_id_' . COOKIEHASH;
    }

    private function get_do_identify_cookie_name(){
        return 'octoboarddoid_' . COOKIEHASH;
    }

    private function get_existed_events_cookie_name(){
        return 'octoboardexevid_' . COOKIEHASH;
    }

    public function check_for_octoboard_clear(){
        if(!empty($_REQUEST) && !empty($_REQUEST['octoboard_clear'])){
            $this->clear_items_in_cookie();
            wp_send_json_success();
        }
    }

    public function process_cookie_events(){
        $items = $this->get_items_in_cookie();
        if(empty($items)) $items = array();
        if(count($items) > 0){
            $this->has_events_in_cookie = true;
            foreach($items as $event){
                $this->put_event_in_queue($event['event'], $event['params']);
            }
        }

        // check if identify data resides in the session
        $identify_data = $this->get_identify_data_in_cookie();
        if(!empty($identify_data)) $this->identify_call_data = $identify_data;
    }

    /**
     * Initialize integration settings form fields.
     */
    public function init_form_fields() {

        // initiate possible user roles from settings
        $possible_ignore_roles = false;

        if(is_admin()){
            global $wp_roles;
            $possible_ignore_roles = array();
            foreach($wp_roles->roles as $role => $stuff){
                $possible_ignore_roles[$role] = $stuff['name'];
            }
        }

        $this->form_fields = array(
            'api_key' => array(
                'title'             => __( 'Secret key', 'octoboard-woo-analytics' ),
                'type'              => 'text',
                'description'       => __( '<strong style="color: green;">(Required)</strong> Enter your Octoboard Secret Key. You can find it in your Octoboard account.<br /> Don\'t have one? <a href="https://www.octoboard.com/support/connecting-to-woocommerce" target="_blank">Sign-up for free</a> now, it only takes a few seconds.', 'octoboard-woo-analytics' ),
                'desc_tip'          => false,
                'default'           => ''
            )
        );

        if($possible_ignore_roles){
            $this->form_fields['ignore_for_roles'] = array(
                'title'             => __( 'Ignore tracking for roles', 'octoboard-woo-analytics' ),
                'type'              => 'multiselect',
                'description'       => __( '<strong style="color: #999;">(Optional)</strong> If you check any of the roles, tracking data will be ignored for WP users with this role', 'octoboard-woo-analytics' ),
                'desc_tip'          => false,
                'default'           => '',
                'options'			=> $possible_ignore_roles
            );
        }

        $this->form_fields['ignore_for_events'] = array(
              'title'             => __( 'Do not send the selected tracking events', 'octoboard-woo-analytics' ),
              'type'              => 'multiselect',
              'description'       => __( '<strong style="color: #999;">(Optional)</strong> Tracking won\'t be sent for the selected events', 'octoboard-woo-analytics' ),
              'desc_tip'          => false,
              'default'           => '',
              'options'			  => $this->possible_events
        );
    }

    public function get_customer_id() {
        $cust_id = '';
        if($this->identify_call_data !== false){
            $cust_id = $this->identify_call_data['id'];
        }
        return $cust_id;
    }

    public function get_cart_products_params(){
        $cart_items = $this->woo->cart->get_cart();
        $products = array();

        foreach($cart_items as $cart_item){
            $products[] = array(
                'productId' => strval($cart_item['product_id']),
                'quantity' => $cart_item['quantity'],
            );
        }
        return array(
            'eventOwner' => array(
                'storeId' => home_url(),
                'visitorId' => $this->cbuid,
                'customerId' => $this->get_customer_id(),
                'ip' => WC_Geolocation::get_ip_address(),
            ),
            'products' => $products,
        );
    }
}

endif;
