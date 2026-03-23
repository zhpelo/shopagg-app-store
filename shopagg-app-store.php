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
define('SHOPAGG_APP_STORE_API_URL', 'http://new-shopagg.local/api/shopagg-app-store/');

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
    if (! shopagg_app_store_is_logged_in()) {
        ShopAGG_App_Store_Auth::instance()->render_login_page();
        return;
    }

    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'plugins';
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    $resource_id = isset($_GET['resource_id']) ? absint($_GET['resource_id']) : 0;

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

    wp_enqueue_style(
        'shopagg-app-store-css',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/css/style.css',
        [],
        SHOPAGG_APP_STORE_VERSION
    );

    wp_enqueue_script(
        'shopagg-app-store-js',
        SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/js/app.js',
        ['jquery'],
        SHOPAGG_APP_STORE_VERSION,
        true
    );

    wp_localize_script('shopagg-app-store-js', 'shopaggAppStore', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('shopagg_app_store_nonce'),
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
}
register_deactivation_hook(__FILE__, 'shopagg_app_store_deactivate');
