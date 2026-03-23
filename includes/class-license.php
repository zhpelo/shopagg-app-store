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
    $resource_id = absint($resource_id);

    if (! $resource_id) {
        return false;
    }

    if (isset($cache[$resource_id])) {
        return $cache[$resource_id];
    }

    if (! shopagg_app_store_is_logged_in()) {
        $cache[$resource_id] = false;
        return false;
    }

    $transient_key = 'shopagg_license_' . $resource_id;
    $cached_result = get_transient($transient_key);

    if (is_array($cached_result) && array_key_exists('valid', $cached_result)) {
        $cache[$resource_id] = (bool) $cached_result['valid'];
        return $cache[$resource_id];
    }

    $api = ShopAGG_App_Store_API_Client::instance();
    $result = $api->post('licenses/verify', [
        'resource_id' => $resource_id,
        'domain'      => shopagg_app_store_get_site_domain(),
    ]);

    if (is_wp_error($result)) {
        $cache[$resource_id] = false;
        return false;
    }

    $valid = isset($result['valid']) && $result['valid'] === true;
    $cache[$resource_id] = $valid;
    set_transient($transient_key, ['valid' => $valid], 5 * MINUTE_IN_SECONDS);

    return $valid;
}
