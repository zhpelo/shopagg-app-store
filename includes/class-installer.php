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
     * @return true|WP_Error
     */
    public function install($resource_id) {
        if (! shopagg_app_store_is_logged_in()) {
            return new WP_Error('not_logged_in', __('You must be logged in to install resources.', 'shopagg-app-store'));
        }

        $api = ShopAGG_App_Store_API_Client::instance();

        // Get resource info to determine type
        $resource_info = $api->get('resources/' . $resource_id);
        if (is_wp_error($resource_info)) {
            return $resource_info;
        }

        $resource = $resource_info['resource'];
        $type = $resource['type'];

        // Get download URL from API
        $result = $api->get('download/' . $resource_id);

        if (is_wp_error($result)) {
            return $result;
        }

        if (empty($result['download_url'])) {
            return new WP_Error('no_download_url', __('Failed to get download URL.', 'shopagg-app-store'));
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
            return new WP_Error('invalid_type', __('Invalid resource type.', 'shopagg-app-store'));
        }

        // Register for auto-updates tracking
        if ($install_result === true) {
            ShopAGG_App_Store_Updater::register_managed_resource(
                $resource['slug'],
                $resource['type'],
                $resource['id']
            );
        }

        return $install_result;
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

        $filesize = filesize($tmp_file);
        if ($filesize === 0) {
            @unlink($tmp_file);
            return new WP_Error('download_empty', __('Downloaded file is empty.', 'shopagg-app-store'));
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
     * @return true|WP_Error
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
                    ? __('Plugin installation failed.', 'shopagg-app-store')
                    : __('Theme installation failed.', 'shopagg-app-store')
            );
        }

        return true;
    }
}
