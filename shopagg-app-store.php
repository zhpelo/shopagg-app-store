<?php
/**
 * Plugin Name: ShopAGG App Store
 * Plugin URI: https://shopagg.com
 * Description: Install and manage WordPress plugins and themes from ShopAGG App Store.
 * Version: 1.0.0
 * Author: ShopAGG
 * Author URI: https://shopagg.com
 * Text Domain: shopagg-app-store
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
    exit;
}

// Constants
define('SHOPAGG_APP_STORE_VERSION', '1.0.0');
define('SHOPAGG_APP_STORE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPAGG_APP_STORE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SHOPAGG_APP_STORE_API_DOMAIN', 'http://v3.shopagg.test');
define('SHOPAGG_APP_STORE_DEFAULT_API_URL', SHOPAGG_APP_STORE_API_DOMAIN . '/api/shopagg-app-store/');
define('SHOPAGG_APP_STORE_CLIENT_PLUGIN_SLUG', 'shopagg-app-store');

// Include files
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-auth.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-market.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-installer.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-updater.php';
require_once SHOPAGG_APP_STORE_PLUGIN_DIR . 'includes/class-license.php';

/**
 * Check if user is logged in to ShopAGG App Store.
 */
function shopagg_app_store_is_logged_in() {
    $token = get_option('shopagg_app_store_access_token');
    return ! empty($token);
}

/**
 * Get current access token.
 */
function shopagg_app_store_get_token() {
    return get_option('shopagg_app_store_access_token', '');
}

/**
 * Get current user data.
 */
function shopagg_app_store_get_user() {
    return get_option('shopagg_app_store_user', []);
}

/**
 * Check whether a slug belongs to the ShopAGG App Store client plugin itself.
 *
 * @param string $slug
 * @return bool
 */
function shopagg_app_store_is_client_plugin_slug($slug) {
    return sanitize_key((string) $slug) === SHOPAGG_APP_STORE_CLIENT_PLUGIN_SLUG;
}

/**
 * Check whether a resource payload represents the ShopAGG App Store client plugin itself.
 *
 * @param array $resource
 * @return bool
 */
function shopagg_app_store_is_client_resource($resource) {
    return is_array($resource)
        && ! empty($resource['slug'])
        && shopagg_app_store_is_client_plugin_slug($resource['slug']);
}



/**
 * Get ShopAGG dashboard URL from API base URL.
 */
function shopagg_app_store_get_dashboard_url() {
    return SHOPAGG_APP_STORE_API_DOMAIN . '/dashboard/api-tokens';
}

/**
 * Build the connect page URL for binding an API token.
 *
 * @param string $redirect_to Optional admin URL to return to after connecting.
 * @return string
 */
function shopagg_app_store_get_connect_url($redirect_to = '') {
    $args = [
        'page' => 'shopagg-app-store',
        'action' => 'connect',
    ];

    if (! empty($redirect_to)) {
        $args['redirect_to'] = $redirect_to;
    }

    return add_query_arg($args, admin_url('admin.php'));
}

/**
 * Normalize the current WordPress site domain for license binding.
 */
function shopagg_app_store_get_site_domain() {
    $home_url = home_url('/');
    $host = wp_parse_url($home_url, PHP_URL_HOST);
    $path = wp_parse_url($home_url, PHP_URL_PATH);

    if (! is_string($host) || $host === '') {
        return untrailingslashit($home_url);
    }

    $normalized = strtolower($host);
    $path = is_string($path) ? trim($path) : '';

    if ($path !== '' && $path !== '/') {
        $normalized .= '/' . trim($path, '/');
    }

    return $normalized;
}

/**
 * Clear cached license check result for a resource.
 */
function shopagg_app_store_forget_license_cache($resource_id) {
    $resource_id = absint($resource_id);

    if ($resource_id) {
        delete_transient('shopagg_license_' . $resource_id);
    }
}

/**
 * Initialize the plugin.
 */
function shopagg_app_store_init() {
    // Initialize components
    ShopAGG_App_Store_Auth::instance();
    ShopAGG_App_Store_Market::instance();
    ShopAGG_App_Store_Installer::instance();
    ShopAGG_App_Store_Updater::instance();
    ShopAGG_App_Store_License::instance();
}
add_action('plugins_loaded', 'shopagg_app_store_init');

