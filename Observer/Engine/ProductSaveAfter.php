<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Catalog ingest on product save. Products that leave the sellable set
 * (disabled, hidden) are tombstoned via an in_stock=false upsert — the
 * engine never deletes.
 */
class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isCatalogSyncEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product) {
            return;
        }

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

        $this->ingestQueue->enqueue(Client::DOMAIN_CATALOG, $item, (string)$product->getId());
    }
}
