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

        // Refresh tracked files after installs or updates finish.
        add_action('upgrader_process_complete', [$this, 'handle_upgrader_complete'], 10, 2);
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
    public static function register_managed_resource($slug, $type, $resource_id, $asset_file = '') {
        $managed = get_option('shopagg_app_store_managed_resources', []);
        if (! is_array($managed)) {
            $managed = [];
        }
        $managed[$slug] = [
            'type'        => $type,
            'resource_id' => $resource_id,
            'asset_file'  => (string) $asset_file,
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

    public function get_update_for_slug($slug) {
        $updates = $this->fetch_updates();

        foreach ($updates as $update) {
            if (isset($update['slug']) && $update['slug'] === $slug) {
                return $update;
            }
        }

        return null;
    }

    public function get_available_updates() {
        $managed = $this->get_managed_slugs();
        $available = [];

        foreach ($this->fetch_updates() as $update) {
            if (empty($update['slug']) || empty($update['update_available'])) {
                continue;
            }

            $slug = $update['slug'];
            if (empty($managed[$slug]['type'])) {
                continue;
            }

            $type = $managed[$slug]['type'];
            $asset_file = ! empty($managed[$slug]['asset_file']) ? $managed[$slug]['asset_file'] : '';
            $installed_version = $type === 'theme'
                ? $this->get_installed_theme_version($asset_file ?: $slug)
                : $this->get_installed_plugin_version($asset_file ?: $this->find_plugin_file($slug));

            if (! $installed_version || version_compare($update['version'], $installed_version, '<=')) {
                continue;
            }

            $update['installed_version'] = $installed_version;
            $update['asset_file'] = $asset_file;
            $update['update_url'] = $type === 'theme'
                ? $this->build_theme_update_url($asset_file ?: $slug)
                : $this->build_plugin_update_url($asset_file ?: $this->find_plugin_file($slug));

            $available[] = $update;
        }

        return $available;
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
            $plugin_file = ! empty($managed[$slug]['asset_file']) ? $managed[$slug]['asset_file'] : $this->find_plugin_file($slug);

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
            $stylesheet = ! empty($managed[$slug]['asset_file']) ? $managed[$slug]['asset_file'] : $slug;
            $theme = wp_get_theme($stylesheet);

            if (! $theme->exists()) {
                continue;
            }

            $installed_version = $theme->get('Version');
            if (version_compare($update['version'], $installed_version, '>')) {
                $resource_id = isset($managed[$slug]) ? $managed[$slug]['resource_id'] : 0;
                $transient->response[$stylesheet] = [
                    'theme'       => $stylesheet,
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
            'name' => $resource['name'],
            'slug' => $resource['slug'],
            'version' => $resource['version'],
            'author' => isset($resource['author']) ? $resource['author'] : 'ShopAGG',
            'author_profile' => isset($resource['author_homepage']) ? $resource['author_homepage'] : '',
            'homepage' => isset($resource['homepage']) ? $resource['homepage'] : 'https://shopagg.com',
            'requires' => isset($resource['requires']) ? $resource['requires'] : '',
            'requires_php' => isset($resource['requires_php']) ? $resource['requires_php'] : '',
            'tested' => isset($resource['tested']) ? $resource['tested'] : '',
            'last_updated' => isset($resource['last_updated']) ? $resource['last_updated'] : '',
            'sections' => isset($resource['sections']) && is_array($resource['sections'])
                ? $resource['sections']
                : [
                    'description' => isset($resource['description']) ? $resource['description'] : '',
                ],
            'banners' => isset($resource['banners']) && is_array($resource['banners']) ? $resource['banners'] : [],
            'icons' => isset($resource['icons']) && is_array($resource['icons']) ? $resource['icons'] : [],
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
        $result = $api->get('download/' . $resource_id, [
            'domain' => shopagg_app_store_get_site_domain(),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['download_url'])) {
            return new WP_Error('no_url', __('Failed to get download URL.', 'shopagg-app-store'));
        }

        // Download the file ourselves so local/private addresses work during updates too.
        $tmpfile = $this->download_package($result['download_url']);

        if (is_wp_error($tmpfile)) {
            return $tmpfile;
        }

        return $tmpfile;
    }

    public function handle_upgrader_complete($upgrader, $hook_extra) {
        if (empty($hook_extra['type']) || empty($hook_extra['action'])) {
            return;
        }

        if (! in_array($hook_extra['type'], ['plugin', 'theme'], true)) {
            return;
        }

        if (! in_array($hook_extra['action'], ['install', 'update'], true)) {
            return;
        }

        $this->refresh_managed_assets();
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

    private function refresh_managed_assets() {
        $managed = get_option('shopagg_app_store_managed_resources', []);
        if (! is_array($managed) || empty($managed)) {
            return;
        }

        foreach ($managed as $slug => $resource) {
            $type = isset($resource['type']) ? $resource['type'] : '';
            $managed[$slug]['asset_file'] = $type === 'theme'
                ? $this->find_theme_stylesheet($slug)
                : $this->find_plugin_file($slug);
        }

        update_option('shopagg_app_store_managed_resources', $managed);
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        wp_clean_themes_cache();
    }

    private function get_installed_plugin_version($plugin_file) {
        if (empty($plugin_file)) {
            return null;
        }

        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
        if (! file_exists($plugin_path)) {
            return null;
        }

        $data = get_plugin_data($plugin_path, false, false);

        return ! empty($data['Version']) ? $data['Version'] : null;
    }

    private function get_installed_theme_version($stylesheet) {
        if (empty($stylesheet)) {
            return null;
        }

        $theme = wp_get_theme($stylesheet);

        if (! $theme->exists()) {
            return null;
        }

        return $theme->get('Version');
    }

    private function build_plugin_update_url($plugin_file) {
        if (empty($plugin_file)) {
            return '';
        }

        return wp_nonce_url(
            admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin_file)),
            'upgrade-plugin_' . $plugin_file
        );
    }

    private function build_theme_update_url($stylesheet) {
        if (empty($stylesheet)) {
            return '';
        }

        return wp_nonce_url(
            admin_url('update.php?action=upgrade-theme&theme=' . rawurlencode($stylesheet)),
            'upgrade-theme_' . $stylesheet
        );
    }

    private function find_theme_stylesheet($slug) {
        $theme = wp_get_theme($slug);
        if ($theme->exists()) {
            return $theme->get_stylesheet();
        }

        $themes = wp_get_themes();
        foreach ($themes as $stylesheet => $installed_theme) {
            $template = $installed_theme->get_template();

            if ($stylesheet === $slug || $template === $slug) {
                return $stylesheet;
            }
        }

        return $slug;
    }

    /**
     * Download an update package into a temp file.
     *
     * Mirrors installer behavior so updates work against local/private origins.
     *
     * @param string $url
     * @return string|WP_Error
     */
    private function download_package($url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp_file = wp_tempnam($url);
        if (! $tmp_file) {
            return new WP_Error('tmp_file_error', __('Could not create temporary file.', 'shopagg-app-store'));
        }

        $response = wp_remote_get($url, [
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $tmp_file,
        ]);

        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($tmp_file);
            return new WP_Error(
                'download_failed',
                sprintf(__('Download failed with HTTP status %d.', 'shopagg-app-store'), $code)
            );
        }

        if (! file_exists($tmp_file) || filesize($tmp_file) === 0) {
            @unlink($tmp_file);
            return new WP_Error('download_empty', __('Downloaded file is empty.', 'shopagg-app-store'));
        }

        return $tmp_file;
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
    $result = $api->get('download/' . $resource_id, [
        'domain' => shopagg_app_store_get_site_domain(),
    ]);

    if (is_wp_error($result) || empty($result['download_url'])) {
        wp_die('Failed to get download URL.');
    }

    wp_redirect($result['download_url']);
    exit;
});
