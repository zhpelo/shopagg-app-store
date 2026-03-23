/**
 * ShopAGG App Store JavaScript
 */
(function ($) {
    'use strict';

    var ShopAGGAppStore = {
        pollTimer: null,

        init: function () {
            this.bindTokenForm();
            this.bindLogout();
            this.bindInstall();
            this.bindPurchase();
            this.bindDeleteConfirm();
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

        clearPolling: function () {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
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

                ShopAGGAppStore.setButtonState($btn, 'Connecting...', true);
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
                            ShopAGGAppStore.setButtonState($btn, 'Connect', false);
                        }
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', 'Connection failed. Please try again.');
                        ShopAGGAppStore.setButtonState($btn, 'Connect', false);
                    }
                });
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

                if (isDetailPage && !confirm('Are you sure you want to install this resource?')) {
                    return;
                }

                ShopAGGAppStore.setButtonState($btn, 'Installing...', true);
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

                            if (response.data.activate_url) {
                                var activateLabel = response.data.activate_label || 'Activate';
                                $btn.replaceWith('<a class="button button-primary shopagg-activate-btn" href="' + response.data.activate_url + '">' + activateLabel + '</a>');
                            } else {
                                ShopAGGAppStore.setButtonState($btn, 'Installed', true);
                            }
                        } else {
                            ShopAGGAppStore.showMessage($msg, 'error', response.data.message);
                            ShopAGGAppStore.setButtonState($btn, 'Install', false);
                        }
                    },
                    error: function () {
                        ShopAGGAppStore.showMessage($msg, 'error', 'Installation failed. Please try again.');
                        ShopAGGAppStore.setButtonState($btn, 'Install', false);
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

                self.setButtonState($btn, 'Creating order...', true);
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
                            self.setButtonState($btn, 'Purchase', false);

                            if (response.data.owned) {
                                self.showMessage($msg, 'success', response.data.message || 'You already own this resource.');
                                setTimeout(function () {
                                    window.location.reload();
                                }, 700);
                                return;
                            }

                            self.showPaymentModal({
                                orderId: response.data.order_id,
                                amount: response.data.amount,
                                resourceId: response.data.resource_id || resourceId,
                                resourceName: response.data.resource_name || '',
                                existingOrder: !!response.data.existing_order,
                                message: response.data.message || ''
                            });
                        } else {
                            self.showMessage($msg, 'error', response.data.message);
                            self.setButtonState($btn, 'Purchase', false);
                        }
                    },
                    error: function () {
                        self.showMessage($msg, 'error', 'Failed to create order. Please try again.');
                        self.setButtonState($btn, 'Purchase', false);
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
            var resourceName = options.resourceName || 'this resource';
            var introText = options.existingOrder
                ? 'An unpaid order already exists for ' + resourceName + '. Continue with payment below.'
                : 'Complete the payment for ' + resourceName + ' and you can install it immediately.';

            self.clearPolling();
            $('#shopagg-payment-modal').remove();

            var modalHtml = '<div id="shopagg-payment-modal" class="shopagg-modal-overlay">' +
                '<div class="shopagg-modal">' +
                    '<div class="shopagg-modal-header">' +
                        '<h3>Complete Purchase</h3>' +
                        '<button class="shopagg-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="shopagg-modal-body">' +
                        '<p class="shopagg-payment-intro">' + introText + '</p>' +
                        '<p class="shopagg-payment-amount">Amount: <strong>¥' + amount + '</strong></p>' +
                        '<div class="shopagg-payment-methods">' +
                            '<button class="button button-primary shopagg-pay-method-btn" data-method="alipay">' +
                                'Alipay' +
                            '</button>' +
                            '<button class="button button-primary shopagg-pay-method-btn" data-method="wechat">' +
                                'WeChat Pay' +
                            '</button>' +
                        '</div>' +
                        '<div class="shopagg-payment-status" style="display:none;">' +
                            '<div class="shopagg-qr-container" style="display:none;"></div>' +
                            '<div class="shopagg-payment-actions" style="display:none;"></div>' +
                            '<p class="shopagg-status-text">Waiting for payment...</p>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(modalHtml);

            var $modal = $('#shopagg-payment-modal');

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
                $modal.find('.shopagg-status-text').text('Initiating payment...');
                $modal.find('.shopagg-payment-intro').text('Order #' + orderId + ' is being prepared for payment.');
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
                            $modal.find('.shopagg-status-text').text(response.data.message || 'Payment failed.');
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
                                $modal.find('.shopagg-status-text').text('Pop-up blocked. Please allow pop-ups and try again.');
                                $modal.find('.shopagg-payment-methods').show();
                                $modal.find('.shopagg-payment-status').hide();
                                $methodBtn.prop('disabled', false);
                                return;
                            }
                            $modal.find('.shopagg-status-text').text('Please complete payment in the new window...');
                        } else if (method === 'wechat' && response.data.code_url) {
                            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(response.data.code_url);
                            $modal.find('.shopagg-qr-container')
                                .html('<img src="' + qrUrl + '" alt="WeChat Pay QR Code" width="200" height="200" />')
                                .show();
                            $modal.find('.shopagg-status-text').text('Please scan the QR code with WeChat to pay');
                        } else {
                            $modal.find('.shopagg-status-text').text('Unknown payment response.');
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
                        $modal.find('.shopagg-status-text').text('Network error. Please try again.');
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
            var resourceName = payload.resourceName || 'this resource';

            $modal.find('.shopagg-qr-container').hide().empty();
            $modal.find('.shopagg-payment-intro').text(resourceName + ' has been purchased successfully.');
            $modal.find('.shopagg-status-text').html('<span style="color:green;font-size:18px;">&#10004; Payment successful!</span>');
            $modal.find('.shopagg-payment-actions')
                .html(
                    '<button type="button" class="button button-primary shopagg-install-after-pay">Install Now</button>' +
                    '<button type="button" class="button shopagg-refresh-after-pay">Refresh Page</button>'
                )
                .show();

            $modal.off('click', '.shopagg-install-after-pay');
            $modal.on('click', '.shopagg-install-after-pay', function () {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Installing...');
                self.installPurchasedResource(resourceId, $modal, $btn);
            });

            $modal.off('click', '.shopagg-refresh-after-pay');
            $modal.on('click', '.shopagg-refresh-after-pay', function () {
                window.location.reload();
            });
        },

        installPurchasedResource: function (resourceId, $modal, $btn) {
            var self = this;

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
                        $modal.find('.shopagg-status-text').text(response.data.message || 'Installation failed.');
                        $btn.prop('disabled', false).text('Install Now');
                        return;
                    }

                    if (response.data.activate_url) {
                        var activateLabel = response.data.activate_label || 'Activate';
                        $modal.find('.shopagg-payment-actions').html(
                            '<a class="button button-primary" href="' + response.data.activate_url + '">' + activateLabel + '</a>' +
                            '<button type="button" class="button shopagg-refresh-after-pay">Done</button>'
                        );
                    } else {
                        $modal.find('.shopagg-payment-actions').html(
                            '<button type="button" class="button button-primary shopagg-refresh-after-pay">Installed, Refresh</button>'
                        );
                    }

                    $modal.find('.shopagg-status-text').html('<span style="color:green;font-size:18px;">&#10004; Installation successful!</span>');
                },
                error: function () {
                    $modal.find('.shopagg-status-text').text('Installation failed. Please try again.');
                    $btn.prop('disabled', false).text('Install Now');
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

                if (!confirm('Are you sure you want to delete this resource?')) {
                    e.preventDefault();
                }
            });
        }
    };

    $(document).ready(function () {
        ShopAGGAppStore.init();
    });

})(jQuery);
