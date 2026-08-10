<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Exception\NoSuchEntityException;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The single place a live store event turns into a catalog ingest row.
 * Product save already holds the product; the stock hooks only learn a
 * product id (legacy stock item) or a sku (MSI source item), so they load it
 * at the canonical scope first.
 *
 * One Magento product save legitimately reaches three of those hooks — the
 * legacy stock item is written during the save, and MSI mirrors that onto its
 * source items — so a byte-identical row queued twice in a row within the same
 * request is collapsed (see $lastPayload). That is not queue-wide dedupe:
 * two stock moves on one product inside a minute still queue two rows, which
 * is fine — rows are cheap, the flusher batches 100 at a time and the engine
 * dedupes on event_id.
 */
class CatalogIngest
{
    /** The product id and payload of the row queued most recently in this request. */
    private int $lastProductId = 0;

    /** @var array<string, mixed> */
    private array $lastPayload = [];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly StockRegistryStorage $stockRegistryStorage,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue the product's current catalog row — a tombstone once it has left
     * the sellable set (disabled, hidden); the engine never deletes.
     */
    public function enqueueProduct(Product $product): void
    {
        // in_stock is read through the stock registry, which memoises the item
        // per request. MSI mirrors its quantities onto the legacy row with
        // direct SQL and so never invalidates that memo — a shipment that sold
        // the last unit out was queued as still in stock until this drop
        // (caught on the sandbox, not by the unit tests). Re-reading one row is
        // the right price for never publishing a stale in_stock.
        $productId = (int)$product->getId();
        $this->stockRegistryStorage->removeStockItem($productId);

        try {
            $item = $this->payloadBuilder->isIngestible($product)
                ? $this->payloadBuilder->build($product)
                : $this->payloadBuilder->buildTombstone($product);
        } catch (\Throwable $exception) {
            $this->logger->error('Catalog payload build failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($productId === $this->lastProductId && $item === $this->lastPayload) {
            return; // The same hop of the same save, seen through another hook.
        }
        $this->lastProductId = $productId;
        $this->lastPayload = $item;

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CATALOG,
            $item,
            (string)$productId,
            $this->payloadBuilder->canonicalStoreId()
        );
    }

    public function enqueueProductId(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }

        try {
            $product = $this->productRepository->getById(
                $productId,
                false,
                $this->payloadBuilder->canonicalStoreId()
            );
        } catch (NoSuchEntityException) {
            return;
        }

        if ($product instanceof Product) {
            $this->enqueueProduct($product);
        }
    }

    public function enqueueSku(string $sku): void
    {
        if (trim($sku) === '') {
            return;
        }

        try {
            $product = $this->productRepository->get($sku, false, $this->payloadBuilder->canonicalStoreId());
        } catch (NoSuchEntityException) {
            return;
        }

        if ($product instanceof Product) {
            $this->enqueueProduct($product);
        }
    }
}
