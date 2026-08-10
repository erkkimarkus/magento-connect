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
 * The single place a live store event turns into a catalog ingest row.
 * Product save already holds the product; the stock hooks only learn a
 * product id (legacy stock item) or a sku (MSI source item), so they load it
 * at the canonical scope first.
 *
 * No per-product dedupe: two stock moves on one product inside a minute queue
 * two rows. Deliberate — rows are cheap, the flusher batches 100 at a time and
 * the engine dedupes on event_id, so a read-before-write on the hot save path
 * would buy nothing.
 */
class CatalogIngest
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue the product's current catalog row — a tombstone once it has left
     * the sellable set (disabled, hidden); the engine never deletes.
     */
    public function enqueueProduct(Product $product): void
    {
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

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CATALOG,
            $item,
            (string)$product->getId(),
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
