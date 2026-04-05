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
        $url = SHOPAGG_APP_STORE_DEFAULT_API_URL . 'me';

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
        $token_page_url = shopagg_app_store_get_dashboard_url();
        $login_url = SHOPAGG_APP_STORE_API_DOMAIN . '/login';
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-login-container">
                <div class="shopagg-login-box">
                    <div class="shopagg-login-header">
                        <h1><?php esc_html_e('ShopAGG App Store', 'shopagg-app-store'); ?></h1>
                        <p><?php esc_html_e('Get your API Token first, then come back here to connect it.', 'shopagg-app-store'); ?></p>
                    </div>

                    <div class="shopagg-connect-layout">
                        <div class="shopagg-connect-guide">
                            <div class="shopagg-guide-card shopagg-guide-card-accent">
                                <h2><?php esc_html_e('Getting a token is easy', 'shopagg-app-store'); ?></h2>
                                <p><?php esc_html_e('Just follow the three steps below. It is best to open the token page in a new tab, generate the token, and copy it right away.', 'shopagg-app-store'); ?></p>
                                <div class="shopagg-guide-actions">
                                    <a class="button button-primary button-large" href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('Open Token Page', 'shopagg-app-store'); ?>
                                    </a>
                                    <a class="button button-secondary" href="<?php echo esc_url($login_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('Sign In to ShopAGG', 'shopagg-app-store'); ?>
                                    </a>
                                </div>
                            </div>

                            <div class="shopagg-guide-steps">
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">1</span>
                                    <div>
                                        <strong><?php esc_html_e('Open the token page', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('Click "Open Token Page" above. If you are asked to sign in, sign in to your ShopAGG account first.', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">2</span>
                                    <div>
                                        <strong><?php esc_html_e('Generate and copy the token', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('Once the page opens, click "Generate New Token". A long token string will appear. Copy it immediately because the full token will not be shown again after the page is closed.', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">3</span>
                                    <div>
                                        <strong><?php esc_html_e('Paste it here and connect', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('Come back to this page, paste the token into the field on the right, and click "Connect" to start using the app store.', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="shopagg-guide-notes">
                                <div class="shopagg-guide-note">
                                    <strong><?php esc_html_e('Helpful tip', 'shopagg-app-store'); ?></strong>
                                    <p><?php esc_html_e('We recommend using one token for one WordPress site. If you manage multiple sites, generate a separate token for each one.', 'shopagg-app-store'); ?></p>
                                </div>
                                <div class="shopagg-guide-note">
                                    <strong><?php esc_html_e('If you cannot find the entry', 'shopagg-app-store'); ?></strong>
                                    <p><?php esc_html_e('After opening the token page, go to the "API Token" section and click "Generate New Token".', 'shopagg-app-store'); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-connect-form-card">
                            <div class="shopagg-connect-form-head">
                                <h2><?php esc_html_e('Paste your token and connect', 'shopagg-app-store'); ?></h2>
                                <p><?php esc_html_e('Paste the token you just copied here. After a successful connection, you can browse, install, and update app store resources.', 'shopagg-app-store'); ?></p>
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
                                <p><?php esc_html_e('Not sure where to get the token? Click "Open Token Page" on the left, generate a new token there, copy it, and paste it here.', 'shopagg-app-store'); ?></p>
                                <p>
                                    <a href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('Open Token Page Again', 'shopagg-app-store'); ?>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
