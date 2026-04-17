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
            return new WP_Error('invalid_token', 'API 令牌无效。请检查并重试。');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['id'])) {
            return new WP_Error('invalid_response', '意外的 API 响应。');
        }

        return $body;
    }

    /**
     * AJAX: Save and verify token.
     */
    public function ajax_save_token() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => '权限不足。']);
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (empty($token)) {
            wp_send_json_error(['message' => '请输入 API 令牌。']);
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

        wp_send_json_success(['message' => '连接成功！']);
    }

    /**
     * AJAX: Logout - clear token and user data.
     */
    public function ajax_logout() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        delete_option('shopagg_app_store_access_token');
        delete_option('shopagg_app_store_user');

        wp_send_json_success(['message' => '已断开连接。']);
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
                        <h1>SHOPAGG 应用商店</h1>
                        <p>先获取 API 令牌，然后再回来连接。</p>
                    </div>

                    <div class="shopagg-connect-layout">
                        <div class="shopagg-connect-guide">
                            <div class="shopagg-guide-card shopagg-guide-card-accent">
                                <h2>获取令牌很容易</h2>
                                <p>只需按照以下三个步骤操作即可。最好在新标签页中打开令牌页面，生成令牌并立即复制。</p>
                                <div class="shopagg-guide-actions">
                                    <a class="button button-primary button-large" href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        打开令牌页面
                                    </a>
                                    <a class="button button-secondary" href="<?php echo esc_url($login_url); ?>" target="_blank" rel="noopener noreferrer">
                                        登录 SHOPAGG
                                    </a>
                                </div>
                            </div>

                            <div class="shopagg-guide-steps">
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">1</span>
                                    <div>
                                        <strong>打开令牌页面</strong>
                                        <p>点击上面的 "打开令牌页面"。如果要求您登录，请先登录您的 ShopAGG 账户。</p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">2</span>
                                    <div>
                                        <strong>生成并复制令牌</strong>
                                        <p>页面打开后，点击 "生成新令牌"。这时会出现一个较长的令牌字符串。请立即复制，因为页面关闭后，将不会再显示完整的令牌。</p>
                                    </div>
                                </div>
                                <div class="shopagg-guide-step">
                                    <span class="shopagg-guide-step-num">3</span>
                                    <div>
                                        <strong>粘贴到此处并连接</strong>
                                        <p>回到此页面，将令牌粘贴到右侧字段，然后点击 "连接"，即可开始使用应用程序商店。</p>
                                    </div>
                                </div>
                            </div>

                            <div class="shopagg-guide-notes">
                                <div class="shopagg-guide-note">
                                    <strong>有用的提示</strong>
                                    <p>我们建议一个 WordPress 网站使用一个令牌。如果您管理多个网站，请为每个网站生成一个单独的令牌。</p>
                                </div>
                                <div class="shopagg-guide-note">
                                    <strong>如果找不到条目</strong>
                                    <p>打开令牌页面后，进入“API 令牌”区域，点击“生成新令牌”。</p>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-connect-form-card">
                            <div class="shopagg-connect-form-head">
                                <h2>粘贴令牌并连接</h2>
                                <p>在此处粘贴刚才复制的令牌。连接成功后，即可浏览、安装和更新应用商店资源。</p>
                            </div>

                            <form id="shopagg-app-store-token-form">
                                <div class="shopagg-field">
                                    <label for="shopagg-api-token">API 令牌</label>
                                    <input type="password" id="shopagg-api-token" name="token" placeholder="在此处粘贴你的 API 令牌" required autocomplete="off">
                                </div>
                                <div class="shopagg-field">
                                    <button type="submit" class="button button-primary button-large" id="shopagg-connect-btn">
                                        连接
                                    </button>
                                </div>
                                <div class="shopagg-message" id="shopagg-token-message"></div>
                            </form>

                            <div class="shopagg-token-help">
                                <p>不知道从哪里获取令牌？点击左侧的 "打开令牌页面"，在那里生成一个新令牌，复制并粘贴到这里。</p>
                                <p>
                                    <a href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                        再次打开令牌页面
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
