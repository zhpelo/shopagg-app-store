<?php
/**
 * ShopAGG App Store Installer
 *
 * Handles installation of plugins and themes from ShopAGG App Store.
 */


if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Installer {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Install a resource by ID.
     *
     * @param int $resource_id
        * @return array|WP_Error
     */
    public function install($resource_id) {
        if (! shopagg_app_store_is_logged_in()) {
            return new WP_Error('not_logged_in', '您必须登录后才能安装资源。');
        }

        $api = ShopAGG_App_Store_API_Client::instance();

        // Get resource info to determine type
        $resource_info = $api->get('resources/' . $resource_id);
        if (is_wp_error($resource_info)) {
            return $resource_info;
        }

        $resource = $resource_info['resource'];

        if (shopagg_app_store_is_client_resource($resource)) {
            return new WP_Error('managed_separately', 'ShopAGG 应用商店插件通过独立渠道更新，不能在市场内部安装。');
        }

        $type = $resource['type'];

        // Get download URL from API
        $result = $api->get('download/' . $resource_id, [
            'domain' => shopagg_app_store_get_site_domain(),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['download_url'])) {
            return new WP_Error('no_download_url', '获取下载 URL 失败。');
        }

        // Download the package file ourselves using wp_remote_get()
        // This avoids wp_safe_remote_get() which blocks local/private IPs
        $tmp_file = $this->download_package($result['download_url']);
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        // Install based on type
        if ($type === 'plugin') {
            $install_result = $this->install_from_file($tmp_file, 'plugin');
        } elseif ($type === 'theme') {
            $install_result = $this->install_from_file($tmp_file, 'theme');
        } else {
            @unlink($tmp_file);
            return new WP_Error('invalid_type', '资源类型无效。');
        }

        // Register for auto-updates tracking
        if ($install_result === true) {
            $asset_file = $resource['type'] === 'plugin'
                ? $this->find_plugin_file($resource['slug'])
                : $this->find_theme_stylesheet($resource['slug']);

            ShopAGG_App_Store_Updater::register_managed_resource(
                $resource['slug'],
                $resource['type'],
                $resource['id'],
                $asset_file
            );

            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            wp_clean_themes_cache();

            return [
                'installed'       => true,
                'type'            => $resource['type'],
                'activate_url'    => $this->get_activate_url($resource['type'], $resource['slug']),
                'activate_label'  => $resource['type'] === 'plugin'
                    ? '激活插件'
                    : '激活主题',
            ];
        }

        return $install_result;
    }

    /**
     * Build activation URL for installed resource.
     *
     * @param string $type
     * @param string $slug
     * @return string
     */
    private function get_activate_url($type, $slug) {
        if ($type === 'plugin') {
            $plugin_file = $this->find_plugin_file($slug);
            if (empty($plugin_file)) {
                return '';
            }

            return wp_nonce_url(
                admin_url('plugins.php?action=activate&plugin=' . rawurlencode($plugin_file)),
                'activate-plugin_' . $plugin_file
            );
        }

        if ($type === 'theme') {
            return wp_nonce_url(
                admin_url('themes.php?action=activate&stylesheet=' . rawurlencode($slug)),
                'switch-theme_' . $slug
            );
        }

        return '';
    }

    /**
     * Find plugin main file path by slug.
     *
     * @param string $slug
     * @return string|null
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

    /**
     * Find installed theme stylesheet by slug.
     *
     * @param string $slug
     * @return string
     */
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
     * Download a package file from URL.
     *
     * Uses wp_remote_get() instead of wp_safe_remote_get() to allow
     * downloading from local/private network addresses.
     *
     * @param string $url
     * @return string|WP_Error Path to temp file on success.
     */
    private function download_package($url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $tmp_file = wp_tempnam($url);
        if (! $tmp_file) {
            return new WP_Error('tmp_file_error', '无法创建临时文件。');
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
                sprintf('下载失败，HTTP 状态为 %d。', $code)
            );
        }

        $filesize = filesize($tmp_file);
        if ($filesize === 0) {
            @unlink($tmp_file);
            return new WP_Error('download_empty', '下载的文件为空。');
        }

        return $tmp_file;
    }

    /**
     * Install a plugin or theme from a pre-downloaded local file.
     *
     * Hooks into upgrader_pre_download to provide the local file,
     * bypassing WordPress's built-in download (which uses wp_safe_remote_get).
     *
     * @param string $tmp_file Path to downloaded ZIP file.
     * @param string $type     'plugin' or 'theme'.
    * @return bool|WP_Error
     */
    private function install_from_file($tmp_file, $type) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ($type === 'plugin') {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        } else {
            require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = ($type === 'plugin')
            ? new Plugin_Upgrader($skin)
            : new Theme_Upgrader($skin);

        // Hook upgrader_pre_download to skip WP's download and use our local file
        $filter = function ($reply, $package) use ($tmp_file) {
            if ($package === 'shopagg://local-package') {
                return $tmp_file;
            }
            return $reply;
        };
        add_filter('upgrader_pre_download', $filter, 10, 2);

        $result = $upgrader->install('shopagg://local-package');

        remove_filter('upgrader_pre_download', $filter, 10);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            $errors = $skin->get_errors();
            if (is_wp_error($errors) && $errors->has_errors()) {
                return $errors;
            }
            return new WP_Error('install_failed',
                $type === 'plugin'
                    ? '插件安装失败。'
                    : '主题安装失败。'
            );
        }

        return true;
    }
}
