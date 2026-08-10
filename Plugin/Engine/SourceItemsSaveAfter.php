<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Engine;

use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Catalog ingest on an MSI source-item save — the shipment/source deduction,
 * the Sources grid, product-form quantity edits on an MSI install and
 * `POST /V1/inventory/source-items`. MSI mirrors those onto the legacy
 * cataloginventory row with direct SQL, so no model save and no
 * cataloginventory_stock_item_save_after event ever fires for them.
 *
 * Declared on Magento\InventoryApi\Api\SourceItemsSaveInterface in di.xml and
 * deliberately free of every MSI type reference (`object`/`array` signatures,
 * the sku read by duck typing): MSI is removable, and a plugin declared on a
 * class that does not exist is simply never wired, so an install without MSI
 * still compiles and runs. sortOrder keeps us last in the after-chain, behind
 * MSI's own SetDataToLegacyCatalogInventoryAtSourceItemsSavePlugin, so the
 * payload is built from the legacy row MSI has already written.
 */
class SourceItemsSaveAfter
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CatalogIngest $catalogIngest
    ) {
    }

    /**
     * Queue a catalog row per affected sku.
     *
     * @param object $subject
     * @param mixed $result
     * @param mixed[] $sourceItems
     * @return mixed
     */
    public function afterExecute(object $subject, $result, array $sourceItems = [])
    {
        if (!$this->settings->isConnected()) {
            return $result;
        }

        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            if (is_object($sourceItem) && method_exists($sourceItem, 'getSku')) {
                // One row per product, not per source: several sources of the
                // same sku collapse into one catalog row.
                $skus[(string)$sourceItem->getSku()] = true;
            }
        }
        foreach (array_keys($skus) as $sku) {
            $this->catalogIngest->enqueueSku((string)$sku);
        }

        return $result;
    }
}
