<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The single place a product turns into a catalog ingest row — live hooks,
 * the delete observer's soft tombstone and the backfill/nightly-resync
 * processor alike. Product save already holds the product; the stock hooks
 * only learn a product id (legacy stock item) or a sku (MSI source item), so
 * they load it at the canonical scope first.
 *
 * The engine-connected gate lives here, once, rather than in every caller.
 *
 * One Magento product save legitimately reaches three of those hooks — the
 * legacy stock item is written during the save, and MSI mirrors that onto its
 * source items — so a byte-identical row queued twice in a row within the same
 * request is collapsed (see $lastPayloadHash). That is not queue-wide dedupe:
 * two stock moves on one product inside a minute still queue two rows, which
 * is fine — rows are cheap, the flusher batches 100 at a time and the engine
 * dedupes on event_id.
 */
class CatalogIngest
{
    /** Hash of the row queued most recently in this request. */
    private ?int $lastPayloadHash = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue the product's current catalog row — a tombstone once it has left
     * the sellable set (disabled, hidden); the engine never deletes.
     *
     * @return bool whether the row is queued (a collapsed duplicate counts:
     *     the row is already there), false when skipped or the build failed
     */
    public function enqueueProduct(Product $product): bool
    {
        return $this->enqueue($product, false);
    }

    /**
     * Queue the product's tombstone row regardless of whether it is still
     * ingestible — the hard-delete path fires before the row is gone, so the
     * product still looks perfectly sellable at that moment.
     */
    public function enqueueTombstone(Product $product): bool
    {
        return $this->enqueue($product, true);
    }

    public function enqueueProductId(int $productId): bool
    {
        return $productId > 0 && $this->enqueueLoaded(
            fn (int $storeId): mixed => $this->productRepository->getById($productId, false, $storeId)
        );
    }

    public function enqueueSku(string $sku): bool
    {
        return trim($sku) !== '' && $this->enqueueLoaded(
            fn (int $storeId): mixed => $this->productRepository->get($sku, false, $storeId)
        );
    }

    /**
     * Load the product at the canonical scope, then queue its row.
     *
     * @param callable(int): mixed $load the repository call to make
     */
    private function enqueueLoaded(callable $load): bool
    {
        if (!$this->settings->isConnected()) {
            return false;
        }

        try {
            $product = $load($this->payloadBuilder->canonicalStoreId());
        } catch (NoSuchEntityException) {
            return false;
        }

        return $product instanceof Product && $this->enqueueProduct($product);
    }

    private function enqueue(Product $product, bool $forceTombstone): bool
    {
        if (!$this->settings->isConnected()) {
            return false;
        }

        try {
            $item = $forceTombstone || !$this->payloadBuilder->isIngestible($product)
                ? $this->payloadBuilder->buildTombstone($product)
                : $this->payloadBuilder->build($product);
        } catch (\Throwable $exception) {
            $this->logger->error('Catalog payload build failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $hash = crc32((string)json_encode($item));
        if ($hash === $this->lastPayloadHash) {
            return true; // The same hop of the same save, seen through another hook.
        }
        $this->lastPayloadHash = $hash;

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CATALOG,
            $item,
            (string)$product->getId(),
            $this->payloadBuilder->canonicalStoreId()
        );

        return true;
    }
}
