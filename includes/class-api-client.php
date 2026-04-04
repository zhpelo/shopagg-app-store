<?php
/**
 * ShopAGG App Store API Client
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_API_Client {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Make an API request.
     */
    public function request($endpoint, $method = 'GET', $body = [], $requires_auth = true) {
        $url = SHOPAGG_APP_STORE_DEFAULT_API_URL . ltrim($endpoint, '/');

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
        ];

        if ($requires_auth) {
            $token = shopagg_app_store_get_token();
            if (empty($token)) {
                return new WP_Error('not_logged_in', __('You must connect your API Token first.', 'shopagg-app-store'));
            }
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        if (! empty($body)) {
            if ($method === 'GET') {
                $url = add_query_arg($body, $url);
            } else {
                $args['body'] = wp_json_encode($body);
            }
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = $raw_body !== '' ? json_decode($raw_body, true) : [];

        if ($raw_body !== '' && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('API returned an invalid JSON response.', 'shopagg-app-store'));
        }

        if ($code === 401) {
            delete_option('shopagg_app_store_access_token');
            delete_option('shopagg_app_store_user');
            return new WP_Error('token_invalid', __('API Token is invalid or expired. Please reconnect.', 'shopagg-app-store'));
        }

        if ($code >= 400) {
            $message = isset($body['message']) ? $body['message'] : __('API request failed.', 'shopagg-app-store');
            return new WP_Error('api_error', $message, ['status' => $code]);
        }

        return $body;
    }

    /**
     * POST request.
     */
    public function post($endpoint, $body = [], $requires_auth = true) {
        return $this->request($endpoint, 'POST', $body, $requires_auth);
    }

    /**
     * GET request.
     */
    public function get($endpoint, $params = [], $requires_auth = true) {
        return $this->request($endpoint, 'GET', $params, $requires_auth);
    }
}
