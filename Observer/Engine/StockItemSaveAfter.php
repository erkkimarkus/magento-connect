<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Catalog ingest on a legacy stock-item save — every stock write that does
 * NOT go through a product save: the admin Advanced Inventory form, the
 * `PUT /V1/products/{sku}/stockItems/{id}` endpoint, the non-MSI order
 * decrement and the credit-memo restock. Without it the engine's
 * back-in-stock detection, which reads our catalog rows' `in_stock`, only
 * ever saw product edits.
 *
 * MSI's own writes are covered separately by
 * Plugin\Engine\SourceItemsSaveAfter — MSI syncs the legacy row with direct
 * SQL, so no model save and no event.
 */
class StockItemSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CatalogIngest $catalogIngest
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        $item = $observer->getEvent()->getData('item');
        if (!$item instanceof StockItem) {
            return;
        }

        // A product save writes its stock item too, so queue again only when
        // the stock itself moved — an ordinary product edit is already
        // covered by ProductSaveAfter. A row with no loaded original (a
        // brand-new stock item) always counts as a change.
        if ($item->getOrigData('item_id') !== null
            && !$item->dataHasChangedFor('is_in_stock')
            && !$item->dataHasChangedFor('qty')
        ) {
            return;
        }

        $this->catalogIngest->enqueueProductId((int)$item->getProductId());
    }
}