/**
 * Add admin menu.
 */
function shopagg_app_store_admin_menu() {
    add_menu_page(
        __('ShopAGG App Store', 'shopagg-app-store'),
        __('App Store', 'shopagg-app-store'),
        'manage_options',
        'shopagg-app-store',
        'shopagg_app_store_render_page',
        'dashicons-store',
        30
    );
}
add_action('admin_menu', 'shopagg_app_store_admin_menu');

/**
 * Main page render.
 */
function shopagg_app_store_render_page() {
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'browse';
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $resource_id = isset($_GET['resource_id']) ? absint($_GET['resource_id']) : 0;
    $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

    if ($action === 'connect') {
        if (shopagg_app_store_is_logged_in() && ! empty($redirect_to)) {
            wp_safe_redirect($redirect_to);
            exit;
        }

        ShopAGG_App_Store_Auth::instance()->render_login_page();
        return;
    }

    if ($action === 'detail' && $resource_id > 0) {
        ShopAGG_App_Store_Market::instance()->render_detail_page($resource_id);
        return;
    }

    ShopAGG_App_Store_Market::instance()->render_market_page($tab);
}

/**
 * Enqueue admin styles and scripts.
 */
function shopagg_app_store_admin_enqueue($hook) {
    if (strpos($hook, 'shopagg-app-store') === false) {
        return;
    }

    $css_file = SHOPAGG_APP_STORE_PLUGIN_DIR . 'assets/css/style.css';
    $js_file  = SHOPAGG_APP_STORE_PLUGIN_DIR . 'assets/js/app.js';

    wp_enqueue_style(
        'shopagg-app-store-css',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/css/style.css',
        [],
        filemtime($css_file)
    );

    wp_enqueue_script(
        'shopagg-app-store-js',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/js/app.js',
        ['jquery'],
        filemtime($js_file),
        true
    );

    wp_localize_script('shopagg-app-store-js', 'shopaggAppStore', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('shopagg_app_store_nonce'),
        'i18n'    => [
            'existingOrderIntro' => __('An unpaid order already exists for this resource. Please complete the payment to continue.', 'shopagg-app-store'),
            'orderCreatedIntro' => __('Your order has been created. Please choose a payment method to continue.', 'shopagg-app-store'),
            'choosePaymentMethod' => __('Please choose a payment method.', 'shopagg-app-store'),
            'startingPayment' => __('Starting payment...', 'shopagg-app-store'),
            'completeAlipayInWindow' => __('Please complete the Alipay payment in the new window.', 'shopagg-app-store'),
            'popupBlocked' => __('The browser blocked the popup window. Please allow popups and try again.', 'shopagg-app-store'),
            'scanWechat' => __('Please scan the QR code with WeChat to complete the payment.', 'shopagg-app-store'),
            'paymentSuccessInstall' => __('Payment successful. You can now install ', 'shopagg-app-store'),
            'connecting' => __('Connecting...', 'shopagg-app-store'),
            'connect' => __('Connect', 'shopagg-app-store'),
            'connectionFailed' => __('Connection failed. Please try again.', 'shopagg-app-store'),
            'installing' => __('Installing...', 'shopagg-app-store'),
            'install' => __('Install', 'shopagg-app-store'),
            'processing' => __('Processing...', 'shopagg-app-store'),
            'retry' => __('Retry', 'shopagg-app-store'),
            'creatingOrder' => __('Creating order...', 'shopagg-app-store'),
            'purchase' => __('Purchase', 'shopagg-app-store'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'shopagg_app_store_admin_enqueue');

/**
 * Plugin activation.
 */
function shopagg_app_store_activate() {
    // Nothing specific to do on activation for now
}
register_activation_hook(__FILE__, 'shopagg_app_store_activate');

/**
 * Plugin deactivation - clean up tokens.
 */
function shopagg_app_store_deactivate() {
    delete_option('shopagg_app_store_access_token');
    delete_option('shopagg_app_store_user');
    delete_option('shopagg_app_store_managed_resources');
}
register_deactivation_hook(__FILE__, 'shopagg_app_store_deactivate');
