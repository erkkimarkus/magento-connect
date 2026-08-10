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
 * Catalog ingest for MSI stock writes. MSI mirrors its own quantities onto the
 * legacy cataloginventory row with direct SQL, so none of these fire
 * cataloginventory_stock_item_save_after — without this plugin the engine
 * never learns that a default 2.4.x install sold out or restocked.
 *
 * Declared in di.xml on the two seams every MSI stock write funnels through,
 * both of whose method is execute() (hence one afterExecute for both):
 * - InventoryApi\Api\SourceItemsSaveInterface — the Sources grid, a product
 *   form's quantity, POST /V1/inventory/source-items, and the legacy stock
 *   item MSI mirrors onto its default source.
 * - InventorySourceDeductionApi\Model\SourceDeductionServiceInterface — the
 *   shipment deduction (what "order decrement" actually is once MSI is
 *   installed: placing an order only writes a reservation) and the
 *   credit-memo return to stock. Neither goes through SourceItemsSave.
 *
 * Not one MSI type is named here — `object`/`mixed` signatures, sku read by
 * duck typing — because MSI is removable: a plugin declared on a class that
 * does not exist is simply never wired, so an install without the Inventory
 * modules still compiles and runs on the legacy observer alone. sortOrder
 * keeps us last in the after-chain, behind MSI's own legacy-stock sync, so
 * the payload is built from the row MSI has already written.
 */
class MsiStockWriteAfter
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
     * @param mixed $payload the saved source items, or a source deduction request
     * @return mixed
     */
    public function afterExecute(object $subject, $result, $payload = null)
    {
        if (!$this->settings->isConnected()) {
            return $result;
        }

        foreach ($this->skus($payload) as $sku) {
            $this->catalogIngest->enqueueSku($sku);
        }

        return $result;
    }

    /**
     * One sku per product, not per source or per order line.
     *
     * @return string[]
     */
    private function skus(mixed $payload): array
    {
        if (is_array($payload)) {
            $items = $payload;
        } elseif (is_object($payload) && method_exists($payload, 'getItems')) {
            $items = (array)$payload->getItems();
        } else {
            return [];
        }

        $skus = [];
        foreach ($items as $item) {
            if (is_object($item) && method_exists($item, 'getSku')) {
                $skus[(string)$item->getSku()] = true;
            }
        }

        return array_map('strval', array_keys($skus));
    }
}
