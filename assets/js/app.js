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

                if (!confirm('Are you sure you want to install this resource?')) {
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
                            $btn.text('Installed').prop('disabled', true);
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
         * Purchase button.
         */
        bindPurchase: function () {
            $(document).on('click', '.shopagg-purchase-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var resourceId = $btn.data('resource-id');
                var $msg = $('#detail-message');

                if (!confirm('Are you sure you want to purchase this resource?')) {
                    return;
                }

                $btn.prop('disabled', true).text('Processing...');
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
                            $msg.addClass('success').text(response.data.message);
                            setTimeout(function () {
                                window.location.reload();
                            }, 1000);
                        } else {
                            $msg.addClass('error').text(response.data.message);
                            $btn.prop('disabled', false).text('Purchase');
                        }
                    },
                    error: function () {
                        $msg.addClass('error').text('Purchase failed. Please try again.');
                        $btn.prop('disabled', false).text('Purchase');
                    }
                });
            });
        }
    };

    $(document).ready(function () {
        ShopAGGAppStore.init();
    });

})(jQuery);
