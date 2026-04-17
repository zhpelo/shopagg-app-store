/**
 * ShopAGG App Store JavaScript
 */
(function ($) {
    'use strict';

    var i18n = (window.shopaggAppStore && window.shopaggAppStore.i18n) || {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    var ShopAGGAppStore = {
        pollTimer: null,

        init: function () {
            this.bindTokenForm();
            this.bindLogout();
            this.bindCatalogFilters();
            this.bindInstall();
            this.bindToggleResource();
            this.bindPurchase();
            this.bindInlinePayment();
            this.bindReviewForm();
            this.bindDeleteConfirm();
            this.bindSectionSpy();
        },

        showMessage: function ($target, type, message) {
            if (!$target || !$target.length) {
                return;
            }

            $target.removeClass('success error').addClass(type).text(message || '');
        },

        resetMessage: function ($target) {
            if ($target && $target.length) {
                $target.removeClass('success error').text('');
            }
        },

        setButtonState: function ($btn, text, disabled) {
            if (!$btn || !$btn.length) {
                return;
            }

            $btn.text(text);
            $btn.prop('disabled', !!disabled);
        },

        escapeHtml: function (value) {
            return $('<div/>').text(value || '').html();
        },

        clearPolling: function () {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        bindCatalogFilters: function () {
            var self = this;

            var applyFilters = function () {
                var keyword = ($('#shopagg-market-search').val() || '').toLowerCase();
                var type = $('#shopagg-market-type').val() || 'all';
                var price = $('#shopagg-market-price').val() || 'all';
                var visibleCount = 0;

                $('.shopagg-resource-card').each(function () {
                    var $card = $(this);
                    var matchesKeyword = !keyword || String($card.data('search') || '').indexOf(keyword) !== -1;
                    var matchesType = type === 'all' || $card.data('type') === type;
                    var matchesPrice = price === 'all' || $card.data('price') === price;
                    var visible = matchesKeyword && matchesType && matchesPrice;

                    $card.toggle(visible);
                    if (visible) {
                        visibleCount += 1;
                    }
                });

                $('#shopagg-filter-empty').toggle(visibleCount === 0 && $('.shopagg-resource-card').length > 0);
            };

            $(document).on('input change', '#shopagg-market-search, #shopagg-market-type, #shopagg-market-price', applyFilters);
            applyFilters();
        },

        renderInlinePaymentPanel: function ($msg, options) {
            if (!$msg || !$msg.length) {
                return;
            }

            $('#shopagg-inline-payment-panel').remove();

            var resourceName = options.resourceName || '当前资源';
            var introText = options.existingOrder
                ? t('existingOrderIntro', '此资源已有未付款订单，请完成支付后继续。')
                : t('orderCreatedIntro', '订单已创建，请选择支付方式继续。');

            var $panel = $('<div/>', {
                id: 'shopagg-inline-payment-panel',
                'class': 'shopagg-inline-payment-panel'
            });

            $panel.append($('<p/>', {
                'class': 'shopagg-inline-payment-title',
                text: resourceName
            }));

            $panel.append($('<p/>', {
                'class': 'shopagg-inline-payment-desc',
                text: introText
            }));

            $panel.append(
                $('<p/>', {
                    'class': 'shopagg-inline-payment-amount',
                    html: '金额：<strong>¥' + this.escapeHtml(options.amount) + '</strong>'
                })
            );

            var $actions = $('<div/>', {
                'class': 'shopagg-inline-payment-methods'
            });

            $actions.append($('<button/>', {
                type: 'button',
                'class': 'button button-primary shopagg-inline-pay-method-btn',
                'data-method': 'alipay',
                'data-order-id': options.orderId,
                'data-resource-id': options.resourceId,
                'data-resource-name': resourceName,
                text: '支付宝'
            }));

            $actions.append($('<button/>', {
                type: 'button',
                'class': 'button button-primary shopagg-inline-pay-method-btn',
                'data-method': 'wechat',
                'data-order-id': options.orderId,
                'data-resource-id': options.resourceId,
                'data-resource-name': resourceName,
                text: '微信支付'
            }));

            $panel.append($actions);
            $panel.append($('<div/>', {
                'class': 'shopagg-inline-payment-qr'
            }).hide());
            $panel.append($('<div/>', {
                'class': 'shopagg-inline-payment-install'
            }).hide());
            $panel.append($('<p/>', {
                'class': 'shopagg-inline-payment-status',
                text: t('choosePaymentMethod', '请选择支付方式。')
            }));

            $msg.after($panel);
        },

        bindInlinePayment: function () {
            var self = this;

            $(document).on('click', '.shopagg-inline-pay-method-btn', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var $panel = $btn.closest('.shopagg-inline-payment-panel');
                var method = $btn.data('method');
                var orderId = $btn.data('order-id');
                var resourceId = $btn.data('resource-id');
                var resourceName = $btn.data('resource-name') || '当前资源';

                $panel.find('.shopagg-inline-pay-method-btn').prop('disabled', true);
                $panel.find('.shopagg-inline-payment-install').hide().empty();
                $panel.find('.shopagg-inline-payment-qr').hide().empty();
                $panel.find('.shopagg-inline-payment-status').text(t('startingPayment', '正在发起支付...'));

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_pay',
                        nonce: shopaggAppStore.nonce,
                        order_id: orderId,
                        payment_method: method
                    },
                    success: function (response) {
                        if (!response.success) {
                            $panel.find('.shopagg-inline-payment-status').text(response.data.message || '支付失败。');
                            $panel.find('.shopagg-inline-pay-method-btn').prop('disabled', false);
                            return;
                        }

                        if (method === 'alipay' && response.data.form_html) {
                            var payWin = window.open('', '_blank', 'width=800,height=600');
                            if (payWin) {
                                payWin.document.write(response.data.form_html);
                                payWin.document.close();
                                $panel.find('.shopagg-inline-payment-status').text(t('completeAlipayInWindow', '请在新窗口中完成支付宝支付。'));
                            } else {
                                $panel.find('.shopagg-inline-payment-status').text(t('popupBlocked', '浏览器拦截了弹窗，请允许弹窗后重试。'));
                                $panel.find('.shopagg-inline-pay-method-btn').prop('disabled', false);
                                return;
                            }
                        } else if (method === 'wechat' && response.data.code_url) {
                            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(response.data.code_url);
                            $panel.find('.shopagg-inline-payment-qr')
                                .html('<img src="' + qrUrl + '" alt="WeChat Pay QR Code" width="200" height="200" />')
                                .show();
                            $panel.find('.shopagg-inline-payment-status').text(t('scanWechat', '请使用微信扫码完成支付。'));
                        } else {
                            $panel.find('.shopagg-inline-payment-status').text('未知的支付响应。');
                            $panel.find('.shopagg-inline-pay-method-btn').prop('disabled', false);
                            return;
                        }

                        self.clearPolling();
                        self.pollTimer = setInterval(function () {
                            self.checkOrderStatus(orderId, function (status) {
                                if (status.paid) {
                                    self.clearPolling();
                                    self.renderInlineInstallActions($panel, {
                                        resourceId: status.resource_id || resourceId,
                                        resourceName: status.resource_name || resourceName
                                    });
                                }
                            });
                        }, 3000);
                    },
                    error: function () {
                        $panel.find('.shopagg-inline-payment-status').text('网络错误，请重试。');
                        $panel.find('.shopagg-inline-pay-method-btn').prop('disabled', false);
                    }
                });
            });

            $(document).on('click', '.shopagg-inline-install-btn', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var resourceId = $btn.data('resource-id');
                var $panel = $btn.closest('.shopagg-inline-payment-panel');

                $btn.prop('disabled', true).text('正在安装...');
                self.installPurchasedResource(resourceId, $panel, $btn, true);
            });

            $(document).on('click', '.shopagg-inline-refresh-btn', function () {
                window.location.reload();
            });
        },

        renderInlineInstallActions: function ($panel, payload) {
            var resourceName = payload.resourceName || '当前资源';
            var resourceId = payload.resourceId;

            $panel.find('.shopagg-inline-payment-qr').hide().empty();
            $panel.find('.shopagg-inline-payment-status').html('<span style="color:green;font-size:16px;">&#10004; ' + this.escapeHtml(t('paymentSuccessInstall', '付款成功。您现在可以安装')) + this.escapeHtml(resourceName) + '</span>');
            $panel.find('.shopagg-inline-payment-install')
                .html(
                    '<button type="button" class="button button-primary shopagg-inline-install-btn" data-resource-id="' + this.escapeHtml(resourceId) + '">立即安装</button>' +
                    '<button type="button" class="button shopagg-inline-refresh-btn">刷新页面</button>'
                )
                .show();
        },

        /**
         * Token form submission.
         */
        bindTokenForm: function () {
            $(document).on('submit', '#shopagg-app-store-token-form', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $('#shopagg-connect-btn');
                var $msg = $('#shopagg-token-message');

                ShopAGGAppStore.setButtonState($btn, t('connecting', '连接中...'), true);
                ShopAGGAppStore.resetMessage($msg);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_save_token',
                        nonce: shopaggAppStore.nonce,
                        token: $form.find('#shopagg-api-token').val()
                    },
                    success: function (response) {
                        if (response.success) {
                            ShopAGGAppStore.showMessage($msg, 'success', response.data.message);
                            setTimeout(function () {
                                window.location.reload();
                            }, 500);
                        } else {
                            ShopAGGAppStore.showMessage($msg, 'error', response.data.message);
                            ShopAGGAppStore.setButtonState($btn, t('connect', '连接'), false);
                        }
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', t('connectionFailed', '连接失败。请重试。'));
                        ShopAGGAppStore.setButtonState($btn, t('connect', '连接'), false);
                    }
                });
            });

            $(document).on('click', '#shopagg-toggle-token-form', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var $panel = $('#shopagg-token-replace');
                var isHidden = $panel.prop('hidden');

                $panel.prop('hidden', !isHidden);
                $btn.text(isHidden ? ($btn.data('hide-text') || '取消更换') : ($btn.data('show-text') || '更换API Token'));

                if (isHidden) {
                    $('#shopagg-api-token').trigger('focus');
                }
            });
        },

        /**
         * Logout.
         */
        bindLogout: function () {
            $(document).on('click', '#shopagg-logout', function (e) {
                e.preventDefault();

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_logout',
                        nonce: shopaggAppStore.nonce
                    },
                    success: function () {
                        window.location.reload();
                    }
                });
            });
        },

        /**
         * Install button.
         */
        bindInstall: function () {
            $(document).on('click', '.shopagg-install-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var resourceId = $btn.data('resource-id');
                var $msg = $('#detail-message');
                var isDetailPage = $btn.closest('.shopagg-detail-actions').length > 0;

                if (isDetailPage && !confirm('确定要安装这个资源吗？')) {
                    return;
                }

                ShopAGGAppStore.setButtonState($btn, t('installing', '正在安装...'), true);
                ShopAGGAppStore.resetMessage($msg);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_install',
                        nonce: shopaggAppStore.nonce,
                        resource_id: resourceId
                    },
                    success: function (response) {
                        if (response.success) {
                            ShopAGGAppStore.showMessage($msg, 'success', response.data.message);
                            ShopAGGAppStore.setButtonState($btn, '已安装', true);
                            setTimeout(function () {
                                window.location.reload();
                            }, 900);
                        } else {
                            ShopAGGAppStore.showMessage($msg, 'error', response.data.message);
                            ShopAGGAppStore.setButtonState($btn, t('install', '安装'), false);
                        }
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', '安装失败，请重试。');
                        ShopAGGAppStore.setButtonState($btn, t('install', '安装'), false);
                    }
                });
            });
        },

        bindToggleResource: function () {
            $(document).on('click', '.shopagg-toggle-resource-btn', function (e) {
                e.preventDefault();

                var $btn = $(this);
                var $msg = $('#detail-message');

                ShopAGGAppStore.setButtonState($btn, t('processing', '处理中...'), true);
                ShopAGGAppStore.resetMessage($msg);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_toggle_resource',
                        nonce: shopaggAppStore.nonce,
                        resource_type: $btn.data('resource-type'),
                        toggle_action: $btn.data('toggle-action'),
                        target: $btn.data('target')
                    },
                    success: function (response) {
                        if (!response.success) {
                            ShopAGGAppStore.showMessage($msg, 'error', response.data.message || '操作失败。');
                            ShopAGGAppStore.setButtonState($btn, t('retry', '重试'), false);
                            return;
                        }

                        ShopAGGAppStore.showMessage($msg, 'success', response.data.message || '已完成。');
                        setTimeout(function () {
                            window.location.reload();
                        }, 700);
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', '操作失败，请重试。');
                        ShopAGGAppStore.setButtonState($btn, t('retry', '重试'), false);
                    }
                });
            });
        },

        /**
         * Purchase button - create order then show payment modal.
         */
        bindPurchase: function () {
            var self = this;

            $(document).on('click', '.shopagg-purchase-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var resourceId = $btn.data('resource-id');
                var $msg = $('#detail-message');

                self.setButtonState($btn, t('creatingOrder', '正在创建订单...'), true);
                self.resetMessage($msg);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_purchase',
                        nonce: shopaggAppStore.nonce,
                        resource_id: resourceId
                    },
                    success: function (response) {
                        if (response.success) {
                            self.setButtonState($btn, t('purchase', '购买'), false);

                            if (response.data.owned) {
                                self.showMessage($msg, 'success', response.data.message || '你已经拥有这个资源。');
                                setTimeout(function () {
                                    window.location.reload();
                                }, 700);
                                return;
                            }

                            self.showMessage($msg, 'success', response.data.message || '订单已就绪，请在下方选择支付方式。');
                            self.renderInlinePaymentPanel($msg, {
                                orderId: response.data.order_id,
                                amount: response.data.amount,
                                resourceId: response.data.resource_id || resourceId,
                                resourceName: response.data.resource_name || '',
                                existingOrder: !!response.data.existing_order,
                                message: response.data.message || ''
                            });
                        } else {
                            self.showMessage($msg, 'error', response.data.message);
                            self.setButtonState($btn, t('purchase', '购买'), false);
                        }
                    },
                    error: function () {
                        self.showMessage($msg, 'error', '创建订单失败，请重试。');
                        self.setButtonState($btn, t('purchase', '购买'), false);
                    }
                });
            });
        },

        /**
         * Show payment method selection modal.
         */
        showPaymentModal: function (options) {
            var self = this;
            var orderId = options.orderId;
            var amount = options.amount;
            var resourceId = options.resourceId;
            var resourceName = options.resourceName || '当前资源';
            var introText = options.existingOrder
                ? '资源“' + resourceName + '”已有未支付订单，请在下方继续完成支付。'
                : '完成“' + resourceName + '”的支付后即可立即安装。';

            if (!orderId) {
                window.alert('订单已创建，但没有返回订单号，请刷新后重试。');
                return;
            }

            self.clearPolling();
            $('#shopagg-payment-modal').remove();
            var $modal = $('<div/>', {
                id: 'shopagg-payment-modal',
                'class': 'shopagg-modal-overlay'
            });
            var $dialog = $('<div/>', {
                'class': 'shopagg-modal'
            });
            var $header = $('<div/>', {
                'class': 'shopagg-modal-header'
            });
            var $body = $('<div/>', {
                'class': 'shopagg-modal-body'
            });
            var $methods = $('<div/>', {
                'class': 'shopagg-payment-methods'
            });
            var $status = $('<div/>', {
                'class': 'shopagg-payment-status'
            }).hide();

            $header.append($('<h3/>').text('完成购买'));
            $header.append($('<button/>', {
                'class': 'shopagg-modal-close',
                type: 'button',
                html: '&times;'
            }));

            $body.append($('<p/>', {
                'class': 'shopagg-payment-intro',
                text: introText
            }));
            $body.append(
                $('<p/>', {
                    'class': 'shopagg-payment-amount',
                    html: '金额：<strong>¥' + self.escapeHtml(amount) + '</strong>'
                })
            );

            $methods.append($('<button/>', {
                type: 'button',
                'class': 'button button-primary shopagg-pay-method-btn',
                'data-method': 'alipay',
                text: '支付宝'
            }));
            $methods.append($('<button/>', {
                type: 'button',
                'class': 'button button-primary shopagg-pay-method-btn',
                'data-method': 'wechat',
                text: '微信支付'
            }));

            $status.append($('<div/>', {
                'class': 'shopagg-qr-container'
            }).hide());
            $status.append($('<div/>', {
                'class': 'shopagg-payment-actions'
            }).hide());
            $status.append($('<p/>', {
                'class': 'shopagg-status-text',
                text: '等待支付中...'
            }));

            $body.append($methods);
            $body.append($status);
            $dialog.append($header);
            $dialog.append($body);
            $modal.append($dialog);
            $('body').append($modal);

            if (!$('#shopagg-payment-modal').length) {
                window.alert('无法打开支付弹窗，请刷新页面后重试。');
                return;
            }

            $modal.on('click', '.shopagg-modal-close, .shopagg-modal-overlay', function (e) {
                if (e.target === this) {
                    self.clearPolling();
                    $modal.remove();
                }
            });

            $modal.on('click', '.shopagg-pay-method-btn', function () {
                var $methodBtn = $(this);
                var method = $methodBtn.data('method');

                $modal.find('.shopagg-payment-methods').hide();
                $modal.find('.shopagg-payment-status').show();
                $modal.find('.shopagg-payment-actions').hide().empty();
                $modal.find('.shopagg-qr-container').hide().empty();
                $modal.find('.shopagg-status-text').text('正在发起支付...');
                $modal.find('.shopagg-payment-intro').text('订单 #' + orderId + ' 正在准备支付。');
                $methodBtn.prop('disabled', true);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_pay',
                        nonce: shopaggAppStore.nonce,
                        order_id: orderId,
                        payment_method: method
                    },
                    success: function (response) {
                        if (!response.success) {
                            $modal.find('.shopagg-status-text').text(response.data.message || '支付失败。');
                            $modal.find('.shopagg-payment-methods').show();
                            $modal.find('.shopagg-payment-status').hide();
                            $methodBtn.prop('disabled', false);
                            return;
                        }

                        if (method === 'alipay' && response.data.form_html) {
                            var payWin = window.open('', '_blank', 'width=800,height=600');
                            if (payWin) {
                                payWin.document.write(response.data.form_html);
                                payWin.document.close();
                            } else {
                                $modal.find('.shopagg-status-text').text('弹窗被浏览器拦截，请允许弹窗后重试。');
                                $modal.find('.shopagg-payment-methods').show();
                                $modal.find('.shopagg-payment-status').hide();
                                $methodBtn.prop('disabled', false);
                                return;
                            }
                            $modal.find('.shopagg-status-text').text('请在新窗口完成支付...');
                        } else if (method === 'wechat' && response.data.code_url) {
                            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(response.data.code_url);
                            $modal.find('.shopagg-qr-container')
                                .html('<img src="' + qrUrl + '" alt="WeChat Pay QR Code" width="200" height="200" />')
                                .show();
                            $modal.find('.shopagg-status-text').text('请使用微信扫码支付。');
                        } else {
                            $modal.find('.shopagg-status-text').text('未知的支付响应。');
                            $modal.find('.shopagg-payment-methods').show();
                            $modal.find('.shopagg-payment-status').hide();
                            $methodBtn.prop('disabled', false);
                            return;
                        }

                        self.clearPolling();
                        self.pollTimer = setInterval(function () {
                            self.checkOrderStatus(orderId, function (status) {
                                if (status.paid) {
                                    self.clearPolling();
                                    self.renderPaymentSuccess($modal, {
                                        resourceId: status.resource_id || resourceId,
                                        resourceName: status.resource_name || resourceName
                                    });
                                }
                            });
                        }, 3000);
                    },
                    error: function () {
                        $modal.find('.shopagg-status-text').text('网络错误，请重试。');
                        $modal.find('.shopagg-payment-methods').show();
                        $modal.find('.shopagg-payment-status').hide();
                        $methodBtn.prop('disabled', false);
                    }
                });
            });
        },

        renderPaymentSuccess: function ($modal, payload) {
            var self = this;
            var resourceId = payload.resourceId;
            var resourceName = payload.resourceName || '当前资源';

            $modal.find('.shopagg-qr-container').hide().empty();
            $modal.find('.shopagg-payment-intro').text('资源“' + resourceName + '”已购买成功。');
            $modal.find('.shopagg-status-text').html('<span style="color:green;font-size:18px;">&#10004; 支付成功！</span>');
            $modal.find('.shopagg-payment-actions')
                .html(
                    '<button type="button" class="button button-primary shopagg-install-after-pay">立即安装</button>' +
                    '<button type="button" class="button shopagg-refresh-after-pay">刷新页面</button>'
                )
                .show();

            $modal.off('click', '.shopagg-install-after-pay');
            $modal.on('click', '.shopagg-install-after-pay', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('正在安装...');
                self.installPurchasedResource(resourceId, $modal, $btn);
            });

            $modal.off('click', '.shopagg-refresh-after-pay');
            $modal.on('click', '.shopagg-refresh-after-pay', function () {
                window.location.reload();
            });
        },

        installPurchasedResource: function (resourceId, $container, $btn, isInline) {
            $.ajax({
                url: shopaggAppStore.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'shopagg_app_store_install',
                    nonce: shopaggAppStore.nonce,
                    resource_id: resourceId
                },
                success: function (response) {
                    if (!response.success) {
                        if (isInline) {
                            $container.find('.shopagg-inline-payment-status').text(response.data.message || '安装失败。');
                        } else {
                            $container.find('.shopagg-status-text').text(response.data.message || '安装失败。');
                        }
                        $btn.prop('disabled', false).text('立即安装');
                        return;
                    }

                    if (isInline) {
                        $container.find('.shopagg-inline-payment-install').html(
                            '<button type="button" class="button button-primary shopagg-inline-refresh-btn">已安装，刷新页面</button>'
                        );
                        $container.find('.shopagg-inline-payment-status').html('<span style="color:green;font-size:16px;">&#10004; 安装成功！</span>');
                        return;
                    }

                    $container.find('.shopagg-payment-actions').html(
                        '<button type="button" class="button button-primary shopagg-refresh-after-pay">已安装，刷新页面</button>'
                    );

                    $container.find('.shopagg-status-text').html('<span style="color:green;font-size:18px;">&#10004; 安装成功！</span>');
                },
                error: function () {
                    if (isInline) {
                        $container.find('.shopagg-inline-payment-status').text('安装失败，请重试。');
                    } else {
                        $container.find('.shopagg-status-text').text('安装失败，请重试。');
                    }
                    $btn.prop('disabled', false).text('立即安装');
                }
            });
        },

        /**
         * Check order payment status.
         */
        checkOrderStatus: function (orderId, callback) {
            $.ajax({
                url: shopaggAppStore.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'shopagg_app_store_order_status',
                    nonce: shopaggAppStore.nonce,
                    order_id: orderId
                },
                success: function (response) {
                    if (response.success) {
                        callback(response.data);
                    }
                }
            });
        },

        /**
         * Confirm delete only on detail page action area.
         */
        bindDeleteConfirm: function () {
            $(document).on('click', '.shopagg-delete-link', function (e) {
                var $link = $(this);
                var isDetailPage = $link.closest('.shopagg-detail-actions').length > 0;

                if (!isDetailPage) {
                    return;
                }

                if (!confirm('确定要删除这个资源吗？')) {
                    e.preventDefault();
                }
            });
        },

        bindReviewForm: function () {
            $(document).on('submit', '.shopagg-review-form', function (e) {
                e.preventDefault();

                var $form = $(this);
                var $btn = $form.find('.shopagg-review-submit-btn');
                var $msg = $form.find('.shopagg-review-message');
                var defaultText = $btn.data('default-text') || t('publishReview', '发布评价');

                ShopAGGAppStore.setButtonState($btn, t('savingReview', '正在保存评价...'), true);
                ShopAGGAppStore.resetMessage($msg);

                $.ajax({
                    url: shopaggAppStore.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'shopagg_app_store_submit_review',
                        nonce: shopaggAppStore.nonce,
                        resource_id: $form.data('resource-id'),
                        rating: $form.find('input[name="review_rating"]:checked').val(),
                        title: $form.find('input[name="review_title"]').val(),
                        content: $form.find('textarea[name="review_content"]').val()
                    },
                    success: function (response) {
                        if (!response.success) {
                            ShopAGGAppStore.showMessage($msg, 'error', response.data.message || t('reviewSaveFailed', '保存评价失败，请重试。'));
                            ShopAGGAppStore.setButtonState($btn, defaultText, false);
                            return;
                        }

                        ShopAGGAppStore.showMessage($msg, 'success', response.data.message || t('reviewSaved', '你的评价已保存。'));
                        setTimeout(function () {
                            window.location.reload();
                        }, 900);
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', t('reviewSaveFailed', '保存评价失败，请重试。'));
                        ShopAGGAppStore.setButtonState($btn, defaultText, false);
                    }
                });
            });
        },

        bindSectionSpy: function () {
            var $anchorLinks = $('.shopagg-admin-sidebar-link[href^="#"]');
            var sections = [];
            var observer = null;
            var updateActiveLink = function (sectionId) {
                if (!sectionId) {
                    return;
                }

                $anchorLinks.removeClass('is-active');
                $anchorLinks.filter('[href="#' + sectionId + '"]').addClass('is-active');
            };

            if (!$anchorLinks.length) {
                return;
            }

            $anchorLinks.each(function () {
                var targetId = ($(this).attr('href') || '').replace('#', '');
                var target = document.getElementById(targetId);

                if (target) {
                    sections.push(target);
                }
            });

            if (!sections.length) {
                return;
            }

            $(document).on('click', '.shopagg-admin-sidebar-link[href^="#"]', function (e) {
                var href = $(this).attr('href') || '';
                var targetId = href.replace('#', '');
                var target = document.getElementById(targetId);

                if (!target) {
                    return;
                }

                e.preventDefault();
                updateActiveLink(targetId);

                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + targetId);
                }

                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });

            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver(function (entries) {
                    var visibleEntries = entries.filter(function (entry) {
                        return entry.isIntersecting;
                    });

                    if (!visibleEntries.length) {
                        return;
                    }

                    visibleEntries.sort(function (left, right) {
                        return left.boundingClientRect.top - right.boundingClientRect.top;
                    });

                    updateActiveLink(visibleEntries[0].target.id);
                }, {
                    root: null,
                    rootMargin: '-18% 0px -58% 0px',
                    threshold: [0.1, 0.25, 0.5]
                });

                sections.forEach(function (section) {
                    observer.observe(section);
                });
            } else {
                $(window).on('scroll.shopaggSectionSpy resize.shopaggSectionSpy', function () {
                    var currentId = sections[0].id;
                    var offsetTop = window.scrollY + 160;

                    sections.forEach(function (section) {
                        if (section.offsetTop <= offsetTop) {
                            currentId = section.id;
                        }
                    });

                    updateActiveLink(currentId);
                });
            }

            if (window.location.hash) {
                updateActiveLink(window.location.hash.replace('#', ''));
            } else {
                updateActiveLink(sections[0].id);
            }
        }
    };

    $(document).ready(function () {
        ShopAGGAppStore.init();
    });

})(jQuery);
