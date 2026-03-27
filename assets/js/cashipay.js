/* global cashipayData, jQuery */
(function ($) {
    'use strict';

    var wrap = $('.cashipay-payment-wrap');
    if (!wrap.length) return;

    var orderId   = wrap.data('order-id');
    var returnUrl = wrap.data('return-url');
    var timer     = null;
    var polls     = 0;
    var MAX_POLLS = 120; // 10 min @ 5 s

    startPolling();

    // ── OTP form ──────────────────────────────────────────────────────────────

    $('#cashipay-otp-form').on('submit', function (e) {
        e.preventDefault();

        var otp = $('#cashipay-otp-input').val().trim();
        if (!otp.match(/^\d{4,8}$/)) {
            showMsg('error', cashipayData.i18n.error);
            return;
        }

        var $btn = $('#cashipay-otp-submit');
        $btn.prop('disabled', true).text(cashipayData.i18n.confirming);
        clearMsg();

        $.post(cashipayData.ajaxUrl, {
            action:   'cashipay_confirm_otp',
            nonce:    cashipayData.nonce,
            order_id: orderId,
            otp:      otp,
        })
        .done(function (res) {
            if (res.success) {
                stopPolling();
                setStatus(cashipayData.i18n.redirecting);
                setTimeout(function () {
                    window.location.href = res.data.return_url || returnUrl;
                }, 1200);
            } else {
                showMsg('error', (res.data && res.data.message) || cashipayData.i18n.error);
                $btn.prop('disabled', false).text(cashipayData.i18n.confirmButton);
            }
        })
        .fail(function () {
            showMsg('error', cashipayData.i18n.error);
            $btn.prop('disabled', false).text(cashipayData.i18n.confirmButton);
        });
    });

    // ── Status polling ────────────────────────────────────────────────────────

    function startPolling() {
        timer = setInterval(checkStatus, 5000);
    }

    function stopPolling() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function checkStatus() {
        if (++polls > MAX_POLLS) {
            stopPolling();
            setStatus('');
            return;
        }

        $.post(cashipayData.ajaxUrl, {
            action:   'cashipay_check_status',
            order_id: orderId,
        })
        .done(function (res) {
            if (!res.success) return;

            var status = (res.data.status || '').toUpperCase();

            if (['COMPLETED', 'PAID', 'SUCCESS', 'APPROVED'].indexOf(status) !== -1) {
                stopPolling();
                setStatus(cashipayData.i18n.redirecting);
                setTimeout(function () {
                    window.location.href = res.data.return_url || returnUrl;
                }, 1000);

            } else if (['FAILED', 'EXPIRED', 'CANCELLED'].indexOf(status) !== -1) {
                stopPolling();
                $('.cashipay-spinner').hide();
                setStatus('');
                showMsg('error', cashipayData.i18n.error + ' (' + status.toLowerCase() + ')');
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function setStatus(text) {
        $('#cashipay-status-text').text(text);
    }

    function showMsg(type, text) {
        var $el = $('#cashipay-otp-message');
        $el.removeClass('is-success is-error')
           .addClass(type === 'error' ? 'is-error' : 'is-success')
           .text(text)
           .removeAttr('hidden');
    }

    function clearMsg() {
        $('#cashipay-otp-message').attr('hidden', true).text('');
    }

}(jQuery));
