<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Newsletter\Model\ResourceModel\Subscriber\Collection;
use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory as SubscriberCollectionFactory;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\ContactsProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1764: the website's stored contact-sync answer gates the historical
 * import exactly as it gates the live paths — with the switch off nothing
 * reaches the transport, a job that never started reports a total of 0
 * rather than promising a sync, and one already in flight is stopped with
 * the counts it really achieved.
 */
class ContactsProcessorTest extends TestCase
{
    private const WEBSITE_ID = 2;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/SubscriberCollectionFactory.php';
    }

    public function testSyncDisabledSendsNothingAndReportsAZeroTotal(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::never())->method('post');

        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        // Not even counted: an import that sends nothing must not quote a
        // number that promises otherwise.
        $collectionFactory = $this->createMock(SubscriberCollectionFactory::class);
        $collectionFactory->expects(self::never())->method('create');

        $job = $this->createJob();
        $job->expects(self::once())->method('setData')->with('total_count', 0);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('complete')->with($job);
        $jobManager->expects(self::never())->method('recordProgress');

        $this->createProcessor($jobManager, $collectionFactory, $clientProvider, false)->process($job);
    }

    public function testSyncSwitchedOffMidImportStopsTheJobWithoutRewritingItsTotal(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $collectionFactory = $this->createMock(SubscriberCollectionFactory::class);
        $collectionFactory->expects(self::never())->method('create');

        // 400 already counted, some of them already sent: the outcome must
        // stay truthful about that, not read "completed, 0".
        $job = $this->createJob(400);
        $job->expects(self::never())->method('setData');

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('cancel')->with($job);
        $jobManager->expects(self::never())->method('complete');

        $this->createProcessor($jobManager, $collectionFactory, $clientProvider, false)->process($job);
    }

    public function testSyncEnabledImportsTheSubscriberBaseUnchanged(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::once())
            ->method('post')
            ->with(SmailyClient::ENDPOINT_CONTACT, [['email' => 'buyer@example.com']]);

        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::once())->method('forStore')->with(5)->willReturn($client);

        $collectionFactory = $this->createMock(SubscriberCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->createCollection());

        $job = $this->createJob();
        $job->expects(self::once())->method('setData')->with('total_count', 1);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 1, 0, '77');
        $jobManager->expects(self::once())->method('complete')->with($job);

        $this->createProcessor($jobManager, $collectionFactory, $clientProvider, true)->process($job);
    }

    /**
     * One page holding one subscriber, then an empty page that completes
     * the job — the shortest real walk through process().
     */
    private function createCollection(): Collection&MockObject
    {
        // getStoreId()/getCustomerId() are DataObject magic getters, so they
        // have to be added to the double rather than stubbed.
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getEmail', 'getStatus'])
            ->addMethods(['getStoreId', 'getCustomerId'])
            ->getMock();
        $subscriber->method('getId')->willReturn(77);
        $subscriber->method('getEmail')->willReturn('buyer@example.com');
        $subscriber->method('getStoreId')->willReturn(5);
        $subscriber->method('getStatus')->willReturn(Subscriber::STATUS_SUBSCRIBED);
        $subscriber->method('getCustomerId')->willReturn(0);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSize')->willReturn(1);
        $collection->method('getItems')->willReturnOnConsecutiveCalls([$subscriber], []);

        return $collection;
    }

    private function createJob(?int $totalCount = null): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $job->method('getData')->willReturn($totalCount);
        $job->method('getCursorValue')->willReturn('0');

        return $job;
    }

    private function createProcessor(
        JobManager&MockObject $jobManager,
        SubscriberCollectionFactory&MockObject $collectionFactory,
        SmailyClientProvider&MockObject $clientProvider,
        bool $syncEnabled
    ): ContactsProcessor {
        $payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        $payloadBuilder->method('build')->willReturn(['email' => 'buyer@example.com']);

        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn([5]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->with(self::WEBSITE_ID)->willReturn($website);

        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->with(self::WEBSITE_ID)->willReturn($syncEnabled);

        return new ContactsProcessor(
            $jobManager,
            $collectionFactory,
            $this->createMock(CustomerRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $payloadBuilder,
            $clientProvider,
            $storeManager,
            $config,
            $this->createMock(Logger::class)
        );
    }
}
