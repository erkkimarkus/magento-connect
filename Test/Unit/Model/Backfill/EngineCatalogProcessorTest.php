<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1352/1353: the backfill collection must be scoped to the SAME
 * canonical store as the live save path (CatalogPayloadBuilder::
 * canonicalStoreId()) — never Magento's implicit current-store resolver —
 * so price/URL/language can never disagree between the two catalog ingest
 * paths.
 */
class EngineCatalogProcessorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    public function testBackfillCollectionIsScopedToTheCanonicalStoreBeforeLoadingPrices(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);

        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('setStoreId')->with(7);
        $collection->method('getItems')->willReturn([$product]);

        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $jobManager = $this->createMock(JobManager::class);
        // Stop cleanly after one page, exactly like an admin cancel would —
        // keeps this test to a single loadPage() call.
        $jobManager->method('isCancelled')->willReturn(true);

        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(7);
        $payloadBuilder->method('isIngestible')->willReturn(true);
        $payloadBuilder->method('build')->willReturn(['sku' => 'X']);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $job = $this->createJob();

        $processor = new EngineCatalogProcessor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(Logger::class)
        );

        $processor->process($job);
    }

    /**
     * PRO-1358: countProducts() (the progress-bar total) must be scoped to
     * the same canonical store as loadPage() — otherwise the total can
     * disagree with the scoped pages on multi-store installs.
     */
    public function testCountProductsAppliesTheSameCanonicalStoreScopeAsLoadPage(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::exactly(2))->method('setStoreId')->with(7);
        $collection->method('getSize')->willReturn(42);
        $collection->method('getItems')->willReturn([]);

        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $jobManager = $this->createMock(JobManager::class);

        $payloadBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $payloadBuilder->method('canonicalStoreId')->willReturn(7);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(null);
        $job->method('getCursorValue')->willReturn('0');
        $job->expects(self::once())->method('setData')->with('total_count', 42);

        $processor = new EngineCatalogProcessor(
            $jobManager,
            $collectionFactory,
            $payloadBuilder,
            $ingestQueue,
            $this->createMock(Logger::class)
        );

        $processor->process($job);
    }

    private function createJob(): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturnCallback(
            static fn (string $key): mixed => $key === 'total_count' ? 5 : null
        );
        $job->method('getCursorValue')->willReturn('0');

        return $job;
    }
}
