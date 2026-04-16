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
                        <p class="shopagg-eyebrow">ShopAGG Marketplace</p>
                        <h1><?php esc_html_e('ShopAGG App Store', 'shopagg-app-store'); ?></h1>
                        <p class="shopagg-hero-text"><?php esc_html_e('Browse, purchase, install, and manage WordPress plugins and themes from one place.', 'shopagg-app-store'); ?></p>
                    </div>
                    <div class="shopagg-hero-user">
                        <div class="shopagg-hero-user-card">
                            <?php if ($is_connected) : ?>
                                <span class="shopagg-hero-user-label"><?php esc_html_e('Current Account', 'shopagg-app-store'); ?></span>
                                <strong><?php echo esc_html($user['name'] ?? ''); ?></strong>
                                <span><?php echo esc_html($user['email'] ?? ''); ?></span>
                            <?php else : ?>
                                <span class="shopagg-hero-user-label"><?php esc_html_e('No Token Connected', 'shopagg-app-store'); ?></span>
                                <strong><?php esc_html_e('Browsing and search are available now', 'shopagg-app-store'); ?></strong>
                                <span><?php esc_html_e('Connect your API token only when you want to install or update.', 'shopagg-app-store'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_connected) : ?>
                            <button id="shopagg-logout" class="button button-secondary"><?php esc_html_e('Logout', 'shopagg-app-store'); ?></button>
                        <?php else : ?>
                            <a class="button button-primary" href="<?php echo esc_url(shopagg_app_store_get_connect_url()); ?>">
                                <?php esc_html_e('Connect', 'shopagg-app-store'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="shopagg-overview">
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Resources', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html(count($state['resources'])); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Orders', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html($is_connected ? count($state['orders']) : '-'); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Licenses', 'shopagg-app-store'); ?></span>
                        <strong><?php echo esc_html($is_connected ? count($state['licenses']) : '-'); ?></strong>
                    </div>
                    <div class="shopagg-stat-card">
                        <span class="shopagg-stat-label"><?php esc_html_e('Updates', 'shopagg-app-store'); ?></span>
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
            echo '<div class="wrap shopagg-app-store-wrap"><div class="shopagg-panel-message error"><p>' . esc_html__('The ShopAGG App Store plugin is managed separately and is not shown inside the marketplace library.', 'shopagg-app-store') . '</p></div></div>';
            return;
        }

        $has_license = shopagg_app_store_is_logged_in() && ! empty($result['has_license']);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '¥' . number_format((float) $resource['price'], 2);
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
                    &larr; <?php esc_html_e('Back to Store', 'shopagg-app-store'); ?>
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
                                        <span class="shopagg-chip"><?php echo esc_html($resource['type'] === 'theme' ? __('Theme', 'shopagg-app-store') : __('Plugin', 'shopagg-app-store')); ?></span>
                                        <span class="shopagg-chip">v<?php echo esc_html($resource['version']); ?></span>
                                        <?php if ($has_license && ! $is_free) : ?>
                                            <span class="shopagg-chip owned"><?php esc_html_e('Licensed', 'shopagg-app-store'); ?></span>
                                        <?php endif; ?>
                                        <?php if (! empty($status['update'])) : ?>
                                            <span class="shopagg-chip update"><?php echo esc_html(sprintf(__('New v%s', 'shopagg-app-store'), $status['update']['version'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <h1><?php echo esc_html($resource['name']); ?></h1>
                                    <?php if (! empty($short_description)) : ?>
                                        <p class="shopagg-detail-tagline"><?php echo esc_html($short_description); ?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="shopagg-storefront-stats">
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php esc_html_e('Price', 'shopagg-app-store'); ?></span>
                                        <strong class="<?php echo esc_attr($is_free ? 'free' : 'paid'); ?>"><?php echo esc_html($price_label); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php esc_html_e('Installs', 'shopagg-app-store'); ?></span>
                                        <strong><?php echo esc_html($this->format_storefront_install_count($installs_count)); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php esc_html_e('Rating', 'shopagg-app-store'); ?></span>
                                        <strong><?php echo esc_html($rating_average !== null ? number_format($rating_average, 1) : __('New', 'shopagg-app-store')); ?></strong>
                                        <span class="shopagg-storefront-stars"><?php echo wp_kses_post($this->render_rating_stars($rating_average)); ?></span>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php esc_html_e('Reviews', 'shopagg-app-store'); ?></span>
                                        <strong><?php echo esc_html($rating_count > 0 ? number_format_i18n($rating_count) : __('Soon', 'shopagg-app-store')); ?></strong>
                                    </div>
                                    <div class="shopagg-storefront-stat">
                                        <span class="shopagg-storefront-stat-label"><?php esc_html_e('Updated', 'shopagg-app-store'); ?></span>
                                        <strong><?php echo esc_html($latest_update); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-detail-cta-card">
                            <div class="shopagg-detail-cta-price">
                                <span><?php esc_html_e('Get This Resource', 'shopagg-app-store'); ?></span>
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
                            <h2><?php esc_html_e('How To Get It', 'shopagg-app-store'); ?></h2>
                            <div class="shopagg-detail-guide-steps">
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">1</span>
                                    <div>
                                        <strong><?php esc_html_e('Read the overview', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('Start with the rating, install count, and update details above to confirm that this is the right plugin or theme for you.', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">2</span>
                                    <div>
                                        <strong><?php esc_html_e('Tap the main button', 'shopagg-app-store'); ?></strong>
                                        <p><?php echo esc_html($this->get_detail_install_step_text($status, $has_license, $is_free)); ?></p>
                                    </div>
                                </div>
                                <div class="shopagg-detail-guide-step">
                                    <span class="shopagg-detail-guide-num">3</span>
                                    <div>
                                        <strong><?php esc_html_e('Finish setup in WordPress', 'shopagg-app-store'); ?></strong>
                                        <p><?php esc_html_e('After installation, you can activate, deactivate, switch themes, or update right from this page.', 'shopagg-app-store'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="shopagg-detail-guide-card">
                            <h2><?php esc_html_e('Compatibility', 'shopagg-app-store'); ?></h2>
                            <div class="shopagg-detail-meta-grid shopagg-detail-meta-grid-compact">
                                <div><span><?php esc_html_e('Requires WordPress', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['requires'] ?: '-'); ?></strong></div>
                                <div><span><?php esc_html_e('Requires PHP', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['requires_php'] ?: '-'); ?></strong></div>
                                <div><span><?php esc_html_e('Tested Up To', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['tested'] ?: '-'); ?></strong></div>
                                <div><span><?php esc_html_e('Bound Domain', 'shopagg-app-store'); ?></span><strong><?php echo esc_html($resource['bound_domain'] ?? '-'); ?></strong></div>
                            </div>
                        </div>

                        <div class="shopagg-detail-guide-card">
                            <h2><?php esc_html_e('Latest Update', 'shopagg-app-store'); ?></h2>
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

                            <div class="shopagg-detail-latest-notes">
                                <?php if (! empty($latest_changelog)) : ?>
                                    <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($latest_changelog), 36)); ?></p>
                                <?php else : ?>
                                    <p><?php esc_html_e('The latest version is ready to install. Release notes will appear here after each update.', 'shopagg-app-store'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php esc_html_e('Ratings & Reviews', 'shopagg-app-store'); ?></h2>
                            <p><?php esc_html_e('Use ratings and customer feedback to quickly decide whether this resource is a good fit for your site.', 'shopagg-app-store'); ?></p>
                        </div>
                        <div class="shopagg-detail-reviews-layout">
                            <div class="shopagg-detail-rating-summary">
                                <div class="shopagg-detail-rating-value"><?php echo esc_html($rating_average !== null ? number_format($rating_average, 1) : '—'); ?></div>
                                <div class="shopagg-storefront-stars shopagg-storefront-stars-large"><?php echo wp_kses_post($this->render_rating_stars($rating_average)); ?></div>
                                <p><?php echo esc_html($rating_count > 0 ? sprintf(_n('%s rating', '%s ratings', $rating_count, 'shopagg-app-store'), number_format_i18n($rating_count)) : __('Reviews are coming soon.', 'shopagg-app-store')); ?></p>
                            </div>
                            <div class="shopagg-detail-review-list">
                                <?php $this->render_review_form($resource, $status, $user_review); ?>
                                <?php if (! empty($reviews)) : ?>
                                    <?php foreach ($reviews as $review) : ?>
                                        <div class="shopagg-detail-review-card">
                                            <div class="shopagg-detail-review-top">
                                                <div>
                                                    <strong><?php echo esc_html($review['title'] ?: ($review['author'] ?? __('ShopAGG User', 'shopagg-app-store'))); ?></strong>
                                                    <span><?php echo esc_html($review['author'] ?? __('ShopAGG User', 'shopagg-app-store')); ?><?php echo ! empty($review['version']) ? ' · v' . esc_html($review['version']) : ''; ?></span>
                                                </div>
                                                <span class="shopagg-storefront-stars"><?php echo wp_kses_post($this->render_rating_stars(isset($review['rating']) ? (float) $review['rating'] : null)); ?></span>
                                            </div>
                                            <p><?php echo esc_html($review['content'] ?? ''); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <div class="shopagg-empty-state">
                                        <h3><?php esc_html_e('No reviews yet.', 'shopagg-app-store'); ?></h3>
                                        <p><?php esc_html_e('Install it first and check back later for more customer feedback.', 'shopagg-app-store'); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php esc_html_e('Update History', 'shopagg-app-store'); ?></h2>
                            <p><?php esc_html_e('Review each version like an App Store changelog so you can quickly see what has changed.', 'shopagg-app-store'); ?></p>
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
                                            <span><?php esc_html_e('WP', 'shopagg-app-store'); ?> <?php echo esc_html($entry['requires_wp'] ?: '-'); ?></span>
                                            <span><?php esc_html_e('PHP', 'shopagg-app-store'); ?> <?php echo esc_html($entry['requires_php'] ?: '-'); ?></span>
                                            <span><?php esc_html_e('Tested', 'shopagg-app-store'); ?> <?php echo esc_html($entry['tested_wp'] ?: '-'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="shopagg-empty-state">
                                <h3><?php esc_html_e('No update history yet.', 'shopagg-app-store'); ?></h3>
                                <p><?php esc_html_e('Update history will appear here automatically after new versions are published.', 'shopagg-app-store'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="shopagg-detail-section">
                        <div class="shopagg-detail-section-head">
                            <h2><?php esc_html_e('Resource Details', 'shopagg-app-store'); ?></h2>
                            <p><?php esc_html_e('The full product description is shown below, which is ideal when you want to dive deeper after reviewing the summary above.', 'shopagg-app-store'); ?></p>
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
                        <?php echo esc_html($user_review ? __('Update Your Review', 'shopagg-app-store') : __('Share Your Experience', 'shopagg-app-store')); ?>
                    </strong>
                    <span>
                        <?php
                        if (! $is_connected) {
                            esc_html_e('Connect your API token first, then you can publish a rating and review from this site.', 'shopagg-app-store');
                        } elseif (! $is_installed) {
                            esc_html_e('Install this resource on your site first. Once it is installed, you can leave a rating and review here.', 'shopagg-app-store');
                        } else {
                            esc_html_e('Your review is linked to your ShopAGG account. You can come back anytime to edit it.', 'shopagg-app-store');
                        }
                        ?>
                    </span>
                </div>
                <?php if ($is_hidden) : ?>
                    <em class="shopagg-review-status-badge"><?php esc_html_e('Currently Hidden', 'shopagg-app-store'); ?></em>
                <?php endif; ?>
            </div>

            <?php if (! $is_connected) : ?>
                <a class="button button-secondary shopagg-review-connect-btn" href="<?php echo esc_url(shopagg_app_store_get_connect_url($this->get_resource_detail_url($resource['id']))); ?>">
                    <?php esc_html_e('Connect Token to Review', 'shopagg-app-store'); ?>
                </a>
            <?php elseif (! $is_installed) : ?>
                <div class="shopagg-review-install-note">
                    <?php esc_html_e('Reviews open automatically after this plugin or theme is installed on the current WordPress site.', 'shopagg-app-store'); ?>
                </div>
            <?php else : ?>
                <form class="shopagg-review-form" data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                    <div class="shopagg-review-stars-field" role="radiogroup" aria-label="<?php esc_attr_e('Rating', 'shopagg-app-store'); ?>">
                        <?php for ($star = 5; $star >= 1; $star--) : ?>
                            <input type="radio"
                                   id="shopagg-review-rating-<?php echo esc_attr($resource['id'] . '-' . $star); ?>"
                                   name="review_rating"
                                   value="<?php echo esc_attr($star); ?>"
                                   <?php checked($rating, $star); ?>>
                            <label for="shopagg-review-rating-<?php echo esc_attr($resource['id'] . '-' . $star); ?>" title="<?php echo esc_attr(sprintf(__('%d stars', 'shopagg-app-store'), $star)); ?>">&#9733;</label>
                        <?php endfor; ?>
                    </div>

                    <div class="shopagg-review-form-grid">
                        <div>
                            <label for="shopagg-review-title-<?php echo esc_attr($resource['id']); ?>"><?php esc_html_e('Review Title', 'shopagg-app-store'); ?></label>
                            <input type="text"
                                   id="shopagg-review-title-<?php echo esc_attr($resource['id']); ?>"
                                   name="review_title"
                                   value="<?php echo esc_attr($title); ?>"
                                   placeholder="<?php esc_attr_e('Summarize your experience in one line', 'shopagg-app-store'); ?>">
                        </div>
                        <div>
                            <label for="shopagg-review-version-<?php echo esc_attr($resource['id']); ?>"><?php esc_html_e('Installed Version', 'shopagg-app-store'); ?></label>
                            <input type="text"
                                   id="shopagg-review-version-<?php echo esc_attr($resource['id']); ?>"
                                   value="<?php echo esc_attr($status['installed_version'] ?: ($resource['version'] ?? '')); ?>"
                                   disabled>
                        </div>
                    </div>

                    <div>
                        <label for="shopagg-review-content-<?php echo esc_attr($resource['id']); ?>"><?php esc_html_e('Your Review', 'shopagg-app-store'); ?></label>
                        <textarea id="shopagg-review-content-<?php echo esc_attr($resource['id']); ?>"
                                  name="review_content"
                                  rows="4"
                                  placeholder="<?php esc_attr_e('What do you like, and how is it working on your site?', 'shopagg-app-store'); ?>"><?php echo esc_textarea($content); ?></textarea>
                    </div>

                    <div class="shopagg-review-form-actions">
                        <button type="submit"
                                class="button button-primary shopagg-review-submit-btn"
                                data-default-text="<?php echo esc_attr($user_review ? __('Update Review', 'shopagg-app-store') : __('Publish Review', 'shopagg-app-store')); ?>">
                            <?php echo esc_html($user_review ? __('Update Review', 'shopagg-app-store') : __('Publish Review', 'shopagg-app-store')); ?>
                        </button>
                        <?php if ($is_hidden) : ?>
                            <span class="shopagg-review-form-note"><?php esc_html_e('An administrator hid your last review. Updating it will keep it saved for later review.', 'shopagg-app-store'); ?></span>
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

        return __('New', 'shopagg-app-store');
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
            return __('Bind your API Token first, then you can install, purchase or update this resource in one step.', 'shopagg-app-store');
        }

        if (! empty($status['update'])) {
            return __('A newer version is ready. Tap update to download and replace the old version automatically.', 'shopagg-app-store');
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            return __('Tap install and WordPress will download this resource to your site automatically.', 'shopagg-app-store');
        }

        if (! $status['installed']) {
            return __('Purchase first, then you can install it on this site right away.', 'shopagg-app-store');
        }

        if ($resource['type'] === 'plugin') {
            return __('This plugin is already on your site. You can activate, deactivate or delete it here.', 'shopagg-app-store');
        }

        return __('This theme is already on your site. You can switch to it or remove it here.', 'shopagg-app-store');
    }

    private function get_detail_install_step_text($status, $has_license, $is_free) {
        if (! shopagg_app_store_is_logged_in() && (empty($status['installed']) || ! empty($status['update']))) {
            return __('If you have not connected a token yet, do that first. It makes installation, purchasing, and updates much smoother.', 'shopagg-app-store');
        }

        if (! empty($status['update'])) {
            return __('If a newer version is available, just tap update and WordPress will download and replace it automatically.', 'shopagg-app-store');
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            return __('Tap install and the resource will be downloaded to this site automatically. There is no need to upload a ZIP file manually.', 'shopagg-app-store');
        }

        if (! $status['installed']) {
            return __('Complete the purchase first. Once payment succeeds, you can install it on this site right away.', 'shopagg-app-store');
        }

        return __('If it is already installed, you can activate, deactivate, or remove it from this page.', 'shopagg-app-store');
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
            'browse' => __('Browse', 'shopagg-app-store'),
        ];

        if (shopagg_app_store_is_logged_in()) {
            $tabs['updates'] = __('Updates', 'shopagg-app-store');
            $tabs['orders'] = __('Orders', 'shopagg-app-store');
            $tabs['licenses'] = __('Licenses', 'shopagg-app-store');
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
                    <h2><?php esc_html_e('Resource Library', 'shopagg-app-store'); ?></h2>
                    <p><?php esc_html_e('Filter and manage the plugins and themes you need.', 'shopagg-app-store'); ?></p>
                    <?php if (! shopagg_app_store_is_logged_in()) : ?>
                        <p><a href="<?php echo esc_url(shopagg_app_store_get_connect_url()); ?>"><?php esc_html_e('Connect your API Token', 'shopagg-app-store'); ?></a> <?php esc_html_e('to install or update resources.', 'shopagg-app-store'); ?></p>
                    <?php endif; ?>
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
                    <p><?php esc_html_e('View the status of your app store orders and payment records.', 'shopagg-app-store'); ?></p>
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
                    <p><?php esc_html_e('View your licensed resources and the domain currently linked to this site.', 'shopagg-app-store'); ?></p>
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
                    <p><?php esc_html_e('Review and update the plugins and themes installed through ShopAGG in one place.', 'shopagg-app-store'); ?></p>
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
        if (shopagg_app_store_is_client_resource($resource)) {
            return;
        }

        $status = $this->get_resource_install_state($resource);
        $is_free = (float) $resource['price'] === 0.0;
        $price_label = $is_free ? __('Free', 'shopagg-app-store') : '¥' . number_format((float) $resource['price'], 2);
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
        $detail_url = $this->get_resource_detail_url($resource['id']);
        $connect_url = shopagg_app_store_get_connect_url($detail_url);
        ?>
        <div class="shopagg-action-stack">
        <?php

        if (! shopagg_app_store_is_logged_in() && (empty($status['installed']) || ! empty($status['update']))) {
            $connect_label = ! empty($status['update'])
                ? __('Connect Token to Update', 'shopagg-app-store')
                : ($is_free ? __('Connect Token to Install', 'shopagg-app-store') : __('Connect Token to Purchase', 'shopagg-app-store'));
            $this->render_detail_action_state(
                __('Token Required', 'shopagg-app-store'),
                __('Connect your API token first. After that, you can purchase, install, and update this resource from the same page.', 'shopagg-app-store'),
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
                __('Update Ready', 'shopagg-app-store'),
                sprintf(
                    __('Version %1$s is available. You are currently using %2$s on this site.', 'shopagg-app-store'),
                    'v' . $status['update']['version'],
                    'v' . ($status['installed_version'] ?: '0.0.0')
                ),
                'warning'
            );
            ?>
            <div class="shopagg-action-primary">
                <a class="button button-primary shopagg-action-button shopagg-action-button-primary" href="<?php echo esc_url($status['update']['update_url']); ?>">
                    <?php echo esc_html(sprintf(__('Update to v%s', 'shopagg-app-store'), $status['update']['version'])); ?>
                </a>
            </div>
            <?php
        }

        if (! $status['installed'] && ($is_free || $has_license)) {
            $this->render_detail_action_state(
                __('Ready to Install', 'shopagg-app-store'),
                __('This resource is available for this site. Tap install and WordPress will download it automatically.', 'shopagg-app-store')
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-install-btn"
                        data-resource-id="<?php echo esc_attr($resource['id']); ?>"
                        data-type="<?php echo esc_attr($resource['type']); ?>">
                    <?php esc_html_e('Install', 'shopagg-app-store'); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        if (! $status['installed']) {
            $this->render_detail_action_state(
                __('Purchase Required', 'shopagg-app-store'),
                __('Purchase this resource first. As soon as payment is complete, you can install it on this site.', 'shopagg-app-store')
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-purchase-btn"
                        data-resource-id="<?php echo esc_attr($resource['id']); ?>">
                    <?php printf(esc_html__('Purchase %s', 'shopagg-app-store'), esc_html($is_free ? __('Free', 'shopagg-app-store') : '$' . number_format((float) $resource['price'], 2))); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        if ($resource['type'] === 'plugin') {
            $this->render_detail_action_state(
                ! empty($status['active']) ? __('Installed and Active', 'shopagg-app-store') : __('Installed on This Site', 'shopagg-app-store'),
                ! empty($status['active'])
                    ? __('This plugin is live on your site right now. You can deactivate it temporarily or remove it completely.', 'shopagg-app-store')
                    : __('This plugin is already installed. Activate it when you are ready, or remove it if you no longer need it.', 'shopagg-app-store')
            );

            if ($status['active']) {
                ?>
                <div class="shopagg-action-primary">
                    <button class="button button-secondary shopagg-action-button shopagg-action-button-secondary shopagg-toggle-resource-btn"
                            data-resource-type="plugin"
                            data-toggle-action="deactivate"
                            data-target="<?php echo esc_attr($status['target']); ?>">
                        <?php esc_html_e('Deactivate', 'shopagg-app-store'); ?>
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
                        <?php esc_html_e('Activate', 'shopagg-app-store'); ?>
                    </button>
                </div>
                <?php
            }

            ?>
            <div class="shopagg-action-secondary">
                <a class="button button-secondary shopagg-action-button shopagg-action-button-danger" href="<?php echo esc_url($this->get_plugin_delete_url($status['target'])); ?>">
                    <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
                </a>
            </div>
            </div>
            <?php
            return;
        }

        if ($status['active']) {
            $this->render_detail_action_state(
                __('Currently Active Theme', 'shopagg-app-store'),
                __('This theme is already active on your site. You do not need to do anything else right now.', 'shopagg-app-store'),
                'success'
            );
            ?>
            <div class="shopagg-action-primary">
                <button class="button button-secondary shopagg-action-button shopagg-action-button-muted" disabled>
                    <?php esc_html_e('Current Theme', 'shopagg-app-store'); ?>
                </button>
            </div>
            </div>
            <?php
            return;
        }

        $this->render_detail_action_state(
            __('Installed on This Site', 'shopagg-app-store'),
            __('This theme is already installed. Activate it when you want to switch your site design, or remove it if you no longer need it.', 'shopagg-app-store')
        );
        ?>
        <div class="shopagg-action-primary">
            <button class="button button-primary shopagg-action-button shopagg-action-button-primary shopagg-toggle-resource-btn"
                    data-resource-type="theme"
                    data-toggle-action="activate"
                    data-target="<?php echo esc_attr($status['target']); ?>">
                <?php esc_html_e('Activate Theme', 'shopagg-app-store'); ?>
            </button>
        </div>
        <div class="shopagg-action-secondary">
            <a class="button button-secondary shopagg-action-button shopagg-action-button-danger" href="<?php echo esc_url($this->get_theme_delete_url($status['target'])); ?>">
                <?php esc_html_e('Delete', 'shopagg-app-store'); ?>
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

    private function get_resource_detail_url($resource_id) {
        return admin_url('admin.php?page=shopagg-app-store&action=detail&resource_id=' . absint($resource_id));
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
        $resource_result = $api->get('resources/' . $resource_id);

        if (is_wp_error($resource_result) || empty($resource_result['resource']) || shopagg_app_store_is_client_resource($resource_result['resource'])) {
            wp_send_json_error(['message' => __('This resource cannot be installed from inside the ShopAGG App Store plugin.', 'shopagg-app-store')]);
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

    public function ajax_submit_review() {
        check_ajax_referer('shopagg_app_store_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'shopagg-app-store')]);
        }

        if (! shopagg_app_store_is_logged_in()) {
            wp_send_json_error(['message' => __('Please log in first.', 'shopagg-app-store')]);
        }

        $resource_id = isset($_POST['resource_id']) ? absint($_POST['resource_id']) : 0;
        $rating = isset($_POST['rating']) ? (float) wp_unslash($_POST['rating']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';

        if (! $resource_id || $rating < 1 || $rating > 5 || $content === '') {
            wp_send_json_error(['message' => __('Please complete the rating and review before submitting.', 'shopagg-app-store')]);
        }

        $api = ShopAGG_App_Store_API_Client::instance();
        $resource_result = $api->get('resources/' . $resource_id);

        if (is_wp_error($resource_result) || empty($resource_result['resource'])) {
            wp_send_json_error(['message' => __('Invalid resource.', 'shopagg-app-store')]);
        }

        $resource = $resource_result['resource'];

        if (shopagg_app_store_is_client_resource($resource)) {
            wp_send_json_error(['message' => __('This resource cannot be reviewed from inside the ShopAGG App Store plugin.', 'shopagg-app-store')]);
        }

        $status = $this->get_resource_install_state($resource);
        if (empty($status['installed']) || empty($status['installed_version'])) {
            wp_send_json_error(['message' => __('Install this resource on your site first, then you can publish a review.', 'shopagg-app-store')]);
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
            'message' => isset($result['message']) ? $result['message'] : __('Your review has been saved.', 'shopagg-app-store'),
        ]);
    }
}
