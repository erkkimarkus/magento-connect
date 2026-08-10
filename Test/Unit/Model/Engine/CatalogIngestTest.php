<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1951: one Magento product save reaches three catalog hooks (product
 * save, the legacy stock item it writes on the way, and the MSI source item
 * that mirrors) — the row must be queued once, not three times, while a
 * genuinely different payload still gets its own row. This is also the one
 * place the engine-connected gate is checked for every catalog ingest path.
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

        $this->ingest($payloadBuilder)->enqueueProduct($this->product(8));
    }

    /**
     * The hard-delete path fires before the row is gone, so the product still
     * looks ingestible — the tombstone has to be forced.
     */
    public function testAForcedTombstoneIgnoresThatTheProductIsStillIngestible(): void
    {
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->payloadBuilder->method('buildTombstone')->willReturn(['sku' => 'TENT', 'in_stock' => false]);
        $this->queue->expects(self::once())->method('enqueue')
            ->with(Client::DOMAIN_CATALOG, ['sku' => 'TENT', 'in_stock' => false], '8', 1);

        $this->ingest()->enqueueTombstone($this->product(8));
    }

    public function testAFailedBuildIsLoggedAndQueuesNothing(): void
    {
        $this->payloadBuilder->method('build')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');
        $this->queue->expects(self::never())->method('enqueue');

        self::assertFalse($this->ingest(null, $logger)->enqueueProduct($this->product(8)));
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

    /**
     * The gate every catalog hook used to repeat now lives here, once — and
     * it short-circuits before the repository is touched.
     */
    public function testADisconnectedEngineNeverBuildsLoadsOrQueues(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->productRepository->expects(self::never())->method('get');
        $this->payloadBuilder->expects(self::never())->method('build');
        $this->queue->expects(self::never())->method('enqueue');

        $ingest = $this->ingest(null, null, $settings);
        self::assertFalse($ingest->enqueueProduct($this->product(8)));
        self::assertFalse($ingest->enqueueTombstone($this->product(8)));
        self::assertFalse($ingest->enqueueSku('TENT'));
    }

    public function testAnUnknownSkuIsSilentlySkipped(): void
    {
        $this->productRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->queue->expects(self::never())->method('enqueue');

        self::assertFalse($this->ingest()->enqueueSku('GONE'));
    }

    public function testABlankSkuNeverHitsTheRepository(): void
    {
        $this->productRepository->expects(self::never())->method('get');

        self::assertFalse($this->ingest()->enqueueSku('  '));
    }

    private function ingest(
        ?CatalogPayloadBuilder $payloadBuilder = null,
        ?Logger $logger = null,
        ?Settings $settings = null
    ): CatalogIngest {
        if ($settings === null) {
            $settings = $this->createMock(Settings::class);
            $settings->method('isConnected')->willReturn(true);
        }

        return new CatalogIngest(
            $settings,
            $this->productRepository,
            $payloadBuilder ?? $this->payloadBuilder,
            $this->queue,
            $logger ?? $this->createMock(Logger::class)
        );
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
