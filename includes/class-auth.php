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
                        <p><?php esc_html_e('先获取 API Token，再回来粘贴连接。', 'shopagg-app-store'); ?></p>
                    </div>

                    <div class="shopagg-connect-layout">
                        <div class="shopagg-connect-guide">
                            <div class="shopagg-guide-card shopagg-guide-card-accent">
                                <h2><?php esc_html_e('获取 Token 很简单', 'shopagg-app-store'); ?></h2>
                                <p><?php esc_html_e('按下面 3 步操作即可。建议先在新标签页打开 Token 页面，生成后马上复制回来。', 'shopagg-app-store'); ?></p>
                                <div class="shopagg-guide-actions">
                                    <a class="button button-primary button-large" href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('打开 Token 页面', 'shopagg-app-store'); ?>
                                    </a>
                                    <a class="button button-secondary" href="<?php echo esc_url($login_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('先去登录 ShopAGG', 'shopagg-app-store'); ?>
                                    </a>
                                </div>
                            </div>

                            <div class="shopagg-guide-steps">
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">1</span>
                                    <div>
                                        <strong><?php esc_html_e('打开 Token 页面', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('点击上方“打开 Token 页面”。如果系统要求登录，请先登录你的 ShopAGG 账号。', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">2</span>
                                    <div>
                                        <strong><?php esc_html_e('生成并复制 Token', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('进入页面后点击“生成新 Token”，系统会显示一串很长的字符。请立即点击复制，因为关闭页面后将无法再次看到完整 Token。', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">3</span>
                                    <div>
                                        <strong><?php esc_html_e('粘贴回来并连接', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('回到当前页面，把刚才复制的 Token 粘贴到右侧输入框，然后点击“连接”即可开始使用应用商店。', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="shopagg-guide-notes">
                                <div class="shopagg-guide-note">
                                    <strong><?php esc_html_e('温馨提示', 'shopagg-app-store'); ?></strong>
                                    <p><?php esc_html_e('一个 Token 建议只连接一个 WordPress 站点。若你有多个站点，请分别生成不同的 Token。', 'shopagg-app-store'); ?></p>
                                </div>
                                <div class="shopagg-guide-note">
                                    <strong><?php esc_html_e('如果你没有找到入口', 'shopagg-app-store'); ?></strong>
                                    <p><?php esc_html_e('打开 Token 页面后，进入“API Token”页面，点击“生成新 Token”按钮即可。', 'shopagg-app-store'); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-connect-form-card">
                            <div class="shopagg-connect-form-head">
                                <h2><?php esc_html_e('粘贴 Token 并连接', 'shopagg-app-store'); ?></h2>
                                <p><?php esc_html_e('把你刚刚复制的 Token 粘贴到这里。连接成功后，就可以浏览、安装和更新应用商店资源。', 'shopagg-app-store'); ?></p>
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
                                <p><?php esc_html_e('不知道 Token 在哪里？点击左侧“打开 Token 页面”，在新页面里生成并复制后，再回来粘贴。', 'shopagg-app-store'); ?></p>
                                <p>
                                    <a href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('重新打开 Token 页面', 'shopagg-app-store'); ?>
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
