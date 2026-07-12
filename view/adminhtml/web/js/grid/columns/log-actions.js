/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

/**
 * Actions column for the unified log grid: the Details action loads the
 * row's drill-down HTML (redacted payload, attempt history, last response)
 * into a slide-out modal instead of navigating away — the grid selection
 * and mass-retry state stay untouched.
 */
define([
    'jquery',
    'Magento_Ui/js/grid/columns/actions',
    'mage/translate',
    'Magento_Ui/js/modal/modal'
], function ($, Actions, $t) {
    'use strict';

    var $container = null;

    /**
     * Lazily build the single reusable slide-out modal container.
     *
     * @returns {jQuery}
     */
    function modalContainer() {
        if (!$container) {
            $container = $('<div class="smaily-log-details-modal-content"></div>');
            $container.modal({
                type: 'slide',
                title: $t('Delivery details'),
                modalClass: 'smaily-log-details-modal',
                buttons: []
            });
        }

        return $container;
    }

    return Actions.extend({
        /**
         * Always bind the click handler: the stock column skips it for
         * plain-href actions (native navigation), but Details must open in
         * the modal. The href stays on the anchor for open-in-new-tab.
         *
         * @returns {Boolean}
         */
        isHandlerRequired: function () {
            return true;
        },

        /**
         * Open the action's href in the slide-out modal.
         *
         * @param {String} actionIndex
         * @param {Number} recordId
         * @param {Object} action
         */
        defaultCallback: function (actionIndex, recordId, action) {
            var $modal = modalContainer();

            $modal.html($('<p></p>').text($t('Loading…')));
            $modal.modal('openModal');
            $.get(action.href).always(function (data, textStatus, jqXHR) {
                var html = textStatus === 'success' ? data : (data.responseText || '');

                if (html) {
                    $modal.html(html);
                } else {
                    $modal.html($('<p></p>').text($t('Loading the delivery details failed — please try again.')));
                }
            });
        }
    });
});
