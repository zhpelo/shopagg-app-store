<?php
/**
 * ShopAGG App Store Updater
 *
 * Hooks into WordPress update system to provide updates for installed resources.
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Updater {

    private static $instance = null;
    private $cached_updates = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        // Hook into plugin updates
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_plugin_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

        // Hook into theme updates
        add_filter('pre_set_site_transient_update_themes', [$this, 'check_theme_updates']);

        // Filter download URL to add auth header
        add_filter('upgrader_pre_download', [$this, 'filter_download'], 10, 3);
    }

    /**
     * Get slugs of installed resources managed by ShopAGG.
     */
    private function get_managed_slugs() {
        $slugs = get_option('shopagg_app_store_managed_resources', []);
        return is_array($slugs) ? $slugs : [];
    }

    /**
     * Register a resource as managed by ShopAGG.
     */
    public static function register_managed_resource($slug, $type, $resource_id) {
        $managed = get_option('shopagg_app_store_managed_resources', []);
        if (! is_array($managed)) {
            $managed = [];
        }
        $managed[$slug] = [
            'type'        => $type,
            'resource_id' => $resource_id,
        ];
        update_option('shopagg_app_store_managed_resources', $managed);
    }

    /**
     * Fetch update info from API.
     */
    private function fetch_updates() {
        if ($this->cached_updates !== null) {
            return $this->cached_updates;
        }

        if (! shopagg_app_store_is_logged_in()) {
            $this->cached_updates = [];
            return [];
        }

        $managed = $this->get_managed_slugs();
        if (empty($managed)) {
            $this->cached_updates = [];
            return [];
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->post('resources/check-updates', [
            'slugs' => array_keys($managed),
        ]);

        if (is_wp_error($result) || ! isset($result['updates'])) {
            $this->cached_updates = [];
            return [];
        }

        $this->cached_updates = $result['updates'];
        return $this->cached_updates;
    }

    /**
     * Check for plugin updates.
     */
    public function check_plugin_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $updates = $this->fetch_updates();
        $managed = $this->get_managed_slugs();

        foreach ($updates as $update) {
            if ($update['type'] !== 'plugin' || empty($update['update_available'])) {
                continue;
            }

            $slug = $update['slug'];
            $plugin_file = $this->find_plugin_file($slug);

            if (! $plugin_file) {
                continue;
            }

            // Compare versions
            $installed_version = isset($transient->checked[$plugin_file]) ? $transient->checked[$plugin_file] : '0.0.0';
            if (version_compare($update['version'], $installed_version, '>')) {
                $resource_id = isset($managed[$slug]) ? $managed[$slug]['resource_id'] : 0;
                $transient->response[$plugin_file] = (object) [
                    'slug'        => $slug,
                    'plugin'      => $plugin_file,
                    'new_version' => $update['version'],
                    'package'     => admin_url('admin-ajax.php?action=shopagg_app_store_download_update&resource_id=' . $resource_id . '&nonce=' . wp_create_nonce('shopagg_app_store_update')),
                    'url'         => '',
                ];
            }
        }

        return $transient;
    }

    /**
     * Check for theme updates.
     */
    public function check_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $updates = $this->fetch_updates();
        $managed = $this->get_managed_slugs();

        foreach ($updates as $update) {
            if ($update['type'] !== 'theme' || empty($update['update_available'])) {
                continue;
            }

            $slug = $update['slug'];
            $theme = wp_get_theme($slug);

            if (! $theme->exists()) {
                continue;
            }

            $installed_version = $theme->get('Version');
            if (version_compare($update['version'], $installed_version, '>')) {
                $resource_id = isset($managed[$slug]) ? $managed[$slug]['resource_id'] : 0;
                $transient->response[$slug] = [
                    'theme'       => $slug,
                    'new_version' => $update['version'],
                    'package'     => admin_url('admin-ajax.php?action=shopagg_app_store_download_update&resource_id=' . $resource_id . '&nonce=' . wp_create_nonce('shopagg_app_store_update')),
                    'url'         => '',
                ];
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the WordPress plugin details popup.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $managed = $this->get_managed_slugs();
        if (! isset($args->slug) || ! isset($managed[$args->slug])) {
            return $result;
        }

        $resource_id = $managed[$args->slug]['resource_id'];
        $api = ShopAGG_App_Store_API_Client::instance();
        $resource_result = $api->get('resources/' . $resource_id);

        if (is_wp_error($resource_result)) {
            return $result;
        }

        $resource = $resource_result['resource'];

        return (object) [
            'name'          => $resource['name'],
            'slug'          => $resource['slug'],
            'version'       => $resource['version'],
            'author'        => 'ShopAGG',
            'homepage'      => 'https://shopagg.com',
            'sections'      => [
                'description' => $resource['description'] ?? '',
            ],
        ];
    }

    /**
     * Filter download to handle update package retrieval.
     */
    public function filter_download($reply, $package, $upgrader) {
        if (strpos($package, 'shopagg_app_store_download_update') === false) {
            return $reply;
        }

        // Parse the URL to get resource_id
        $query = wp_parse_url($package, PHP_URL_QUERY);
        parse_str($query, $params);

        $resource_id = isset($params['resource_id']) ? absint($params['resource_id']) : 0;
        $nonce = isset($params['nonce']) ? $params['nonce'] : '';

        if (! wp_verify_nonce($nonce, 'shopagg_app_store_update')) {
            return new WP_Error('invalid_nonce', __('Security check failed.', 'shopagg-app-store'));
        }

        if (! $resource_id) {
            return new WP_Error('no_resource', __('Invalid resource.', 'shopagg-app-store'));
        }

        // Get download URL from API
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('download/' . $resource_id);

        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['download_url'])) {
            return new WP_Error('no_url', __('Failed to get download URL.', 'shopagg-app-store'));
        }

        // Download the file
        $tmpfile = download_url($result['download_url']);

        if (is_wp_error($tmpfile)) {
            return $tmpfile;
        }

        return $tmpfile;
    }

    /**
     * Find plugin file by slug.
     */
    private function find_plugin_file($slug) {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $file => $plugin) {
            if (strpos($file, $slug . '/') === 0) {
                return $file;
            }
        }

        return null;
    }
}

/**
 * AJAX handler for download during updates.
 */
add_action('wp_ajax_shopagg_app_store_download_update', function () {
    if (! wp_verify_nonce($_GET['nonce'] ?? '', 'shopagg_app_store_update')) {
        wp_die('Security check failed.');
    }

    $resource_id = absint($_GET['resource_id'] ?? 0);
    if (! $resource_id) {
        wp_die('Invalid resource.');
    }

    $api = ShopAGG_App_Store_API_Client::instance();
    $result = $api->get('download/' . $resource_id);

    if (is_wp_error($result) || empty($result['download_url'])) {
        wp_die('Failed to get download URL.');
    }

    wp_redirect($result['download_url']);
    exit;
});
