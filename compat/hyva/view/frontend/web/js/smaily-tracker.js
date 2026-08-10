/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Hyvä port of Smaily_Connect/js/tracker — no RequireJS, no jQuery.
 * Differences from the Luma build, and only these:
 *  - plain script + JSON config block instead of AMD + x-magento-init;
 *  - fetch(keepalive) replaces the $.ajax sendBeacon fallback;
 *  - cart_add: Hyvä fires no `ajax:addToCart` jQuery event, and its default
 *    add-to-cart is a regular form POST to checkout/cart/add — so the event
 *    is captured at form-submit time (see TODO below).
 *
 * Browse tracker: page-context events batched (5s window) to the plugin
 * relay (smaily/relay), which forwards them to the Campaign Intelligence
 * engine — the API key never reaches the browser. Loss-tolerant by design.
 *
 * Consent (contract §6 sender-side anonymous mode): when Magento cookie
 * restriction mode is on and the visitor has not allowed cookies
 * (user_allowed_save_cookie), events still flow but omit the identity hint
 * (smaily_visitor_token) — session_id + event_id only, so anonymous events
 * keep feeding popularity/co-view signals. The engine-side profiling
 * opt-out gate is the guarantee; this is the data-minimization layer.
 */
(function () {
    'use strict';

    var BATCH_WINDOW_MS = 5000,
        MAX_BATCH = 100;

    var configEl = document.getElementById('smaily-tracker-config'),
        config,
        helper,
        queue = [],
        flushTimer = null;

    if (!configEl || typeof window.smailyAttribution !== 'function') {
        return;
    }

    try {
        config = JSON.parse(configEl.textContent);
    } catch (e) {
        return;
    }

    helper = window.smailyAttribution(config.attribution);

    function consentGiven() {
        if (!config.consentRequired) {
            return true;
        }

        return helper.getCookie('user_allowed_save_cookie') !== null;
    }

    function sessionId() {
        return helper.getCookie(config.attribution.cookieSession);
    }

    function baseEvent(type) {
        var event = {
            event_id: helper.uuidv4(),
            session_id: sessionId(),
            event_type: type
        };
        var visitorToken = helper.getCookie(config.attribution.cookieVisitor);

        // Identity hint only with consent (sender-side anonymous mode).
        // The rec id/ctx cookies are deliberately NOT echoed here — the
        // engine ignores both on browse events since contract v1.7.0; they
        // reach the engine on the order instead (§5).
        if (visitorToken && consentGiven()) {
            event.smaily_visitor_token = visitorToken;
        }

        return event;
    }

    function push(event) {
        if (!event.session_id) {
            return;
        }
        queue.push(event);
        if (queue.length >= MAX_BATCH) {
            flush();
        } else if (!flushTimer) {
            flushTimer = setTimeout(flush, BATCH_WINDOW_MS);
        }
    }

    function flush() {
        if (flushTimer) {
            clearTimeout(flushTimer);
            flushTimer = null;
        }
        if (!queue.length) {
            return;
        }
        var payload = JSON.stringify({events: queue.splice(0, MAX_BATCH)});

        if (navigator.sendBeacon) {
            navigator.sendBeacon(config.relayUrl, new Blob([payload], {type: 'application/json'}));
        } else if (window.fetch) {
            fetch(config.relayUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: payload,
                keepalive: true
            });
        }
    }

    function trackPageContext() {
        var ctx = window.smailyPageContext || {type: null},
            event;

        switch (ctx.type) {
            case 'product':
                event = baseEvent('product_view');
                if (ctx.sku) {
                    event.sku = ctx.sku;
                }
                if (ctx.categoryPath) {
                    event.category_path = ctx.categoryPath;
                }
                break;

            case 'category':
                event = baseEvent('category_view');
                if (ctx.categoryPath) {
                    event.category_path = ctx.categoryPath;
                }
                break;

            case 'checkout':
                event = baseEvent('checkout_start');
                break;

            case 'success':
                event = baseEvent('checkout_complete');
                break;

            default:
                if (window.location.pathname.indexOf('catalogsearch') !== -1) {
                    var query = new URLSearchParams(window.location.search).get('q');

                    if (query) {
                        event = baseEvent('search');
                        event.search_query = query;
                    }
                }
        }

        if (event) {
            push(event);
        }
    }

    // Hyvä ships no `ajax:addToCart`; its default add-to-cart is a regular
    // form POST to checkout/cart/add, so catch it at submit time (capture
    // phase) and flush immediately — sendBeacon survives the navigation.
    // Semantic difference vs Luma: fires on the ATTEMPT, not on confirmed
    // success (acceptable for a loss-tolerant popularity signal).
    // Verified on Hyvä 1.5.2 (default theme, PDP form POST): the event
    // fires with the page-context sku and flushes before navigation.
    // Known remaining gap: third-party AJAX-add-to-cart modules that call
    // form.submit() programmatically (fires no `submit` event) or replace
    // the form bypass this capture. If a store reports missing cart_add
    // events, add a `private-content-loaded` cart-diff listener
    // (event.detail.data.cart) as the success-side signal.
    document.addEventListener('submit', function (submitEvent) {
        var form = submitEvent.target,
            action = form && form.getAttribute ? String(form.getAttribute('action') || '') : '';

        if (action.indexOf('checkout/cart/add') === -1) {
            return;
        }
        var event = baseEvent('cart_add'),
            ctx = window.smailyPageContext || {};

        // Hyvä's PDP form posts the product id, not the sku — reuse the
        // page-context sku (present on product pages, absent on listings).
        if (ctx.type === 'product' && ctx.sku) {
            event.sku = ctx.sku;
        }
        push(event);
        flush();
    }, true);

    window.addEventListener('pagehide', flush);

    trackPageContext();
})();
