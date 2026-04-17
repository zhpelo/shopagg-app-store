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
        add_action('wp_ajax_shopagg_app_store_submit_review', [$this, 'ajax_submit_review']);
    }

    public function render_market_page($tab = 'browse') {
        $state = $this->resolve_market_state($tab);
        $is_connected = shopagg_app_store_is_logged_in();
        $user = $is_connected ? shopagg_app_store_get_user() : [];
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-shell">
                <div class="shopagg-hero">
                    <div>
                        <p class="shopagg-eyebrow">ShopAGG 应用市场</p>
                        <h1><?php echo esc_html('ShopAGG 应用商店'); ?></h1>
                        <p class="shopagg-hero-text"><?php echo esc_html('从一个地方浏览、购买、安装和管理 WordPress 插件和主题。'); ?></p>
                    </div>
                    <div class="shopagg-hero-user">
                        <div class="shopagg-hero-user-card">
                            <?php if ($is_connected) : ?>
                                <span class="shopagg-hero-user-label"><?php echo esc_html('当前账号'); ?></span>
                                <strong><?php echo esc_html($user['name'] ?? ''); ?></strong>
                                <span><?php echo esc_html($user['email'] ?? ''); ?></span>
                            <?php else : ?>
                                <span class="shopagg-hero-user-label"><?php echo esc_html('未连接令牌'); ?></span>
                                <strong><?php echo esc_html('现已提供浏览和搜索功能'); ?></strong>
                                <span><?php echo esc_html('只有在要安装或更新时才连接 API 令牌。'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_connected) : ?>
                            <button id="shopagg-logout" class="button button-secondary"><?php echo esc_html('注销'); ?></button>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url(shopagg_app_store_get_connect_url()); ?>">
                                <?php echo esc_html('连接'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="shopagg-overview">
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php echo esc_html('资源'); ?></span>
                        <strong><?php echo esc_html(count($state['resources'])); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php echo esc_html('订单'); ?></span>
                        <strong><?php echo esc_html($is_connected ? count($state['orders']) : '-'); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php echo esc_html('许可证'); ?></span>
                        <strong><?php echo esc_html($is_connected ? count($state['licenses']) : '-'); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php echo esc_html('更新'); ?></span>
                        <strong><?php echo esc_html($is_connected ? count($state['updates']) : '-'); ?></strong>
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
        $result = $api->get('resources/' . $resource_id, [], shopagg_app_store_is_logged_in());

        if (is_wp_error($result) && shopagg_app_store_is_logged_in()) {
            $result = $api->get('resources/' . $resource_id, [], false);
        }

        if (is_wp_error($result)) {
            echo '<div class="wrap shopagg-app-store-wrap"><div class="shopagg-panel-message error"><p>' . esc_html($result->get_error_message()) . '</p></div></div>';
            return;
        }

        $resource = $result['resource'];

        if (shopagg_app_store_is_client_resource($resource)) {
            echo '<div class="wrap shopagg-app-store-wrap"><div class="shopagg-panel-message error"><p>' . esc_html('ShopAGG 应用商店插件单独维护，不会在市场资源库中显示。') . '</p></div></div>';
            return;
        }

        $has_license = shopagg_app_store_is_logged_in() && ! empty($result['has_license']);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? '免费' : '¥' . number_format((float) $resource['price'], 2);
        $short_description = ! empty($resource['short_description'])
            ? $resource['short_description']
            : wp_trim_words(wp_strip_all_tags($resource['description'] ?? ''), 28);
        $reviews = ! empty($resource['reviews']) && is_array($resource['reviews']) ? $resource['reviews'] : [];
        $user_review = ! empty($resource['user_review']) && is_array($resource['user_review']) ? $resource['user_review'] : null;
        $update_history = ! empty($resource['update_history']) && is_array($resource['update_history']) ? $resource['update_history'] : [];
        $rating_average = isset($resource['rating_average']) && $resource['rating_average'] !== null && $resource['rating_average'] !== ''
            ? round((float) $resource['rating_average'], 1)
            : null;
        $rating_count = isset($resource['rating_count']) ? absint($resource['rating_count']) : 0;
        $installs_count = isset($resource['installs_count']) ? absint($resource['installs_count']) : 0;
        $latest_update = ! empty($resource['last_updated']) ? $this->format_storefront_date($resource['last_updated']) : '-';
        $detail_description = $resource['sections']['description'] ?? ($resource['description'] ?? '');
        $latest_changelog = $resource['sections']['changelog'] ?? '';

        $status = $this->get_resource_install_state($resource);
        $cover = ! empty($resource['cover_image']) ? $resource['cover_image'] : SHOPAGG_APP_STORE_PLUGIN_URL . 'assets/images/placeholder.png';
        ?>
        <div class="wrap shopagg-app-store-wrap">
            <div class="shopagg-shell">
                <a href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store')); ?>" class="shopagg-back-link">
                    &larr; <?php echo esc_html('返回商店'); ?>
                </a>

                <div class="shopagg-detail-card shopagg-detail-storefront">
                    <div class="shopagg-detail-top">
                        <div class="shopagg-detail-app">
                            <div class="shopagg-detail-cover shopagg-detail-icon">
                                <img src="<?php echo esc_url($cover); ?>" alt="<?php echo esc_attr($resource['name']); ?>">
                            </div>
                            <div class="shopagg-detail-main">
                                <div class="shopagg-detail-heading">
                                    <div class="shopagg-detail-heading-top">
                                        <span class="shopagg-chip"><?php echo esc_html($resource['type'] === 'theme' ? '主题' : '插件'); ?></span>
                                        <span class="shopagg-chip">v<?php echo esc_html($resource['version']); ?></span>
                                        <?php if ($has_license && ! $is_free) : ?>
                                            <span class="shopagg-chip owned"><?php echo esc_html('已授权'); ?></span>
                                        <?php endif; ?>
                                        <?php if (! empty($status['update'])) : ?>
                                            <span class="shopagg-chip update"><?php echo esc_html(sprintf('新 v%s', $status['update']['version'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <h1><?php echo esc_html($resource['name']); ?></h1>
                                    <?php if (! empty($short_description)) : ?>
                                        <p class="shopagg-detail-tagline"><?php echo esc_html($short_description); ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="shopagg-storefront-stats">
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php echo esc_html('价格'); ?></span>
                                        <strong class="<?php echo esc_attr($is_free ? 'free' : 'paid'); ?>"><?php echo esc_html($price_label); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php echo esc_html('安装量'); ?></span>
                                        <strong><?php echo esc_html($this->format_storefront_install_count($installs_count)); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php echo esc_html('评级'); ?></span>
                                        <strong><?php echo esc_html($rating_average !== null ? number_format($rating_average, 1) : '新'); ?></strong>
                                        <span class="shopagg-storefront-stars"><?php echo wp_kses_post($this->render_rating_stars($rating_average)); ?></span>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php echo esc_html('评论'); ?></span>
                                        <strong><?php echo esc_html($rating_count > 0 ? number_format_i18n($rating_count) : '很快'); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php echo esc_html('最近更新'); ?></span>
                                        <strong><?php echo esc_html($latest_update); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-detail-cta-card">
                            <div class="shopagg-detail-cta-price">
                                <span><?php echo esc_html('获取此资源'); ?></span>
                                <strong class="<?php echo esc_attr($is_free ? 'free' : 'paid'); ?>"><?php echo esc_html($price_label); ?></strong>
                            </div>

                            <div class="shopagg-detail-actions shopagg-detail-actions-storefront">
                                <?php $this->render_detail_action_buttons($resource, $status, $has_license, $is_free); ?>
                            </div>

                            <p class="shopagg-detail-action-hint"><?php echo esc_html($this->get_detail_action_hint($resource, $status, $has_license, $is_free)); ?></p>
                            <div class="shopagg-message" id="detail-message"></div>
                        </div>
                    </div>

                    <div class="shopagg-detail-guidance-grid">
                        <div class="shopagg-detail-guide-card">
                            <h2><?php echo esc_html('如何获取'); ?></h2>
                            <div class="shopagg-detail-guide-steps">
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">1</span>
                                    <div>
                                        <strong><?php echo esc_html('阅读概述'); ?></strong>
                                        <p><?php echo esc_html('从上面的评级、安装次数和更新详情开始，确认该插件或主题是否适合您。'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">2</span>
                                    <div>
                                        <strong><?php echo esc_html('点击主按钮'); ?></strong>
                                        <p><?php echo esc_html($this->get_detail_install_step_text($status, $has_license, $is_free)); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">3</span>
                                    <div>
                                        <strong><?php echo esc_html('完成 WordPress 中的设置'); ?></strong>
                                        <p><?php echo esc_html('安装后，您可以在此页面上激活、停用、切换主题或更新。'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-detail-guide-card">
                            <h2><?php echo esc_html('兼容性'); ?></h2>
                            <div class="shopagg-detail-meta-grid shopagg-detail-meta-grid-compact">
                                <div><span><?php echo esc_html('需要 WordPress'); ?></span><strong><?php echo esc_html($resource['requires'] ?: '-'); ?></strong></div>
                                <div><span><?php echo esc_html('需要 PHP'); ?></span><strong><?php echo esc_html($resource['requires_php'] ?: '-'); ?></strong></div>
                                <div><span><?php echo esc_html('测试达'); ?></span><strong><?php echo esc_html($resource['tested'] ?: '-'); ?></strong></div>
                                <div><span><?php echo esc_html('绑定域'); ?></span><strong><?php echo esc_html($resource['bound_domain'] ?? '-'); ?></strong></div>
                            </div>
                        </div>

                        <div class="shopagg-detail-guide-card">
                            <h2><?php echo esc_html('最新更新'); ?></h2>
                            <?php if (! empty($status['update'])) : ?>
                                <div class="shopagg-update-banner">
                                    <strong><?php echo esc_html('可更新'); ?></strong>
                                    <span>
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                '已安装 v%s，最新 v%s。',
                                                $status['installed_version'] ?: '0.0.0',
                                                $status['update']['version']
                                            )
                                        );
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="shopagg-detail-latest-notes">
                                <?php if (! empty($latest_changelog)) : ?>
                                    <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($latest_changelog), 36)); ?></p>
                                <?php else : ?>
                                    <p><?php echo esc_html('最新版本已可安装。每次更新后，这里都会出现发布说明。'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php echo esc_html('评级与评论'); ?></h2>
                            <p><?php echo esc_html('通过评分和客户反馈，快速判断该资源是否适合您的网站。'); ?></p>
                        </div>
                        <div class="shopagg-detail-reviews-layout">
                            <div class="shopagg-detail-rating-summary">
                                <div class="shopagg-detail-rating-value"><?php echo esc_html($rating_average !== null ? number_format($rating_average, 1) : '—'); ?></div>
                                <div class="shopagg-storefront-stars shopagg-storefront-stars-large"><?php echo wp_kses_post($this->render_rating_stars($rating_average)); ?></div>
                                <p><?php echo esc_html($rating_count > 0 ? sprintf('%s 评级', number_format_i18n($rating_count)) : '评论即将发布。'); ?></p>
                            </div>
                            <div class="shopagg-detail-review-list">
                                <?php $this->render_review_form($resource, $status, $user_review); ?>
                                <?php if (! empty($reviews)) : ?>
                                    <?php foreach ($reviews as $review) : ?>
                                        <div class="shopagg-detail-review-card">
                                            <div class="shopagg-detail-review-top">
                                                <div>
                                                    <strong><?php echo esc_html($review['title'] ?: ($review['author'] ?? '购物网用户')); ?></strong>
                                                    <span><?php echo esc_html($review['author'] ?? '购物网用户'); ?><?php echo ! empty($review['version']) ? ' · v' . esc_html($review['version']) : ''; ?></span>
                                                </div>
                                                <span class="shopagg-storefront-stars"><?php echo wp_kses_post($this->render_rating_stars(isset($review['rating']) ? (float) $review['rating'] : null)); ?></span>
                                            </div>
                                            <p><?php echo esc_html($review['content'] ?? ''); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <div class="shopagg-empty-state">
                                        <h3><?php echo esc_html('尚无评论。'); ?></h3>
                                        <p><?php echo esc_html('先安装，稍后再查看更多客户反馈。'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php echo esc_html('更新历史'); ?></h2>
                            <p><?php echo esc_html('像查看 App Store 的更新日志一样查看每个版本，这样就能快速了解有哪些变化。'); ?></p>
                        </div>
                        <?php if (! empty($update_history)) : ?>
                            <div class="shopagg-detail-timeline">
                                <?php foreach ($update_history as $entry) : ?>
                                    <div class="shopagg-detail-timeline-item">
                                        <div class="shopagg-detail-timeline-head">
                                            <strong><?php echo esc_html('v' . ($entry['version'] ?? '')); ?></strong>
                                            <span><?php echo esc_html($this->format_storefront_date($entry['released_at'] ?? '')); ?></span>
                                        </div>
                                        <?php if (! empty($entry['changelog'])) : ?>
                                            <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($entry['changelog']), 60)); ?></p>
                                        <?php endif; ?>
                                        <div class="shopagg-detail-timeline-meta">
                                            <span><?php echo esc_html('WP'); ?> <?php echo esc_html($entry['requires_wp'] ?: '-'); ?></span>
                                            <span><?php echo esc_html('PHP'); ?> <?php echo esc_html($entry['requires_php'] ?: '-'); ?></span>
                                            <span><?php echo esc_html('已测试'); ?> <?php echo esc_html($entry['tested_wp'] ?: '-'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="shopagg-empty-state">
                                <h3><?php echo esc_html('尚无更新记录。'); ?></h3>
                                <p><?php echo esc_html('新版本发布后，这里将自动显示更新历史。'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php echo esc_html('资源详情'); ?></h2>
                            <p><?php echo esc_html('下面是完整的产品说明，如果您在阅读完上面的摘要后还想深入了解，这将是您的理想选择。'); ?></p>
                        </div>
                        <div class="shopagg-detail-description shopagg-detail-description-bottom">
                            <?php echo wp_kses_post($detail_description); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_review_form($resource, $status, $user_review) {
        $is_connected = shopagg_app_store_is_logged_in();
        $is_installed = ! empty($status['installed']);
        $is_hidden = is_array($user_review) && isset($user_review['status']) && $user_review['status'] === 'hidden';
        $rating = is_array($user_review) && isset($user_review['rating']) ? (int) round((float) $user_review['rating']) : 5;
        $title = is_array($user_review) ? (string) ($user_review['title'] ?? '') : '';
        $content = is_array($user_review) ? (string) ($user_review['content'] ?? '') : '';
        ?>
        <div class="shopagg-detail-review-form-card">
            <div class="shopagg-detail-review-form-head">
                <div>
                    <strong>
                        <?php echo esc_html($user_review ? '更新你的评价' : '分享你的使用体验'); ?>
                    </strong>
                    <span>
                        <?php
                        if (! $is_connected) {
                            echo esc_html('请先连接 API 令牌，然后才能在这个站点发布评分和评价。');
                        } elseif (! $is_installed) {
                            echo esc_html('请先在你的站点安装这个资源，安装后才可以在这里发表评分和评价。');
                        } else {
                            echo esc_html('你的评价会关联到 ShopAGG 账号，之后可以随时回来修改。');
                        }
                        ?>
                    </span>
                </div>
                <?php if ($is_hidden) : ?>
                    <em class="shopagg-review-status-badge"><?php echo esc_html('当前已隐藏'); ?></em>
                <?php endif; ?>
            </div>

            <?php if (! $is_connected) : ?>
                <a class="button button-secondary shopagg-review-connect-btn" href="<?php echo esc_url( shopagg_app_store_get_connect_url() ); ?>">
                    <?php echo esc_html('连接令牌后评价'); ?>
                </a>
            <?php elseif (! $is_installed) : ?>
                <div class="shopagg-review-install-note">
                    <?php echo esc_html('当前 WordPress 站点安装此插件或主题后，评价功能会自动开放。'); ?>
                </div>
            <?php else : ?>
                <form class="shopagg-review-form" data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                    <div class="shopagg-review-stars-field" role="radiogroup" aria-label="<?php echo esc_attr('评级'); ?>">
                        <?php for ($star = 5; $star >= 1; $star--) : ?>
                            <input type="radio"
                                   id="shopagg-review-rating-<?php echo esc_attr($resource['id'] . '-' . $star); ?>"
                                   name="review_rating"
                                   value="<?php echo esc_attr($star); ?>"
                                   <?php checked($rating, $star); ?>>
                            <label for="shopagg-review-rating-<?php echo esc_attr($resource['id'] . '-' . $star); ?>" title="<?php echo esc_attr(sprintf('%d 星', $star)); ?>">&#9733;</label>
                        <?php endfor; ?>
                    </div>

                    <div class="shopagg-review-form-grid">
                        <div>
                            <label for="shopagg-review-title-<?php echo esc_attr($resource['id']); ?>"><?php echo esc_html('评价标题'); ?></label>
                            <input type="text"
                                   id="shopagg-review-title-<?php echo esc_attr($resource['id']); ?>"
                                   name="review_title"
                                   value="<?php echo esc_attr($title); ?>"
                                   placeholder="<?php echo esc_attr('用一句话概括你的使用体验'); ?>">
                        </div>
                        <div>
                            <label for="shopagg-review-version-<?php echo esc_attr($resource['id']); ?>"><?php echo esc_html('已安装版本'); ?></label>
                            <input type="text"
                                   id="shopagg-review-version-<?php echo esc_attr($resource['id']); ?>"
                                   value="<?php echo esc_attr($status['installed_version'] ?: ($resource['version'] ?? '')); ?>"
                                   disabled>
                        </div>
                    </div>

                    <div>
                        <label for="shopagg-review-content-<?php echo esc_attr($resource['id']); ?>"><?php echo esc_html('你的评价'); ?></label>
                        <textarea id="shopagg-review-content-<?php echo esc_attr($resource['id']); ?>"
                                  name="review_content"
                                  rows="4"
                                  placeholder="<?php echo esc_attr('说说你喜欢它的地方，以及它在你站点上的使用情况'); ?>"><?php echo esc_textarea($content); ?></textarea>
                    </div>

                    <div class="shopagg-review-form-actions">
                        <button type="submit"
                                class="button button-primary shopagg-review-submit-btn"
                                data-default-text="<?php echo esc_attr($user_review ? '更新评价' : '发布评价'); ?>">
                            <?php echo esc_html($user_review ? '更新评价' : '发布评价'); ?>
                        </button>
                        <?php if ($is_hidden) : ?>
                            <span class="shopagg-review-form-note"><?php echo esc_html('管理员已隐藏你上一次提交的评价，更新后会继续保留，等待再次审核。'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="shopagg-message shopagg-review-message"></div>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_rating_stars($rating) {
        if ($rating === null) {
            return '<span class="shopagg-star empty">&#9734;</span><span class="shopagg-star empty">&#9734;</span><span class="shopagg-star empty">&#9734;</span><span class="shopagg-star empty">&#9734;</span><span class="shopagg-star empty">&#9734;</span>';
        }

        $output = '';
        $rounded = round((float) $rating * 2) / 2;

        for ($i = 1; $i <= 5; $i++) {
            if ($rounded >= $i) {
                $output .= '<span class="shopagg-star">&#9733;</span>';
            } elseif ($rounded >= ($i - 0.5)) {
                $output .= '<span class="shopagg-star half">&#9733;</span>';
            } else {
                $output .= '<span class="shopagg-star empty">&#9734;</span>';
            }
        }

        return $output;
    }

    private function format_storefront_install_count($count) {
        $count = absint($count);

        if ($count >= 1000000) {
            return round($count / 1000000, 1) . 'M+';
        }

        if ($count >= 10000) {
            return round($count / 1000, 1) . 'K+';
        }

        if ($count > 0) {
            return number_format_i18n($count);
        }

        return '新';
    }

    private function format_storefront_date($value) {
        if (empty($value)) {
            return '-';
        }

        $timestamp = strtotime($value);

        return $timestamp ? wp_date('Y-m-d', $timestamp) : (string) $value;
    }

    private function get_detail_action_hint($resource, $status, $has_license, $is_free) {
        if (! shopagg_app_store_is_logged_in() && (empty($status['installed']) || ! empty($status['update']))) {
            return '首先绑定 API 令牌，然后就可以一步安装、购买或更新该资源。';
        }

        if (! empty($status['update'])) {
            return '新版本已准备就绪。点击更新即可下载并自动替换旧版本。';
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            return '点击安装，WordPress 就会自动将该资源下载到您的网站。';
        }

        if (! $status['installed']) {
            return '先购买，然后就可以立即在本网站上安装。';
        }

        if ($resource['type'] === 'plugin') {
            return '该插件已在您的网站上。您可以在此激活、停用或删除它。';
        }

        return '该主题已在您的网站上。您可以在此切换或删除。';
    }

    private function get_detail_install_step_text($status, $has_license, $is_free) {
        if (! shopagg_app_store_is_logged_in() && (empty($status['installed']) || ! empty($status['update']))) {
            return '如果尚未连接令牌，请先连接。这将使安装、购买和更新更加顺利。';
        }

        if (! empty($status['update'])) {
            return '如果有更新的版本，只需点击更新，WordPress 就会自动下载并替换它。';
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            return '点击安装，资源将自动下载到本网站。无需手动上传 ZIP 文件。';
        }

        if (! $status['installed']) {
            return '先完成购买。付款成功后，即可在本网站安装。';
        }

        return '如果已经安装，您可以从该页面激活、停用或删除。';
    }

    private function resolve_market_state($tab) {
        $tab = sanitize_key($tab);
        $is_connected = shopagg_app_store_is_logged_in();
        $legacy_map = [
            'plugins' => 'plugin',
            'themes' => 'theme',
        ];

        $preset_type = isset($legacy_map[$tab]) ? $legacy_map[$tab] : 'all';
        $normalized_tab = in_array($tab, ['browse', 'updates', 'orders', 'licenses'], true) ? $tab : 'browse';
        if (! $is_connected && $normalized_tab !== 'browse') {
            $normalized_tab = 'browse';
        }

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
        $tabs = [
            'browse' => '浏览',
        ];

        if (shopagg_app_store_is_logged_in()) {
            $tabs['updates'] = '更新';
            $tabs['orders'] = '订单';
            $tabs['licenses'] = '许可证';
        }

        return $tabs;
    }

    private function fetch_resources() {
        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('resources', [], false);

        if (is_wp_error($result)) {
            return [];
        }

        $resources = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];

        return array_values(array_filter($resources, function ($resource) {
            return ! shopagg_app_store_is_client_resource($resource);
        }));
    }

    private function fetch_orders() {
        if (! shopagg_app_store_is_logged_in()) {
            return [];
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('orders');

        if (is_wp_error($result)) {
            return [];
        }

        $orders = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];

        return array_values(array_filter($orders, function ($order) {
            $resource = isset($order['app_store_resource']) && is_array($order['app_store_resource'])
                ? $order['app_store_resource']
                : [];

            return ! shopagg_app_store_is_client_resource($resource);
        }));
    }

    private function fetch_licenses() {
        if (! shopagg_app_store_is_logged_in()) {
            return [];
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $result = $api->get('licenses');

        if (is_wp_error($result)) {
            return [];
        }

        $licenses = isset($result['licenses']) && is_array($result['licenses']) ? $result['licenses'] : [];

        return array_values(array_filter($licenses, function ($license) {
            $resource = isset($license['resource']) && is_array($license['resource'])
                ? $license['resource']
                : [];

            return ! shopagg_app_store_is_client_resource($resource);
        }));
    }

    private function fetch_available_updates() {
        return ShopAGG_App_Store_Updater::instance()->get_available_updates();
    }

    private function render_browse_panel($resources, $preset_type) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php echo esc_html('资源库'); ?></h2>
                    <p><?php echo esc_html('筛选并管理所需的插件和主题。'); ?></p>
                    <?php if (! shopagg_app_store_is_logged_in()) : ?>
                        <p><a href="<?php echo esc_url(shopagg_app_store_get_connect_url()); ?>"><?php echo esc_html('连接 API 令牌'); ?></a> <?php echo esc_html('以安装或更新资源。'); ?></p>
                    <?php endif; ?>
                </div>
                <div class="shopagg-filter-bar">
                    <input type="search" id="shopagg-market-search" class="shopagg-filter-input" placeholder="<?php echo esc_attr('按名称或标题搜索...'); ?>">
                    <select id="shopagg-market-type" class="shopagg-filter-select">
                        <option value="all"><?php echo esc_html('所有类型'); ?></option>
                        <option value="plugin" <?php selected($preset_type, 'plugin'); ?>><?php echo esc_html('插件'); ?></option>
                        <option value="theme" <?php selected($preset_type, 'theme'); ?>><?php echo esc_html('主题'); ?></option>
                    </select>
                    <select id="shopagg-market-price" class="shopagg-filter-select">
                        <option value="all"><?php echo esc_html('所有价格'); ?></option>
                        <option value="free"><?php echo esc_html('免费'); ?></option>
                        <option value="paid"><?php echo esc_html('付费'); ?></option>
                    </select>
                </div>
            </div>

            <div class="shopagg-resource-grid" id="shopagg-resource-grid">
                <?php if (empty($resources)) : ?>
                    <div class="shopagg-empty-state">
                        <h3><?php echo esc_html('未找到资源。'); ?></h3>
                    </div>
                <?php else : ?>
                    <?php foreach ($resources as $resource) : ?>
                        <?php $this->render_resource_card($resource); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="shopagg-empty-state shopagg-filter-empty" id="shopagg-filter-empty" style="display:none;">
                <h3><?php echo esc_html('没有符合当前筛选条件的资源。'); ?></h3>
                <p><?php echo esc_html('尝试更改类型、价格或搜索关键词。'); ?></p>
            </div>
        </div>
        <?php
    }

    private function render_orders_panel($orders) {
        ?>
        <div class="shopagg-panel">
            <div class="shopagg-panel-head">
                <div>
                    <h2><?php echo esc_html('订单历史'); ?></h2>
                    <p><?php echo esc_html('查看应用程序商店订单和付款记录的状态。'); ?></p>
                </div>
            </div>

            <?php if (empty($orders)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php echo esc_html('还没有订单。'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-table">
                    <div class="shopagg-table-head">
                        <span><?php echo esc_html('资源'); ?></span>
                        <span><?php echo esc_html('金额'); ?></span>
                        <span><?php echo esc_html('状态'); ?></span>
                        <span><?php echo esc_html('创建'); ?></span>
                    </div>
                    <?php foreach ($orders as $order) : ?>
                        <?php
                        $resource = isset($order['app_store_resource']) ? $order['app_store_resource'] : [];
                        $status = isset($order['status']) ? $order['status'] : '';
                        ?>
                        <div class="shopagg-table-row">
                            <span>
                                <strong><?php echo esc_html($resource['name'] ?? '未知资源'); ?></strong>
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
                    <h2><?php echo esc_html('许可证记录'); ?></h2>
                    <p><?php echo esc_html('查看您的许可资源和当前链接到本网站的域。'); ?></p>
                </div>
            </div>

            <?php if (empty($licenses)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php echo esc_html('还没有许可证。'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-license-list">
                    <?php foreach ($licenses as $license) : ?>
                        <?php $resource = isset($license['resource']) ? $license['resource'] : []; ?>
                        <div class="shopagg-license-card">
                            <div>
                                <h3><?php echo esc_html($resource['name'] ?? '未知资源'); ?></h3>
                                <p><?php echo esc_html($resource['type'] === 'theme' ? '主题' : '插件'); ?> · v<?php echo esc_html($resource['version'] ?? '-'); ?></p>
                            </div>
                            <div class="shopagg-license-meta">
                                <span><strong><?php echo esc_html('域名'); ?>:</strong> <?php echo esc_html(! empty($license['domain']) ? $license['domain'] : '未绑定'); ?></span>
                                <span><strong><?php echo esc_html('授权时间'); ?>:</strong> <?php echo esc_html($this->format_datetime(isset($license['created_at']) ? $license['created_at'] : '')); ?></span>
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
                    <h2><?php echo esc_html('可用更新'); ?></h2>
                    <p><?php echo esc_html('在一个地方查看和更新通过 ShopAGG 安装的插件和主题。'); ?></p>
                </div>
            </div>

            <?php if (empty($updates)) : ?>
                <div class="shopagg-empty-state">
                    <h3><?php echo esc_html('一切都是最新的。'); ?></h3>
                </div>
            <?php else : ?>
                <div class="shopagg-update-list">
                    <?php foreach ($updates as $update) : ?>
                        <div class="shopagg-update-card">
                            <div>
                                <div class="shopagg-update-header">
                                    <h3><?php echo esc_html($update['name'] ?? $update['slug']); ?></h3>
                                    <span class="shopagg-chip"><?php echo esc_html($update['type'] === 'theme' ? '主题' : '插件'); ?></span>
                                </div>
                                <p class="shopagg-update-summary">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            '已安装 v%s，最新 v%s',
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
                                    <?php echo esc_html('立即更新'); ?>
                                </a>
                                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . absint($update['id']))); ?>">
                                    <?php echo esc_html('查看详情'); ?>
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
        if (shopagg_app_store_is_client_resource($resource)) {
            return;
        }

        $status = $this->get_resource_install_state($resource);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? '免费' : '¥' . number_format((float) $resource['price'], 2);
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
                    <span class="shopagg-resource-type"><?php echo esc_html($resource['type'] === 'theme' ? '主题' : '插件'); ?></span>
                    <span class="shopagg-resource-version">v<?php echo esc_html($resource['version']); ?></span>
                </div>
                <h3><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($resource['name']); ?></a></h3>
                <p class="shopagg-resource-summary"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($resource['short_description'] ?? $resource['description'] ?? ''), 18)); ?></p>
                <div class="shopagg-resource-footer">
                    <span class="shopagg-resource-price <?php echo $is_free ? 'free' : 'paid'; ?>"><?php echo esc_html($price_label); ?></span>
                    <div class="shopagg-resource-flags">
                        <?php if (! empty($resource['has_license'])) : ?>
                            <span class="shopagg-flag owned"><?php echo esc_html('已拥有'); ?></span>
                        <?php endif; ?>
                        <?php if (! empty($status['installed'])) : ?>
                            <span class="shopagg-flag installed"><?php echo esc_html(! empty($status['active']) ? '已启用' : '已安装'); ?></span>
                        <?php endif; ?>
                        <?php if (! empty($status['update'])) : ?>
                            <span class="shopagg-flag update"><?php echo esc_html(sprintf('更新 v%s', $status['update']['version'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <a class="button button-primary shopagg-card-btn" href="<?php echo esc_url($detail_url); ?>">
                    <?php echo esc_html('查看详情'); ?>
                </a>
            </div>
        </article>
        <?php
    }

    private function render_detail_action_buttons($resource, $status, $has_license, $is_free) {
        $connect_url = shopagg_app_store_get_connect_url();
        ?>
        <div class="shopagg-action-stack">
        <?php

        if (! shopagg_app_store_is_logged_in() && (empty($status['installed']) || ! empty($status['update']))) {
            $connect_label = ! empty($status['update'])
                ? '连接更新令牌'
                : ($is_free ? '将令牌连接到安装' : '将令牌连接到购买');
            $this->render_detail_action_state(
                '需要令牌',
                '请先连接 API 令牌，之后你就可以在同一页面完成购买、安装和更新。',
                'warning'
            );
            ?>
            <div class="shopagg-action-primary">
                <a class="button button-primary shopagg-action-button shopagg-action-button-primary" href="<?php echo esc_url($connect_url); ?>">
                    <?php echo esc_html($connect_label); ?>
                </a>
            </div>
            </div>
            <?php
            return;
        }

        if (! empty($status['update']['update_url'])) {
            $this->render_detail_action_state(
                '可更新',
                sprintf(
                    '版本 %1$s 已可用，你当前站点正在使用 %2$s。',
                    'v' . $status['update']['version'],
                    'v' . ($status['installed_version'] ?: '0.0.0')
                ),
                'warning'
            );
            ?>
            <div class="shopagg-action-primary">
                <a class="button button-primary shopagg-action-button shopagg-action-button-primary" href="<?php echo esc_url($status['update']['update_url']); ?>">
                    <?php echo esc_html(sprintf('更新至 v%s', $status['update']['version'])); ?>
                </a>
            </div>
            <?php
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            $this->render_detail_action_state(
                '可以安装',
                '当前站点可安装此资源，点击安装后 WordPress 会自动下载。'
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-install-btn"
                        data-resource-id="<?php echo esc_attr($resource['id']); ?>"
                        data-type="<?php echo esc_attr($resource['type']); ?>">
                    <?php echo esc_html('安装'); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        if (! $status['installed']) {
            $this->render_detail_action_state(
                '需要先购买',
                '请先购买此资源，支付完成后即可在当前站点安装。'
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-purchase-btn"
                        data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                    <?php printf(esc_html('购买 %s'), esc_html($is_free ? '免费' : '¥' . number_format((float) $resource['price'], 2))); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        if ($resource['type'] === 'plugin') {
            $this->render_detail_action_state(
                ! empty($status['active']) ? '已安装并启用' : '已安装到当前站点',
                ! empty($status['active'])
                    ? '该插件当前正在你的站点运行，你可以临时停用或彻底删除。'
                    : '该插件已经安装，准备好后可以启用；如果不再需要，也可以删除。'
            );

            if ($status['active']) {
                ?>
                <div class="shopagg-action-primary">
                    <button class="button button-secondary shopagg-action-button shopagg-action-button-secondary shopagg-toggle-resource-btn"
                            data-resource-type="plugin"
                            data-toggle-action="deactivate"
                            data-target="<?php echo esc_attr($status['target']); ?>">
                        <?php echo esc_html('停用'); ?>
                    </button>
                </div>
                <?php
            } else {
                ?>
                <div class="shopagg-action-primary">
                    <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-toggle-resource-btn"
                            data-resource-type="plugin"
                            data-toggle-action="activate"
                            data-target="<?php echo esc_attr($status['target']); ?>">
                        <?php echo esc_html('激活'); ?>
                    </button>
                </div>
                <?php
            }

            ?>
            <div class="shopagg-action-secondary">
                <a class="button button-secondary shopagg-action-button shopagg-action-button-danger" href="<?php echo esc_url($this->get_plugin_delete_url($status['target'])); ?>">
                    <?php echo esc_html('删除'); ?>
                </a>
            </div>
            </div>
            <?php
            return;
        }

        if ($status['active']) {
            $this->render_detail_action_state(
                '当前启用主题',
                '该主题当前已在你的站点启用，现在无需额外操作。',
                'success'
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-secondary shopagg-action-button shopagg-action-button-muted" disabled>
                    <?php echo esc_html('当前主题'); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        $this->render_detail_action_state(
            '已安装到当前站点',
            '该主题已经安装，想切换站点外观时可以启用；如果不再需要，也可以删除。'
        );
        ?>
        <div class="shopagg-action-primary">
            <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-toggle-resource-btn"
                    data-resource-type="theme"
                    data-toggle-action="activate"
                    data-target="<?php echo esc_attr($status['target']); ?>">
                <?php echo esc_html('激活主题'); ?>
            </button>
        </div>
        <div class="shopagg-action-secondary">
            <a class="button button-secondary shopagg-action-button shopagg-action-button-danger" href="<?php echo esc_url($this->get_theme_delete_url($status['target'])); ?>">
                <?php echo esc_html('删除'); ?>
            </a>
        </div>
        </div>
        <?php
    }

    private function render_detail_action_state($title, $description, $tone = 'default') {
        ?>
        <div class="shopagg-action-state shopagg-action-state-<?php echo esc_attr($tone); ?>">
            <strong><?php echo esc_html($title); ?></strong>
            <span><?php echo esc_html($description); ?></span>
        </div>
        <?php
    }

    private function get_resource_install_state($resource) {
        if (shopagg_app_store_is_client_resource($resource)) {
            return [
                'installed' => false,
                'active' => false,
                'target' => '',
                'installed_version' => null,
                'update' => null,
            ];
        }

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
            'pending' => '待定',
            'paid' => '付费',
            'processing' => '处理中',
            'completed' => '已完成',
            'failed' => '失败',
            'cancelled' => '已取消',
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

    private function get_resource_detail_url($resource_id) {
        return admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . absint($resource_id));
    }

    public function ajax_install() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('install_plugins')) {
            wp_send_json_error(['message' => '拒绝许可。']);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => '请先登录。']);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => '无效资源。']);
        }

        $result = ShopAGG_App_Store_Installer::instance()->install($resource_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        shopagg_app_store_forget_license_cache($resource_id);

        wp_send_json_success([
            'message' => '安装成功！刷新资源状态...',
        ]);
    }

    public function ajax_purchase() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => '拒绝许可。']);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => '请先登录。']);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        if (! $resource_id) {
            wp_send_json_error(['message' => '无效资源。']);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $resource_result = $api->get('resources/' . $resource_id);

        if (is_wp_error($resource_result) || empty($resource_result['resource']) || shopagg_app_store_is_client_resource($resource_result['resource'])) {
            wp_send_json_error(['message' => '该资源不能在 ShopAGG 应用商店插件内部安装。']);
        }

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
            'message' => isset($order_result['message']) ? $order_result['message'] : '创建订单。请选择付款方式。',
        ]);
    }

    public function ajax_pay() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => '拒绝许可。']);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => '请先登录。']);
        }

        $order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';

        if (empty($order_id) || ! in_array($payment_method, ['alipay', 'wechat'], true)) {
            wp_send_json_error(['message' => '参数无效。']);
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
            wp_send_json_error(['message' => '请先登录。']);
        }

        $order_id = isset($_GET['order_id']) ? sanitize_text_field(wp_unslash($_GET['order_id'])) : '';
        if (empty($order_id)) {
            wp_send_json_error(['message' => '订单无效。']);
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
            wp_send_json_error(['message' => '拒绝许可。']);
        }

        $resource_type = isset($_POST['resource_type']) ? sanitize_text_field(wp_unslash($_POST['resource_type'])) : '';
        $toggle_action = isset($_POST['toggle_action']) ? sanitize_text_field(wp_unslash($_POST['toggle_action'])) : '';
        $target = isset($_POST['target']) ? sanitize_text_field(wp_unslash($_POST['target'])) : '';

        if (! in_array($resource_type, ['plugin', 'theme'], true) || ! in_array($toggle_action, ['activate', 'deactivate'], true) || $target === '') {
            wp_send_json_error(['message' => '参数无效。']);
        }

        if ($resource_type === 'plugin') {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            if ($toggle_action === 'activate') {
                $result = activate_plugin($target, '', false, false);
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                }

                wp_send_json_success(['message' => '已激活插件。']);
            }

            deactivate_plugins($target, false, false);
            wp_send_json_success(['message' => '插件已停用。']);
        }

        if ($toggle_action !== 'activate') {
            wp_send_json_error(['message' => '主题只能在这里激活。']);
        }

        switch_theme($target);
        wp_send_json_success(['message' => '激活主题。']);
    }

    public function ajax_submit_review() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => '拒绝许可。']);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => '请先登录。']);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        $rating = isset($_POST['rating']) ? (float) wp_unslash($_POST['rating']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';

        if (! $resource_id || $rating < 1 || $rating > 5 || $content === '') {
            wp_send_json_error(['message' => '请先完成评分和评价内容再提交。']);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $resource_result = $api->get('resources/' . $resource_id);

        if (is_wp_error($resource_result) || empty($resource_result['resource'])) {
            wp_send_json_error(['message' => '无效资源。']);
        }

        $resource = $resource_result['resource'];

        if (shopagg_app_store_is_client_resource($resource)) {
            wp_send_json_error(['message' => '该资源不能在 ShopAGG 应用商店插件内部评价。']);
        }

        $status = $this->get_resource_install_state($resource);
        if (empty($status['installed']) || empty($status['installed_version'])) {
            wp_send_json_error(['message' => '请先在你的站点安装这个资源，然后才能发布评价。']);
        }

        $result = $api->post('resources/' . $resource_id . '/reviews', [
            'rating' => round($rating, 1),
            'title' => $title,
            'content' => $content,
            'installed_version' => $status['installed_version'],
            'site_domain' => shopagg_app_store_get_site_domain(),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => isset($result['message']) ? $result['message'] : '你的评价已保存。',
        ]);
    }
}
