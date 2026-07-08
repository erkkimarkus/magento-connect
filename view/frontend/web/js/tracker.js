/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 *
 * Browse tracker: page-context events batched (5s window) to the plugin
 * relay (smaily/relay), which forwards them to the Campaign Intelligence
 * engine — the API key never reaches the browser. Loss-tolerant by design.
 *
 * Consent: when Magento cookie restriction mode is on, no events are sent
 * until the visitor has allowed cookies (user_allowed_save_cookie).
 */
define(['jquery', 'Smaily_Connect/js/attribution'], function ($, attribution) {
    'use strict';

    var BATCH_WINDOW_MS = 5000,
        MAX_BATCH = 100;

    return function (config) {
        var helper = attribution(config.attribution),
            queue = [],
            flushTimer = null;

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
            var visitorToken = helper.getCookie(config.attribution.cookieVisitor),
                recId = helper.getCookie(config.attribution.cookieRecId),
                ctx = helper.getCookie(config.attribution.cookieContext);

            if (visitorToken) {
                event.smaily_visitor_token = visitorToken;
            }
            if (recId) {
                event.smaily_rec_id = recId;
            }
            // NB: browse events use smaily_ctx (orders use smaily_rec_ctx).
            if (ctx) {
                event.smaily_ctx = ctx;
            }

            return event;
        }

        function push(event) {
            if (!consentGiven() || !event.session_id) {
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
            } else {
                $.ajax({
                    url: config.relayUrl,
                    method: 'POST',
                    data: payload,
                    contentType: 'application/json',
                    global: false
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

        // Luma fires ajax:addToCart on successful add-to-cart requests.
        $(document).on('ajax:addToCart', function (jqEvent, data) {
            var event = baseEvent('cart_add'),
                sku = data && data.sku ? data.sku : null;

            if (sku) {
                event.sku = sku;
            }
            push(event);
        });

        window.addEventListener('pagehide', flush);

        trackPageContext();
    };
});
