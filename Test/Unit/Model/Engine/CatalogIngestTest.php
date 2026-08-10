<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1951: one Magento product save reaches three catalog hooks (product
 * save, the legacy stock item it writes on the way, and the MSI source item
 * that mirrors) — the row must be queued once, not three times, while a
 * genuinely different payload still gets its own row.
 */
class CatalogIngestTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $productRepository;
    private CatalogPayloadBuilder&MockObject $payloadBuilder;
    private IngestQueue&MockObject $queue;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $this->payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $this->payloadBuilder->method('isIngestible')->willReturn(true);
        $this->queue = $this->createMock(IngestQueue::class);
    }

    public function testTheSameRowSeenThroughThreeHooksIsQueuedOnce(): void
    {
        $product = $this->product(8);
        $this->payloadBuilder->method('build')->willReturn(['sku' => 'TENT', 'in_stock' => true]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => true], '8', 1);

        $ingest = $this->ingest();
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
    }

    public function testAChangedPayloadForTheSameProductStillGetsItsOwnRow(): void
    {
        $product = $this->product(8);
        $this->payloadBuilder->method('build')->willReturnOnConsecutiveCalls(
            ['sku' => 'TENT', 'in_stock' => true],
            ['sku' => 'TENT', 'in_stock' => false]
        );
        $this->queue->expects(self::exactly(2))->method('enqueue');

        $ingest = $this->ingest();
        $ingest->enqueueProduct($product);
        $ingest->enqueueProduct($product);
    }

    public function testAProductThatLeftTheSellableSetIsTombstoned(): void
    {
        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(1);
        $payloadBuilder->method('isIngestible')->willReturn(false);
        $payloadBuilder->method('buildTombstone')->willReturn(['sku' => 'TENT', 'in_stock' => false]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => false], '8', 1);

        (new CatalogIngest(
            $this->productRepository,
            $payloadBuilder,
            $this->queue,
            $this->createMock(StockRegistryStorage::class),
            $this->createMock(Logger::class)
        ))->enqueueProduct($this->product(8));
    }

    public function testAFailedBuildIsLoggedAndQueuesNothing(): void
    {
        $this->payloadBuilder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');
        $this->queue->expects(self::never())->method('enqueue');

        (new CatalogIngest(
            $this->productRepository,
            $this->payloadBuilder,
            $this->queue,
            $this->createMock(StockRegistryStorage::class),
            $logger
        ))->enqueueProduct($this->product(8));
    }

    public function testAProductIdIsLoadedAtTheCanonicalScope(): void
    {
        $product = $this->product(8);
        $this->productRepository->expects(self::once())->method('getById')->with(8, false, 1)
            ->willReturn($product);
        $this->payloadBuilder->method('build')->willReturn(['sku' => 'TENT']);
        $this->queue->expects(self::once())->method('enqueue');

        $this->ingest()->enqueueProductId(8);
    }

    public function testTheStockRegistryMemoIsDroppedBeforeTheRowIsBuilt(): void
    {
        $storage = $this->createMock(StockRegistryStorage::class);
        $storage->expects(self::once())->method('removeStockItem')->with(8);
        $this->payloadBuilder->method('build')->willReturn(['sku' => 'TENT']);

        (new CatalogIngest(
            $this->productRepository,
            $this->payloadBuilder,
            $this->queue,
            $storage,
            $this->createMock(Logger::class)
        ))->enqueueProduct($this->product(8));
    }

    public function testAnUnknownSkuIsSilentlySkipped(): void
    {
        $this->productRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->queue->expects(self::never())->method('enqueue');

        $this->ingest()->enqueueSku('GONE');
    }

    public function testABlankSkuNeverHitsTheRepository(): void
    {
        $this->productRepository->expects(self::never())->method('get');

        $this->ingest()->enqueueSku('  ');
    }

    private function ingest(): CatalogIngest
    {
        return new CatalogIngest(
            $this->productRepository,
            $this->payloadBuilder,
            $this->queue,
            $this->createMock(StockRegistryStorage::class),
            $this->createMock(Logger::class)
        );
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
