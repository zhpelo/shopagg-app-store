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
     * Mask the saved token before rendering it in the admin UI.
     */
    private function get_masked_token($token) {
        $token = (string) $token;

        if ($token === '') {
            return '未保存';
        }


        return substr($token, 0, 12) . str_repeat('*', 6) ;
    }

    /**
     * Render the login page with token input.
     */
    public function render_login_page() {
        $token_page_url = shopagg_app_store_get_dashboard_url();
        $login_url = SHOPAGG_APP_STORE_API_DOMAIN . '/login';
        $market_url = admin_url('admin.php?page=shopagg-app-store');
        $orders_url = admin_url('admin.php?page=shopagg-app-store&tab=orders');
        $licenses_url = admin_url('admin.php?page=shopagg-app-store&tab=licenses');
        $updates_url = admin_url('admin.php?page=shopagg-app-store&tab=updates');
        $is_connected = shopagg_app_store_is_logged_in();
        $token = shopagg_app_store_get_token();
        $user = shopagg_app_store_get_user();
        $masked_token = $this->get_masked_token($token);
        $site_domain = shopagg_app_store_get_site_domain();
        $top_nav = [
            [
                'label' => '资源库',
                'url' => $market_url,
                'active' => false,
            ],
            [
                'label' => '更新',
                'url' => $updates_url,
                'active' => false,
                'disabled' => ! $is_connected,
            ],
            [
                'label' => '订单',
                'url' => $orders_url,
                'active' => false,
                'disabled' => ! $is_connected,
            ],
            [
                'label' => 'API Token',
                'url' => shopagg_app_store_get_connect_url(),
                'active' => true,
            ],
        ];
        $side_nav = [
            [
                'title' => '连接管理',
                'items' => [
                    [
                        'label' => $is_connected ? '当前连接' : '连接指南',
                        'url' => '#shopagg-connect-main',
                        'active' => true,
                    ],
                    [
                        'label' => 'API Token 页面',
                        'url' => $token_page_url,
                        'target' => '_blank',
                    ],
                    [
                        'label' => '登录 ShopAGG',
                        'url' => $login_url,
                        'target' => '_blank',
                    ],
                ],
            ],
            [
                'title' => '业务入口',
                'items' => [
                    [
                        'label' => '资源库',
                        'url' => $market_url,
                    ],
                    [
                        'label' => '订单管理',
                        'url' => $orders_url,
                        'disabled' => ! $is_connected,
                    ],
                    [
                        'label' => '许可证',
                        'url' => $licenses_url,
                        'disabled' => ! $is_connected,
                    ],
                ],
            ],
        ];

        shopagg_app_store_render_admin_shell_start([
            'title' => 'API Token 管理',
            'description' => $is_connected
                ? '当前站点已经连接 API Token，可以在这里查看连接状态、解除连接或更换 Token。'
                : '先完成 Token 连接，后续才能在后台直接完成购买、安装、授权和更新。',
            'top_nav' => $top_nav,
            'side_nav' => $side_nav,
        ]);
        ?>
        <div class="shopagg-login-container" id="shopagg-connect-main">
            <div class="shopagg-login-box">
                    <div class="shopagg-login-header">
                        <h1>SHOPAGG 应用商店</h1>
                        <?php if ($is_connected) : ?>
                            <p>当前站点已经连接 API Token。你可以直接查看当前 Token 信息、解除连接、切换 Token，并进入业务功能页面。</p>
                        <?php else : ?>
                            <p>先获取API Token，然后填入此处即可连接。</p>
                        <?php endif; ?>
                    </div>

                    <div class="shopagg-connect-layout">
                        <div class="shopagg-connect-guide">
                            <?php if ($is_connected) : ?>
                                <div class="shopagg-guide-card shopagg-guide-card-accent">
                                    <h2>当前 API Token 已连接</h2>
                                    <p>这个站点已经可以直接使用 SHOPAGG 应用商店的浏览、安装、授权和更新能力。如果你要切换账号，可以先更换或解除当前 Token。</p>
                                    <div class="shopagg-guide-actions">
                                        <a class="button button-primary button-large" href="<?php echo esc_url($market_url); ?>">
                                            进入资源库
                                        </a>
                                        <a class="button button-secondary" href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                            打开 API Token 页面
                                        </a>
                                    </div>
                                </div>

                                <div class="shopagg-connect-features">
                                    <a class="shopagg-connect-feature" href="<?php echo esc_url($market_url); ?>">
                                        <strong>资源库</strong>
                                        <span>浏览插件和主题，直接进入详情、安装和购买流程。</span>
                                    </a>
                                    <a class="shopagg-connect-feature" href="<?php echo esc_url($orders_url); ?>">
                                        <strong>订单管理</strong>
                                        <span>查看订单状态、付款结果，以及历史购买记录。</span>
                                    </a>
                                    <a class="shopagg-connect-feature" href="<?php echo esc_url($licenses_url); ?>">
                                        <strong>许可证</strong>
                                        <span>查看当前站点已授权资源和域名绑定情况。</span>
                                    </a>
                                    <a class="shopagg-connect-feature" href="<?php echo esc_url($updates_url); ?>">
                                        <strong>更新中心</strong>
                                        <span>集中处理通过 SHOPAGG 安装的插件和主题更新。</span>
                                    </a>
                                </div>

                                <div class="shopagg-guide-notes">
                                    <div class="shopagg-guide-note">
                                        <strong>当前站点</strong>
                                        <p><?php echo esc_html($site_domain); ?></p>
                                    </div>
                                    <div class="shopagg-guide-note">
                                        <strong>使用建议</strong>
                                        <p>建议一个 WordPress 网站使用一个独立 Token，便于按站点管理授权、更新和权限范围。</p>
                                    </div>
                                </div>
                            <?php else : ?>
                                <div class="shopagg-guide-card shopagg-guide-card-accent">
                                    <h2>获取令牌很容易</h2>
                                    <p>只需按照以下三个步骤操作即可。最好在新标签页中打开令牌页面，生成令牌并立即复制。</p>
                                    <div class="shopagg-guide-actions">
                                        <a class="button button-secondary" href="<?php echo esc_url($login_url); ?>" target="_blank" rel="noopener noreferrer">
                                            1. 登录 SHOPAGG 网站
                                        </a>

                                        <a class="button button-primary button-large" href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                            2. 获取 API Token
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
                                        <strong>建议</strong>
                                        <p>我们建议一个 WordPress 网站使用一个令牌。如果您管理多个网站，请为每个网站生成一个单独的令牌。</p>
                                    </div>
                                    <div class="shopagg-guide-note">
                                        <strong>如何生成token？</strong>
                                        <p>打开shopagg网站后，进入“API Token”页面，点击“生成新的Token”。</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="shopagg-connect-form-card">
                            <?php if ($is_connected) : ?>
                                <div class="shopagg-connect-form-head">
                                    <h2>当前 API Token 信息</h2>
                                    <p>当前 Token 已生效。你可以继续使用业务功能，也可以在这里解除或更换当前 API Token。</p>
                                </div>

                                <div class="shopagg-token-status-card">
                                    <div class="shopagg-token-status-row">
                                        <span>连接状态</span>
                                        <strong>已连接</strong>
                                    </div>
                                    <div class="shopagg-token-status-row">
                                        <span>当前站点</span>
                                        <strong><?php echo esc_html($site_domain); ?></strong>
                                    </div>
                                    <div class="shopagg-token-status-row">
                                        <span>账号名称</span>
                                        <strong><?php echo esc_html($user['name'] ?? '未获取'); ?></strong>
                                    </div>
                                    <div class="shopagg-token-status-row">
                                        <span>账号邮箱</span>
                                        <strong><?php echo esc_html($user['email'] ?? '未获取'); ?></strong>
                                    </div>
                                    <div class="shopagg-token-status-row shopagg-token-status-row-token">
                                        <span>当前 Token</span>
                                        <strong><?php echo esc_html($masked_token); ?></strong>
                                    </div>
                                </div>

                                <div class="shopagg-token-actions">
                                    <button type="button" class="button button-secondary" id="shopagg-logout">解除API Token</button>
                                    <button type="button" class="button button-primary" id="shopagg-toggle-token-form" data-show-text="更换API Token" data-hide-text="取消更换">更换API Token</button>
                                    <a class="button" href="<?php echo esc_url($market_url); ?>">进入业务功能</a>
                                </div>

                                <div class="shopagg-message" id="shopagg-token-message"></div>

                                <div class="shopagg-token-replace" id="shopagg-token-replace" hidden>
                                    <div class="shopagg-token-replace-head">
                                        <h3>更换 API Token</h3>
                                        <p>输入新的 API Token 并保存。保存成功后，当前站点会立即切换到新的连接信息。</p>
                                    </div>

                                    <form id="shopagg-app-store-token-form">
                                        <div class="shopagg-field">
                                            <label for="shopagg-api-token">新的 API Token</label>
                                            <input type="password" id="shopagg-api-token" name="token" placeholder="在此处粘贴新的 API Token" required autocomplete="off">
                                        </div>
                                        <div class="shopagg-field">
                                            <button type="submit" class="button button-primary button-large" id="shopagg-connect-btn">
                                                保存并更换
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div class="shopagg-token-help">
                                    <p>如果你需要重新生成 Token，请去 SHOPAGG 网站的 API Token 页面操作。出于安全考虑，这里只展示脱敏后的 Token 信息。</p>
                                    <p>
                                        <a href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                            打开 API Token 页面
                                        </a>
                                    </p>
                                </div>
                            <?php else : ?>
                                <div class="shopagg-connect-form-head">
                                    <h2>粘贴令牌并连接</h2>
                                    <p>在此处粘贴刚才复制的令牌。连接成功后，即可浏览、安装和更新应用商店资源。</p>
                                </div>

                                <form id="shopagg-app-store-token-form">
                                    <div class="shopagg-field">
                                        <label for="shopagg-api-token">API Token</label>
                                        <input type="password" id="shopagg-api-token" name="token" placeholder="在此处粘贴你的 API Token" required autocomplete="off">
                                    </div>
                                    <div class="shopagg-field">
                                        <button type="submit" class="button button-primary button-large" id="shopagg-connect-btn">
                                            立即连接
                                        </button>
                                    </div>
                                    <div class="shopagg-message" id="shopagg-token-message"></div>
                                </form>

                                <div class="shopagg-token-help">
                                    <p>不知道从哪里获取令牌？点击左侧的 "打开 API Token"，在那里生成一个新的Token，复制并粘贴到这里。</p>
                                    <p>
                                        <a href="<?php echo esc_url($token_page_url); ?>" target="_blank" rel="noopener noreferrer">
                                            再次打开API Token页面
                                        </a>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
            </div>
        </div>
        <?php
        shopagg_app_store_render_admin_shell_end();
    }
}
