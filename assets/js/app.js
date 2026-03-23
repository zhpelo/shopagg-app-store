/**
 * ShopAGG App Store JavaScript
 */
(function ($) {
    'use strict';

    var ShopAGGAppStore = {
        init: function () {
            this.bindTokenForm();
            this.bindLogout();
            this.bindInstall();
            this.bindPurchase();
            this.bindDeleteConfirm();
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

                $btn.prop('disabled', true).text('Connecting...');
                $msg.removeClass('success error').text('');

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
                            $msg.addClass('success').text(response.data.message);
                            setTimeout(function () {
                                window.location.reload();
                            }, 500);
                        } else {
                            $msg.addClass('error').text(response.data.message);
                            $btn.prop('disabled', false).text('Connect');
                        }
                    },
                    error: function () {
                        $msg.addClass('error').text('Connection failed. Please try again.');
                        $btn.prop('disabled', false).text('Connect');
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

                $btn.prop('disabled', true).text('Installing...');
                $msg.removeClass('success error').text('');

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
                            $msg.addClass('success').text(response.data.message);

                            if (response.data.activate_url) {
                                var activateLabel = response.data.activate_label || 'Activate';
                                $btn.replaceWith('<a class="button button-primary shopagg-activate-btn" href="' + response.data.activate_url + '">' + activateLabel + '</a>');
                            } else {
                                $btn.text('Installed').prop('disabled', true);
                            }
                        } else {
                            $msg.addClass('error').text(response.data.message);
                            $btn.prop('disabled', false).text('Install');
                        }
                    },
                    error: function () {
                        $msg.addClass('error').text('Installation failed. Please try again.');
                        $btn.prop('disabled', false).text('Install');
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

                $btn.prop('disabled', true).text('Creating order...');
                $msg.removeClass('success error').text('');

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
                            $btn.prop('disabled', false).text('Purchase');
                            self.showPaymentModal(response.data.order_id, response.data.amount);
                        } else {
                            $msg.addClass('error').text(response.data.message);
                            $btn.prop('disabled', false).text('Purchase');
                        }
                    },
                    error: function () {
                        $msg.addClass('error').text('Failed to create order. Please try again.');
                        $btn.prop('disabled', false).text('Purchase');
                    }
                });
            });
        },

        /**
         * Show payment method selection modal.
         */
        showPaymentModal: function (orderId, amount) {
            var self = this;

            // Remove existing modal
            $('#shopagg-payment-modal').remove();

            var modalHtml = '<div id="shopagg-payment-modal" class="shopagg-modal-overlay">' +
                '<div class="shopagg-modal">' +
                    '<div class="shopagg-modal-header">' +
                        '<h3>Select Payment Method</h3>' +
                        '<button class="shopagg-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="shopagg-modal-body">' +
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
                            '<p class="shopagg-status-text">Waiting for payment...</p>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $('body').append(modalHtml);

            var $modal = $('#shopagg-payment-modal');
            var pollTimer = null;

            // Close modal
            $modal.on('click', '.shopagg-modal-close, .shopagg-modal-overlay', function (e) {
                if (e.target === this) {
                    if (pollTimer) clearInterval(pollTimer);
                    $modal.remove();
                }
            });

            // Payment method click
            $modal.on('click', '.shopagg-pay-method-btn', function () {
                var $methodBtn = $(this);
                var method = $methodBtn.data('method');

                $modal.find('.shopagg-payment-methods').hide();
                $modal.find('.shopagg-payment-status').show();
                $modal.find('.shopagg-status-text').text('Initiating payment...');

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
                            return;
                        }

                        if (method === 'alipay' && response.data.form_html) {
                            // Open Alipay in a new window with the form HTML
                            var payWin = window.open('', '_blank', 'width=800,height=600');
                            if (payWin) {
                                payWin.document.write(response.data.form_html);
                                payWin.document.close();
                            } else {
                                $modal.find('.shopagg-status-text').text('Pop-up blocked. Please allow pop-ups and try again.');
                                $modal.find('.shopagg-payment-methods').show();
                                $modal.find('.shopagg-payment-status').hide();
                                return;
                            }
                            $modal.find('.shopagg-status-text').text('Please complete payment in the new window...');
                        } else if (method === 'wechat' && response.data.code_url) {
                            // Show WeChat QR code
                            var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(response.data.code_url);
                            $modal.find('.shopagg-qr-container')
                                .html('<img src="' + qrUrl + '" alt="WeChat Pay QR Code" width="200" height="200" />')
                                .show();
                            $modal.find('.shopagg-status-text').text('Please scan the QR code with WeChat to pay');
                        } else {
                            $modal.find('.shopagg-status-text').text('Unknown payment response.');
                            return;
                        }

                        // Start polling order status
                        pollTimer = setInterval(function () {
                            self.checkOrderStatus(orderId, function (paid) {
                                if (paid) {
                                    clearInterval(pollTimer);
                                    $modal.find('.shopagg-qr-container').hide();
                                    $modal.find('.shopagg-status-text').html('<span style="color:green;font-size:18px;">&#10004; Payment successful!</span>');
                                    setTimeout(function () {
                                        $modal.remove();
                                        window.location.reload();
                                    }, 1500);
                                }
                            });
                        }, 3000);
                    },
                    error: function () {
                        $modal.find('.shopagg-status-text').text('Network error. Please try again.');
                        $modal.find('.shopagg-payment-methods').show();
                        $modal.find('.shopagg-payment-status').hide();
                    }
                });
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
                    if (response.success && response.data.paid) {
                        callback(true);
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
