<?php
/**
 * ShopAGG App Store Authentication - API Token
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Auth {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        add_action('wp_ajax_shopagg_app_store_save_token', [$this, 'ajax_save_token']);
        add_action('wp_ajax_shopagg_app_store_logout', [$this, 'ajax_logout']);
    }

    /**
     * Verify a token by calling the /me endpoint.
     */
    private function verify_token($token) {
        $url = rtrim(SHOPAGG_APP_STORE_API_URL, '/') . '/me';

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('invalid_token', __('Invalid API Token. Please check and try again.', 'shopagg-app-store'));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['id'])) {
            return new WP_Error('invalid_response', __('Unexpected API response.', 'shopagg-app-store'));
        }

        return $body;
    }

    /**
     * AJAX: Save and verify token.
     */
    public function ajax_save_token() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (empty($token)) {
            wp_send_json_error(['message' => __('Please enter your API Token.', 'shopagg-app-store')]);
        }

        $result = $this->verify_token($token);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        update_option('shopagg_app_store_access_token', $token);
        update_option('shopagg_app_store_user', [
            'id'    => sanitize_text_field($result['id']),
            'name'  => sanitize_text_field($result['name'] ?? ''),
            'email' => sanitize_email($result['email'] ?? ''),
        ]);

        wp_send_json_success(['message' => __('Connected successfully!', 'shopagg-app-store')]);
    }

    /**
     * AJAX: Logout - clear token and user data.
     */
    public function ajax_logout() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        delete_option('shopagg_app_store_access_token');
        delete_option('shopagg_app_store_user');

        wp_send_json_success(['message' => __('Disconnected.', 'shopagg-app-store')]);
    }

    /**
     * Render the login page with token input.
     */
    public function render_login_page() {
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-login-container">
                <div class="shopagg-login-box">
                    <div class="shopagg-login-header">
                        <h1><?php esc_html_e('ShopAGG App Store', 'shopagg-app-store'); ?></h1>
                        <p><?php esc_html_e('Enter your API Token to connect', 'shopagg-app-store'); ?></p>
                    </div>

                    <form id="shopagg-app-store-token-form">
                        <div class="shopagg-field">
                            <label for="shopagg-api-token"><?php esc_html_e('API Token', 'shopagg-app-store'); ?></label>
                            <input type="password" id="shopagg-api-token" name="token" placeholder="<?php esc_attr_e('Paste your API Token here', 'shopagg-app-store'); ?>" required autocomplete="off">
                        </div>
                        <div class="shopagg-field">
                            <button type="submit" class="button button-primary button-large" id="shopagg-connect-btn">
                                <?php esc_html_e('Connect', 'shopagg-app-store'); ?>
                            </button>
                        </div>
                        <div class="shopagg-message" id="shopagg-token-message"></div>
                    </form>

                    <div class="shopagg-token-help">
                        <p><?php
                            printf(
                                /* translators: %s: URL to ShopAGG dashboard */
                                esc_html__('You can generate an API Token from your %s.', 'shopagg-app-store'),
                                '<a href="' . esc_url(rtrim(SHOPAGG_APP_STORE_API_URL, '/api/shopagg-app-store/') . '/dashboard') . '" target="_blank">' .
                                esc_html__('ShopAGG Dashboard', 'shopagg-app-store') .
                                '</a>'
                            );
                        ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
