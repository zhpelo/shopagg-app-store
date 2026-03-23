<?php
/**
 * ShopAGG App Store License Manager
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_License {

    private static $instance = null;
    private $license_cache = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if current user has a license for a resource.
     *
     * @param int $resource_id
     * @return bool
     */
    public function has_license($resource_id) {
        return shopagg_app_store_has_license($resource_id);
    }
}

/**
 * Check if the user has a license for a resource.
 *
 * @param int $resource_id
 * @return bool
 */
function shopagg_app_store_has_license($resource_id) {
    static $cache = [];

    if (isset($cache[$resource_id])) {
        return $cache[$resource_id];
    }

    if (! shopagg_app_store_is_logged_in()) {
        $cache[$resource_id] = false;
        return false;
    }

    $api = ShopAGG_App_Store_API_Client::instance();
    $result = $api->post('licenses/verify', [
        'resource_id' => $resource_id,
        'domain'      => home_url(),
    ]);

    if (is_wp_error($result)) {
        $cache[$resource_id] = false;
        return false;
    }

    $valid = isset($result['valid']) && $result['valid'] === true;
    $cache[$resource_id] = $valid;

    return $valid;
}
