<?php
/**
 * ShopAGG App Store Market
 */

if (! defined('ABSPATH')) {
    exit;
}

class ShopAGG_App_Store_Market {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }

        return self::$instance;
    }

    private function init() {
        add_action('wp_ajax_shopagg_app_store_install', [$this, 'ajax_install']);
        add_action('wp_ajax_shopagg_app_store_purchase', [$this, 'ajax_purchase']);
        add_action('wp_ajax_shopagg_app_store_pay', [$this, 'ajax_pay']);
        add_action('wp_ajax_shopagg_app_store_order_status', [$this, 'ajax_order_status']);
        add_action('wp_ajax_shopagg_app_store_toggle_resource', [$this, 'ajax_toggle_resource']);
    }

    public function render_market_page($tab = 'browse') {
        $state = $this->resolve_market_state($tab);
        $user = shopagg_app_store_get_user();
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-shell">
                <div class="shopagg-hero">
                    <div>
                        <p class="shopagg-eyebrow">ShopAGG Marketplace</p>
                        <h1><?php esc_html_e('ShopAGG App Store', 'shopagg-app-store'); ?></h1>
                        <p class="shopagg-hero-text"><?php esc_html_e('集中安装、购买、授权和管理你的 WordPress 插件与主题资源。', 'shopagg-app-store'); ?></p>
                    </div>
                    <div class="shopagg-hero-user">
                        <div class="shopagg-hero-user-card">
                            <span class="shopagg-hero-user-label"><?php esc_html_e('Current Account', 'shopagg-app-store'); ?></span>
                            <strong><?php echo esc_html($user['name'] ?? ''); ?></strong>
                            <span><?php echo esc_html($user['email'] ?? ''); ?></span>
                        </div>
                        <button id="shopagg-logout" class="button button-secondary"><?php esc_html_e('Logout', 'shopagg-app-store'); ?></button>
                    </div>
                </div>

                <div class="shopagg-overview">
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Resources', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html(count($state['resources'])); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Orders', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html(count($state['orders'])); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Licenses', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html(count($state['licenses'])); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Updates', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html(count($state['updates'])); ?></strong>
                    </div>
                </div>

                <div class="shopagg-nav">
                    <?php foreach ($this->get_market_tabs() as $key => $label) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store&tab=' . $key)); ?>"
                           class="shopagg-nav-link <?php echo $state['tab'] === $key ? 'active' : ''; ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($state['tab'] === 'orders') : ?>
                    <?php $this->render_orders_panel($state['orders']); ?>
                <?php elseif ($state['tab'] === 'licenses') : ?>
                    <?php $this->render_licenses_panel($state['licenses']); ?>
                <?php elseif ($state['tab'] === 'updates') : ?>
                    <?php $this->render_updates_panel($state['updates']); ?>
                <?php else : ?>
                    <?php $this->render_browse_panel($state['resources'], $state['preset_type']); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_detail_page($resource_id) {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('resources/' . $resource_id);

        if (is_wp_error($result)) {
            echo '<div class="wrap shopagg-app-store-wrap"><div class="shopagg-panel-message error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>';
            return;
        }

        $resource = $result['resource'];
        $has_license = ! empty($result['has_license']);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2);

        $status = $this->get_resource_install_state($resource);
        $cover = ! empty($resource['cover_image']) ? $resource['cover_image'] : SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/placeholder.png';
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-shell">
                <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store')); ?>" class="shopagg-back-link">
                    &larr; <?php esc_html_e('Back to Store', 'shopagg-app-store'); ?>
                </a>

                <div class="shopagg-detail-card">
                    <div class="shopagg-detail-cover">
                        <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($resource['name']); ?>">
                    </div>
                    <div class="shopagg-detail-main">
                        <div class="shopagg-detail-heading">
                            <div>
                                <h1><?php echo esc_html($resource['name']); ?></h1>
                                <div class="shopagg-detail-badges">
                                    <span class="shopagg-chip"><?php echo esc_html($resource['type'] === 'theme' ? __('Theme', 'shopagg-app-store') : __('Plugin', 'shopagg-app-store')); ?></span>
                                    <span class="shopagg-chip">v<?php echo esc_html($resource['version']); ?></span>
                                    <span class="shopagg-chip <?php echo $is_free ? 'free' : 'paid'; ?>"><?php echo esc_html($price_label); ?></span>
                                    <?php if ($has_license && ! $is_free) : ?>
                                        <span class="shopagg-chip owned"><?php esc_html_e('Licensed', 'shopagg-app-store'); ?></span>
                                    <?php endif; ?>
                                    <?php if (! empty($status['update'])) : ?>
                                        <span class="shopagg-chip update"><?php echo esc_html(sprintf(__('New v%s', 'shopagg-app-store'), $status['update']['version'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-detail-description">
                            <?php echo wp_kses_post($resource['description']); ?>
                        </div>

                        <div class="shopagg-detail-meta-grid">
                            <div><span><?php esc_html_e('Requires WordPress', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['requires'] ?: '-'); ?></strong></div>
                            <div><span><?php esc_html_e('Requires PHP', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['requires_php'] ?: '-'); ?></strong></div>
                            <div><span><?php esc_html_e('Tested Up To', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['tested'] ?: '-'); ?></strong></div>
                            <div><span><?php esc_html_e('Bound Domain', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['bound_domain'] ?? '-'); ?></strong></div>
                        </div>

                        <?php if (! empty($status['update'])) : ?>
                            <div class="shopagg-update-banner">
                                <strong><?php esc_html_e('Update available', 'shopagg-app-store'); ?></strong>
                                <span>
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('Installed v%s, latest v%s.', 'shopagg-app-store'),
                                            $status['installed_version'] ?: '0.0.0',
                                            $status['update']['version']
                                        )
                                    );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="shopagg-detail-actions">
                            <?php $this->render_detail_action_buttons($resource, $status, $has_license, $is_free); ?>
                        </div>

                        <div class="shopagg-message" id="detail-message"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function resolve_market_state($tab) {
        $tab = sanitize_key($tab);
        $legacy_map = [
            'plugins' => 'plugin',
            'themes' => 'theme',
        ];

        $preset_type = isset($legacy_map[$tab]) ? $legacy_map[$tab] : 'all';
        $normalized_tab = in_array($tab, ['browse', 'updates', 'orders', 'licenses'], true) ? $tab : 'browse';

        return [
            'tab' => $normalized_tab,
            'preset_type' => $preset_type,
            'resources' => $this->fetch_resources(),
            'orders' => $this->fetch_orders(),
            'licenses' => $this->fetch_licenses(),
            'updates' => $this->fetch_available_updates(),
        ];
    }

    private function get_market_tabs() {
        return [
            'browse' => __('Browse', 'shopagg-app-store'),
            'updates' => __('Updates', 'shopagg-app-store'),
            'orders' => __('Orders', 'shopagg-app-store'),
            'licenses' => __('Licenses', 'shopagg-app-store'),
        ];
    }

    private function fetch_resources() {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('resources');

        if (is_wp_error($result)) {
            return [];
        }

        return isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    }

    private function fetch_orders() {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('orders');

        if (is_wp_error($result)) {
            return [];
        }

        return isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    }

    private function fetch_licenses() {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('licenses');

        if (is_wp_error($result)) {
            return [];
        }

        return isset($result['licenses']) && is_array($result['licenses']) ? $result['licenses'] : [];
    }

    private function fetch_available_updates() {
        return ShopAGG_App_Store_Updater::instance()->get_available_updates();
    }

    private function render_browse_panel($resources, $preset_type) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php esc_html_e('Resource Library', 'shopagg-app-store'); ?></h2>
                    <p><?php esc_html_e('筛选并管理你需要的插件和主题资源。', 'shopagg-app-store'); ?></p>
                </div>
                <div class="shopagg-filter-bar">
                    <input type="search" id="shopagg-market-search" class="shopagg-filter-input" placeholder="<?php esc_attr_e('Search by name or slug...', 'shopagg-app-store'); ?>">
                    <select id="shopagg-market-type" class="shopagg-filter-select">
                        <option value="all"><?php esc_html_e('All Types', 'shopagg-app-store'); ?></option>
                        <option value="plugin" <?php selected($preset_type, 'plugin'); ?>><?php esc_html_e('Plugins', 'shopagg-app-store'); ?></option>
                        <option value="theme" <?php selected($preset_type, 'theme'); ?>><?php esc_html_e('Themes', 'shopagg-app-store'); ?></option>
                    </select>
                    <select id="shopagg-market-price" class="shopagg-filter-select">
                        <option value="all"><?php esc_html_e('All Prices', 'shopagg-app-store'); ?></option>
                        <option value="free"><?php esc_html_e('Free', 'shopagg-app-store'); ?></option>
                        <option value="paid"><?php esc_html_e('Paid', 'shopagg-app-store'); ?></option>
                    </select>
                </div>
            </div>

            <div class="shopagg-resource-grid" id="shopagg-resource-grid">
                <?php if (empty($resources)) : ?>
                    <div class="shopagg-empty-state">
                        <h3><?php esc_html_e('No resources found.', 'shopagg-app-store'); ?></h3>
                    </div>
                <?php else : ?>
                    <?php foreach ($resources as $resource) : ?>
                        <?php $this->render_resource_card($resource); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="shopagg-empty-state shopagg-filter-empty" id="shopagg-filter-empty" style="display:none;">
                <h3><?php esc_html_e('No resources match the current filters.', 'shopagg-app-store'); ?></h3>
                <p><?php esc_html_e('Try changing the type, price or search keywords.', 'shopagg-app-store'); ?></p>
            </div>
        </div>
        <?php
    }

    private function render_orders_panel($orders) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php esc_html_e('Order History', 'shopagg-app-store'); ?></h2>
                    <p><?php esc_html_e('查看你的应用商店订单状态和支付记录。', 'shopagg-app-store'); ?></p>
                </div>
            </div>

            <?php if (empty($orders)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php esc_html_e('No orders yet.', 'shopagg-app-store'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-table">
                    <div class="shopagg-table-head">
                        <span><?php esc_html_e('Resource', 'shopagg-app-store'); ?></span>
                        <span><?php esc_html_e('Amount', 'shopagg-app-store'); ?></span>
                        <span><?php esc_html_e('Status', 'shopagg-app-store'); ?></span>
                        <span><?php esc_html_e('Created', 'shopagg-app-store'); ?></span>
                    </div>
                    <?php foreach ($orders as $order) : ?>
                        <?php
                        $resource = isset($order['app_store_resource']) ? $order['app_store_resource'] : [];
                        $status = isset($order['status']) ? $order['status'] : '';
                        ?>
                        <div class="shopagg-table-row">
                            <span>
                                <strong><?php echo esc_html($resource['name'] ?? __('Unknown Resource', 'shopagg-app-store')); ?></strong>
                                <small>#<?php echo esc_html($order['id'] ?? ''); ?></small>
                            </span>
                            <span>¥<?php echo esc_html(number_format((float) ($order['amount'] ?? 0), 2)); ?></span>
                            <span><em class="shopagg-status-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html($this->format_order_status($status)); ?></em></span>
                            <span><?php echo esc_html($this->format_datetime(isset($order['created_at']) ? $order['created_at'] : '')); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_licenses_panel($licenses) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php esc_html_e('License Records', 'shopagg-app-store'); ?></h2>
                    <p><?php esc_html_e('查看已授权资源以及当前站点绑定的域名。', 'shopagg-app-store'); ?></p>
                </div>
            </div>

            <?php if (empty($licenses)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php esc_html_e('No licenses yet.', 'shopagg-app-store'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-license-list">
                    <?php foreach ($licenses as $license) : ?>
                        <?php $resource = isset($license['resource']) ? $license['resource'] : []; ?>
                        <div class="shopagg-license-card">
                            <div>
                                <h3><?php echo esc_html($resource['name'] ?? __('Unknown Resource', 'shopagg-app-store')); ?></h3>
                                <p><?php echo esc_html($resource['type'] === 'theme' ? __('Theme', 'shopagg-app-store') : __('Plugin', 'shopagg-app-store')); ?> · v<?php echo esc_html($resource['version'] ?? '-'); ?></p>
                            </div>
                            <div class="shopagg-license-meta">
                                <span><strong><?php esc_html_e('Domain', 'shopagg-app-store'); ?>:</strong> <?php echo esc_html(! empty($license['domain']) ? $license['domain'] : __('Not bound', 'shopagg-app-store')); ?></span>
                                <span><strong><?php esc_html_e('Granted', 'shopagg-app-store'); ?>:</strong> <?php echo esc_html($this->format_datetime(isset($license['created_at']) ? $license['created_at'] : '')); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_updates_panel($updates) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php esc_html_e('Available Updates', 'shopagg-app-store'); ?></h2>
                    <p><?php esc_html_e('集中查看并更新通过 ShopAGG 安装的插件和主题版本。', 'shopagg-app-store'); ?></p>
                </div>
            </div>

            <?php if (empty($updates)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php esc_html_e('Everything is up to date.', 'shopagg-app-store'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-update-list">
                    <?php foreach ($updates as $update) : ?>
                        <div class="shopagg-update-card">
                            <div>
                                <div class="shopagg-update-header">
                                    <h3><?php echo esc_html($update['name'] ?? $update['slug']); ?></h3>
                                    <span class="shopagg-chip"><?php echo esc_html($update['type'] === 'theme' ? __('Theme', 'shopagg-app-store') : __('Plugin', 'shopagg-app-store')); ?></span>
                                </div>
                                <p class="shopagg-update-summary">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            __('Installed v%s, latest v%s', 'shopagg-app-store'),
                                            $update['installed_version'] ?? '0.0.0',
                                            $update['version']
                                        )
                                    );
                                    ?>
                                </p>
                                <?php if (! empty($update['sections']['changelog'])) : ?>
                                    <div class="shopagg-update-changelog"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($update['sections']['changelog']), 28)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="shopagg-update-actions">
                                <a class="button button-primary" href="<?php echo esc_url($update['update_url']); ?>">
                                    <?php esc_html_e('Update Now', 'shopagg-app-store'); ?>
                                </a>
                                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . absint($update['id']))); ?>">
                                    <?php esc_html_e('View Details', 'shopagg-app-store'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_resource_card($resource) {
        $status = $this->get_resource_install_state($resource);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2);
        $detail_url = admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . absint($resource['id']));
        $cover = ! empty($resource['cover_image']) ? $resource['cover_image'] : SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/placeholder.png';
        $search_text = strtolower(trim(($resource['name'] ?? '') . ' ' . ($resource['slug'] ?? '') . ' ' . wp_strip_all_tags($resource['description'] ?? '')));
        ?>
        <article class="shopagg-resource-card <?php echo ! empty($status['update']) ? 'has-update' : ''; ?>"
                 data-type="<?php echo esc_attr($resource['type']); ?>"
                 data-price="<?php echo esc_attr($is_free ? 'free' : 'paid'); ?>"
                 data-search="<?php echo esc_attr($search_text); ?>">
            <div class="shopagg-resource-cover">
                <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($resource['name']); ?>">
            </div>
            <div class="shopagg-resource-info">
                <div class="shopagg-resource-topline">
                    <span class="shopagg-resource-type"><?php echo esc_html($resource['type'] === 'theme' ? __('Theme', 'shopagg-app-store') : __('Plugin', 'shopagg-app-store')); ?></span>
                    <span class="shopagg-resource-version">v<?php echo esc_html($resource['version']); ?></span>
                </div>
                <h3><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($resource['name']); ?></a></h3>
                <p class="shopagg-resource-summary"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($resource['short_description'] ?? $resource['description'] ?? ''), 18)); ?></p>
                <div class="shopagg-resource-footer">
                    <span class="shopagg-resource-price <?php echo $is_free ? 'free' : 'paid'; ?>"><?php echo esc_html($price_label); ?></span>
                    <div class="shopagg-resource-flags">
                        <?php if (! empty($resource['has_license'])) : ?>
                            <span class="shopagg-flag owned"><?php esc_html_e('Owned', 'shopagg-app-store'); ?></span>
                        <?php endif; ?>
                        <?php if (! empty($status['installed'])) : ?>
                            <span class="shopagg-flag installed"><?php echo esc_html(! empty($status['active']) ? __('Active', 'shopagg-app-store') : __('Installed', 'shopagg-app-store')); ?></span>
                        <?php endif; ?>
                        <?php if (! empty($status['update'])) : ?>
                            <span class="shopagg-flag update"><?php echo esc_html(sprintf(__('Update v%s', 'shopagg-app-store'), $status['update']['version'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a class="button button-primary shopagg-card-btn" href="<?php echo esc_url($detail_url); ?>">
                    <?php esc_html_e('View Details', 'shopagg-app-store'); ?>
                </a>
            </div>
        </article>
        <?php
    }

    private function render_detail_action_buttons($resource, $status, $has_license, $is_free) {
        if (! empty($status['update']['update_url'])) {
            ?>
            <a class="button button-primary" href="<?php echo esc_url($status['update']['update_url']); ?>">
                <?php echo esc_html(sprintf(__('Update to v%s', 'shopagg-app-store'), $status['update']['version'])); ?>
            </a>
            <?php
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            ?>
            <button class="button button-primary shopagg-install-btn"
                    data-resource-id="<?php echo esc_attr($resource['id']); ?>"
                    data-type="<?php echo esc_attr($resource['type']); ?>">
                <?php esc_html_e('Install', 'shopagg-app-store'); ?>
            </button>
            <?php
            return;
        }

        if (! $status['installed']) {
            ?>
            <button class="button button-primary shopagg-purchase-btn"
                    data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                <?php printf(esc_html__('Purchase %s', 'shopagg-app-store'), esc_html($is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2))); ?>
            </button>
            <?php
            return;
        }

        if ($resource['type'] === 'plugin') {
            if ($status['active']) {
                ?>
                <button class="button button-secondary shopagg-toggle-resource-btn"
                        data-resource-type="plugin"
                        data-toggle-action="deactivate"
                        data-target="<?php echo esc_attr($status['target']); ?>">
                    <?php esc_html_e('Deactivate', 'shopagg-app-store'); ?>
                </button>
                <?php
            } else {
                ?>
                <button class="button button-primary shopagg-toggle-resource-btn"
                        data-resource-type="plugin"
                        data-toggle-action="activate"
                        data-target="<?php echo esc_attr($status['target']); ?>">
                    <?php esc_html_e('Activate', 'shopagg-app-store'); ?>
                </button>
                <?php
            }

            ?>
            <a class="button-link-delete shopagg-delete-link" href="<?php echo esc_url($this->get_plugin_delete_url($status['target'])); ?>">
                <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
            </a>
            <?php
            return;
        }

        if ($status['active']) {
            ?>
            <button class="button button-secondary" disabled>
                <?php esc_html_e('Current Theme', 'shopagg-app-store'); ?>
            </button>
            <?php
            return;
        }

        ?>
        <button class="button button-primary shopagg-toggle-resource-btn"
                data-resource-type="theme"
                data-toggle-action="activate"
                data-target="<?php echo esc_attr($status['target']); ?>">
            <?php esc_html_e('Activate', 'shopagg-app-store'); ?>
        </button>
        <a class="button-link-delete shopagg-delete-link" href="<?php echo esc_url($this->get_theme_delete_url($status['target'])); ?>">
            <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
        </a>
        <?php
    }

    private function get_resource_install_state($resource) {
        $update = ShopAGG_App_Store_Updater::instance()->get_update_for_slug($resource['slug']);
        $is_plugin = isset($resource['type']) && $resource['type'] === 'plugin';

        if ($is_plugin) {
            $plugin_file = $this->get_plugin_file($resource['slug']);
            $installed_version = $plugin_file ? $this->get_plugin_version($plugin_file) : null;
            $update_url = $plugin_file ? wp_nonce_url(
                admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode($plugin_file)),
                'upgrade-plugin_' . $plugin_file
            ) : '';

            return [
                'installed' => ! empty($plugin_file),
                'active' => ! empty($plugin_file) ? $this->is_plugin_active($plugin_file) : false,
                'target' => $plugin_file,
                'installed_version' => $installed_version,
                'update' => (! empty($update['update_available']) && $installed_version && version_compare($update['version'], $installed_version, '>'))
                    ? array_merge($update, ['update_url' => $update_url])
                    : null,
            ];
        }

        $stylesheet = $this->get_theme_stylesheet($resource['slug']);
        $installed = ! empty($stylesheet);
        $installed_version = $installed ? $this->get_theme_version($stylesheet) : null;
        $update_url = $installed ? wp_nonce_url(
            admin_url('update.php?action=upgrade-theme&theme=' . rawurlencode($stylesheet)),
            'upgrade-theme_' . $stylesheet
        ) : '';

        return [
            'installed' => $installed,
            'active' => $installed ? $this->is_theme_active($stylesheet) : false,
            'target' => $stylesheet ?: $resource['slug'],
            'installed_version' => $installed_version,
            'update' => (! empty($update['update_available']) && $installed_version && version_compare($update['version'], $installed_version, '>'))
                ? array_merge($update, ['update_url' => $update_url])
                : null,
        ];
    }

    private function format_order_status($status) {
        $labels = [
            'pending' => __('Pending', 'shopagg-app-store'),
            'paid' => __('Paid', 'shopagg-app-store'),
            'processing' => __('Processing', 'shopagg-app-store'),
            'completed' => __('Completed', 'shopagg-app-store'),
            'failed' => __('Failed', 'shopagg-app-store'),
            'cancelled' => __('Cancelled', 'shopagg-app-store'),
        ];

        return isset($labels[$status]) ? $labels[$status] : ucfirst((string) $status);
    }

    private function format_datetime($value) {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp ? wp_date('Y-m-d H:i', $timestamp) : (string) $value;
    }

    private function get_plugin_file($slug) {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        foreach ($plugins as $path => $plugin) {
            if (strpos($path, $slug . '/') === 0) {
                return $path;
            }
        }

        return null;
    }

    private function is_plugin_active($plugin_file) {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($plugin_file);
    }

    private function get_plugin_version($plugin_file) {
        if (empty($plugin_file)) {
            return null;
        }

        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
        if (! file_exists($plugin_path)) {
            return null;
        }

        $data = get_plugin_data($plugin_path, false, false);

        return ! empty($data['Version']) ? $data['Version'] : null;
    }

    private function get_theme_stylesheet($slug) {
        $theme = wp_get_theme($slug);
        if ($theme->exists()) {
            return $theme->get_stylesheet();
        }

        $themes = wp_get_themes();
        foreach ($themes as $stylesheet => $installed_theme) {
            if ($stylesheet === $slug || $installed_theme->get_template() === $slug) {
                return $stylesheet;
            }
        }

        return null;
    }

    private function is_theme_installed($slug) {
        return ! empty($this->get_theme_stylesheet($slug));
    }

    private function is_theme_active($slug) {
        return get_option('stylesheet') === $slug;
    }

    private function get_theme_version($slug) {
        $theme = wp_get_theme($slug);

        return $theme->exists() ? $theme->get('Version') : null;
    }

    private function get_plugin_delete_url($plugin_file) {
        $url = add_query_arg([
            'action' => 'delete-selected',
            'checked' => [$plugin_file],
        ], admin_url('plugins.php'));

        return wp_nonce_url($url, 'bulk-plugins');
    }

    private function get_theme_delete_url($slug) {
        return wp_nonce_url(
            admin_url('themes.php?action=delete&stylesheet=' . rawurlencode($slug)),
            'delete-theme_' . $slug
        );
    }

    public function ajax_install() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('install_plugins')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => __('Invalid resource.', 'shopagg-app-store')]);
        }

        $result = ShopAGG_App_Store_Installer::instance()->install($resource_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        shopagg_app_store_forget_license_cache($resource_id);

        wp_send_json_success([
            'message' => __('Installation successful! Refreshing resource status...', 'shopagg-app-store'),
        ]);
    }

    public function ajax_purchase() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => __('Invalid resource.', 'shopagg-app-store')]);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $order_result = $api->post('orders', ['resource_id' => $resource_id]);

        if (is_wp_error($order_result)) {
            wp_send_json_error(['message' => $order_result->get_error_message()]);
        }

        if (! empty($order_result['owned'])) {
            shopagg_app_store_forget_license_cache($resource_id);
        }

        wp_send_json_success([
            'order_id' => isset($order_result['order']['id']) ? $order_result['order']['id'] : '',
            'amount' => isset($order_result['order']['amount']) ? $order_result['order']['amount'] : '',
            'resource_id' => isset($order_result['resource_id']) ? absint($order_result['resource_id']) : $resource_id,
            'resource_name' => isset($order_result['resource_name']) ? $order_result['resource_name'] : '',
            'owned' => ! empty($order_result['owned']),
            'existing_order' => ! empty($order_result['existing_order']),
            'message' => isset($order_result['message']) ? $order_result['message'] : __('Order created. Please select payment method.', 'shopagg-app-store'),
        ]);
    }

    public function ajax_pay() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';

        if (empty($order_id) || ! in_array($payment_method, ['alipay', 'wechat'], true)) {
            wp_send_json_error(['message' => __('Invalid parameters.', 'shopagg-app-store')]);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $pay_result = $api->post('orders/' . $order_id . '/pay', [
            'payment_method' => $payment_method,
        ]);

        if (is_wp_error($pay_result)) {
            wp_send_json_error(['message' => $pay_result->get_error_message()]);
        }

        wp_send_json_success($pay_result);
    }

    public function ajax_order_status() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $order_id = isset($_GET['order_id']) ? sanitize_text_field(wp_unslash($_GET['order_id'])) : '';
        if (empty($order_id)) {
            wp_send_json_error(['message' => __('Invalid order.', 'shopagg-app-store')]);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('orders/' . $order_id . '/status');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if (! empty($result['paid']) && ! empty($result['resource_id'])) {
            shopagg_app_store_forget_license_cache($result['resource_id']);
        }

        wp_send_json_success($result);
    }

    public function ajax_toggle_resource() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        $resource_type = isset($_POST['resource_type']) ? sanitize_text_field(wp_unslash($_POST['resource_type'])) : '';
        $toggle_action = isset($_POST['toggle_action']) ? sanitize_text_field(wp_unslash($_POST['toggle_action'])) : '';
        $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';

        if (! in_array($resource_type, ['plugin', 'theme'], true) || ! in_array($toggle_action, ['activate', 'deactivate'], true) || $target === '') {
            wp_send_json_error(['message' => __('Invalid parameters.', 'shopagg-app-store')]);
        }

        if ($resource_type === 'plugin') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            if ($toggle_action === 'activate') {
                $result = activate_plugin($target, '', false, false);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }

                wp_send_json_success(['message' => __('Plugin activated.', 'shopagg-app-store')]);
            }

            deactivate_plugins($target, false, false);
            wp_send_json_success(['message' => __('Plugin deactivated.', 'shopagg-app-store')]);
        }

        if ($toggle_action !== 'activate') {
            wp_send_json_error(['message' => __('Themes can only be activated here.', 'shopagg-app-store')]);
        }

        switch_theme($target);
        wp_send_json_success(['message' => __('Theme activated.', 'shopagg-app-store')]);
    }
}
