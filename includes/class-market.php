<?php
/**
 * ShopAGG App Store Market
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Market {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        add_action('wp_ajax_shopagg_app_store_install', [$this, 'ajax_install']);
        add_action('wp_ajax_shopagg_app_store_purchase', [$this, 'ajax_purchase']);
    }

    /**
     * Render the market page.
     */
    public function render_market_page($tab = 'plugins') {
        $user = shopagg_app_store_get_user();
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-header">
                <h1><?php esc_html_e('ShopAGG App Store', 'shopagg-app-store'); ?></h1>
                <div class="shopagg-user-info">
                    <span><?php echo esc_html($user['name'] ?? ''); ?> (<?php echo esc_html($user['email'] ?? ''); ?>)</span>
                    <button id="shopagg-logout" class="button"><?php esc_html_e('Logout', 'shopagg-app-store'); ?></button>
                </div>
            </div>

            <div class="shopagg-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store&tab=plugins')); ?>"
                   class="shopagg-market-tab <?php echo $tab === 'plugins' ? 'active' : ''; ?>">
                    <?php esc_html_e('Plugins', 'shopagg-app-store'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store&tab=themes')); ?>"
                   class="shopagg-market-tab <?php echo $tab === 'themes' ? 'active' : ''; ?>">
                    <?php esc_html_e('Themes', 'shopagg-app-store'); ?>
                </a>
            </div>

            <div class="shopagg-resource-grid" id="shopagg-resource-grid">
                <?php $this->render_resource_list($tab === 'themes' ? 'theme' : 'plugin'); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render resource list.
     */
    private function render_resource_list($type) {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('resources', ['type' => $type]);

        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            return;
        }

        $resources = isset($result['data']) ? $result['data'] : [];

        if (empty($resources)) {
            echo '<div class="shopagg-empty">';
            echo '<p>' . esc_html__('No resources found.', 'shopagg-app-store') . '</p>';
            echo '</div>';
            return;
        }

        foreach ($resources as $resource) {
            $this->render_resource_card($resource);
        }
    }

    /**
     * Render a single resource card.
     */
    private function render_resource_card($resource) {
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2);
        $detail_url = admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . $resource['id']);
        $cover = ! empty($resource['cover_image']) ? $resource['cover_image'] : SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/placeholder.png';
        ?>
        <div class="shopagg-resource-card">
            <div class="shopagg-resource-cover">
                <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($resource['name']); ?>">
            </div>
            <div class="shopagg-resource-info">
                <h3><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($resource['name']); ?></a></h3>
                <div class="shopagg-resource-meta">
                    <span class="shopagg-resource-type"><?php echo esc_html(ucfirst($resource['type'])); ?></span>
                    <span class="shopagg-resource-version">v<?php echo esc_html($resource['version']); ?></span>
                </div>
                <div class="shopagg-resource-price <?php echo $is_free ? 'free' : 'paid'; ?>">
                    <?php echo esc_html($price_label); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render resource detail page.
     */
    public function render_detail_page($resource_id) {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('resources/' . $resource_id);

        if (is_wp_error($result)) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>';
            return;
        }

        $resource = $result['resource'];
        $has_license = $result['has_license'];
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2);

        $is_plugin = $resource['type'] === 'plugin';
        $plugin_file = null;
        $is_installed = false;
        $is_active = false;

        if ($is_plugin) {
            $plugin_file = $this->get_plugin_file($resource['slug']);
            $is_installed = ! empty($plugin_file);
            $is_active = $is_installed ? $this->is_plugin_active($plugin_file) : false;
        } else {
            $is_installed = $this->is_theme_installed($resource['slug']);
            $is_active = $is_installed ? $this->is_theme_active($resource['slug']) : false;
        }

        $cover = ! empty($resource['cover_image']) ? $resource['cover_image'] : SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/placeholder.png';
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-header">
                <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store')); ?>" class="shopagg-back">
                    &larr; <?php esc_html_e('Back to Store', 'shopagg-app-store'); ?>
                </a>
            </div>

            <div class="shopagg-detail">
                <div class="shopagg-detail-cover">
                    <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($resource['name']); ?>">
                </div>
                <div class="shopagg-detail-info">
                    <h1><?php echo esc_html($resource['name']); ?></h1>
                    <div class="shopagg-detail-meta">
                        <span class="shopagg-resource-type"><?php echo esc_html(ucfirst($resource['type'])); ?></span>
                        <span class="shopagg-resource-version">v<?php echo esc_html($resource['version']); ?></span>
                        <span class="shopagg-resource-price <?php echo $is_free ? 'free' : 'paid'; ?>">
                            <?php echo esc_html($price_label); ?>
                        </span>
                    </div>

                    <div class="shopagg-detail-description">
                        <?php echo wp_kses_post($resource['description']); ?>
                    </div>

                    <div class="shopagg-detail-actions">
                        <?php if (! $is_installed && ($is_free || $has_license)) : ?>
                            <button class="button button-primary shopagg-install-btn"
                                    data-resource-id="<?php echo esc_attr($resource['id']); ?>"
                                    data-type="<?php echo esc_attr($resource['type']); ?>">
                                <?php esc_html_e('Install', 'shopagg-app-store'); ?>
                            </button>
                        <?php elseif (! $is_installed) : ?>
                            <button class="button button-primary shopagg-purchase-btn"
                                    data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                                <?php printf(esc_html__('Purchase %s', 'shopagg-app-store'), esc_html($price_label)); ?>
                            </button>
                        <?php elseif ($is_plugin && $is_active) : ?>
                            <a class="button button-secondary" href="<?php echo esc_url($this->get_plugin_deactivate_url($plugin_file)); ?>">
                                <?php esc_html_e('Deactivate', 'shopagg-app-store'); ?>
                            </a>
                        <?php elseif ($is_plugin && ! $is_active) : ?>
                            <a class="button button-primary" href="<?php echo esc_url($this->get_plugin_activate_url($plugin_file)); ?>">
                                <?php esc_html_e('Activate', 'shopagg-app-store'); ?>
                            </a>
                            <a class="button-link-delete shopagg-delete-link" href="<?php echo esc_url($this->get_plugin_delete_url($plugin_file)); ?>">
                                <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
                            </a>
                        <?php elseif (! $is_plugin && $is_active) : ?>
                            <button class="button button-secondary" disabled>
                                <?php esc_html_e('Current Theme', 'shopagg-app-store'); ?>
                            </button>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url($this->get_theme_activate_url($resource['slug'])); ?>">
                                <?php esc_html_e('Activate', 'shopagg-app-store'); ?>
                            </a>
                            <a class="button-link-delete shopagg-delete-link" href="<?php echo esc_url($this->get_theme_delete_url($resource['slug'])); ?>">
                                <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="shopagg-message" id="detail-message"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Check if a plugin is installed.
     */
    private function is_plugin_installed($slug) {
        return ! empty($this->get_plugin_file($slug));
    }

    /**
     * Find plugin file by slug.
     */
    private function get_plugin_file($slug) {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        foreach ($plugins as $path => $plugin) {
            if (strpos($path, $slug . '/') === 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a plugin is active.
     */
    private function is_plugin_active($plugin_file) {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($plugin_file);
    }

    /**
     * Check if a theme is installed.
     */
    private function is_theme_installed($slug) {
        $theme = wp_get_theme($slug);
        return $theme->exists();
    }

    /**
     * Check if a theme is active.
     */
    private function is_theme_active($slug) {
        return get_option('stylesheet') === $slug;
    }

    /**
     * Build plugin activate URL.
     */
    private function get_plugin_activate_url($plugin_file) {
        return wp_nonce_url(
            admin_url('plugins.php?action=activate&plugin=' . rawurlencode($plugin_file)),
            'activate-plugin_' . $plugin_file
        );
    }

    /**
     * Build plugin deactivate URL.
     */
    private function get_plugin_deactivate_url($plugin_file) {
        return wp_nonce_url(
            admin_url('plugins.php?action=deactivate&plugin=' . rawurlencode($plugin_file)),
            'deactivate-plugin_' . $plugin_file
        );
    }

    /**
     * Build plugin delete URL.
     */
    private function get_plugin_delete_url($plugin_file) {
        $url = add_query_arg([
            'action'  => 'delete-selected',
            'checked' => [$plugin_file],
        ], admin_url('plugins.php'));

        return wp_nonce_url($url, 'bulk-plugins');
    }

    /**
     * Build theme activate URL.
     */
    private function get_theme_activate_url($slug) {
        return wp_nonce_url(
            admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($slug)),
            'switch-theme_' . $slug
        );
    }

    /**
     * Build theme delete URL.
     */
    private function get_theme_delete_url($slug) {
        return wp_nonce_url(
            admin_url('themes.php?action=delete&stylesheet=' . rawurlencode($slug)),
            'delete-theme_' . $slug
        );
    }

    /**
     * AJAX Install handler.
     */
    public function ajax_install() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('install_plugins')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => __('Invalid resource.', 'shopagg-app-store')]);
        }

        $result = ShopAGG_App_Store_Installer::instance()->install($resource_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'        => __('Installation successful!', 'shopagg-app-store'),
            'activate_url'   => isset($result['activate_url']) ? $result['activate_url'] : '',
            'activate_label' => isset($result['activate_label']) ? $result['activate_label'] : __('Activate', 'shopagg-app-store'),
        ]);
    }

    /**
     * AJAX Purchase handler.
     */
    public function ajax_purchase() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => __('Invalid resource.', 'shopagg-app-store')]);
        }

        $api = ShopAGG_App_Store_API_Client::instance();

        // Create order
        $order_result = $api->post('orders', ['resource_id' => $resource_id]);
        if (is_wp_error($order_result)) {
            wp_send_json_error(['message' => $order_result->get_error_message()]);
        }

        $order_id = $order_result['order']['id'];

        // Simulate payment (MVP)
        $pay_result = $api->post('orders/' . $order_id . '/pay', [
            'domain' => home_url(),
        ]);

        if (is_wp_error($pay_result)) {
            wp_send_json_error(['message' => $pay_result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Purchase successful! You can now install this resource.', 'shopagg-app-store'),
            'license' => $pay_result['license'],
        ]);
    }
}
