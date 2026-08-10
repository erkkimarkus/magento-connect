<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory as SubscriberCollectionFactory;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Backfills all existing newsletter subscribers of a website into Smaily
 * (both subscribed and unsubscribed, with is_unsubscribed set — the same
 * audience as the legacy 2.8.x full sync).
 *
 * Cursor = last processed subscriber_id; each tick processes pages until
 * the time budget is spent, so a large base imports across several cron
 * runs without ever blocking the cron group.
 *
 * The website's stored "Enable subscriber synchronization" answer gates
 * this import exactly as it gates the live paths and the reconcile tick —
 * one stored answer owns every outbound contact path (PRO-1764).
 */
class ContactsProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 500;
    private const TIME_BUDGET_SECONDS = 20;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly SubscriberCollectionFactory $subscriberCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SubscriberPayloadBuilder $payloadBuilder,
        private readonly SmailyClientProvider $clientProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        // Same stored answer, same website resolution as the live paths
        // (Observer\SubscriberSaveAfter, Cron\ContactReconcile): with the
        // switch off nothing is sent, and the total says 0 instead of
        // promising a sync that will not happen.
        if (!$this->config->isSyncEnabled($job->getWebsiteId())) {
            $job->setData('total_count', 0);
            $this->jobManager->complete($job);
            $this->logger->info('Contacts backfill sent nothing — subscriber synchronization is off', [
                'website_id' => $job->getWebsiteId(),
            ]);

            return;
        }

        $storeIds = $this->websiteStoreIds($job->getWebsiteId());
        if (!$storeIds) {
            $this->jobManager->fail($job, sprintf('Website %d has no stores', $job->getWebsiteId()));

            return;
        }

        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $this->countSubscribers($storeIds));
        }
        $this->jobManager->markRunning($job);

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            $cursor = (int)$job->getCursorValue();
            $page = $this->loadPage($storeIds, $cursor);
            if (!$page) {
                $this->jobManager->complete($job);

                return;
            }

            [$processed, $failed, $newCursor] = $this->sendPage($page);
            $this->jobManager->recordProgress($job, $processed, $failed, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }
        } while (microtime(true) < $deadline);
    }

    /**
     * @param int[] $storeIds
     * @return Subscriber[]
     */
    private function loadPage(array $storeIds, int $cursor): array
    {
        $collection = $this->subscriberCollectionFactory->create();
        $collection->addFieldToFilter('store_id', ['in' => $storeIds])
            ->addFieldToFilter('subscriber_id', ['gt' => $cursor])
            ->setOrder('subscriber_id', 'ASC')
            ->setPageSize(self::PAGE_SIZE);

        $subscribers = [];
        foreach ($collection->getItems() as $subscriber) {
            if ($subscriber instanceof Subscriber) {
                $subscribers[] = $subscriber;
            }
        }

        return $subscribers;
    }

    /**
     * @param Subscriber[] $subscribers
     * @return array{int, int, int} processed, failed, new cursor
     */
    private function sendPage(array $subscribers): array
    {
        $customers = $this->loadCustomers($subscribers);

        $byStore = [];
        $cursor = 0;
        foreach ($subscribers as $subscriber) {
            $cursor = max($cursor, (int)$subscriber->getId());
            $email = (string)$subscriber->getEmail();
            if ($email === '') {
                continue;
            }

            $byStore[(int)$subscriber->getStoreId()][] = $this->payloadBuilder->build(
                $email,
                (int)$subscriber->getStoreId(),
                (int)$subscriber->getStatus() !== Subscriber::STATUS_SUBSCRIBED,
                $customers[(int)$subscriber->getCustomerId()] ?? null
            );
        }

        $processed = 0;
        $failed = 0;
        foreach ($byStore as $storeId => $contacts) {
            try {
                $this->clientProvider->forStore($storeId)->post(SmailyClient::ENDPOINT_CONTACT, $contacts);
                $processed += count($contacts);
            } catch (SmailyClientException $exception) {
                $failed += count($contacts);
                $this->logger->error('Contacts backfill batch failed', [
                    'store_id' => $storeId,
                    'count' => count($contacts),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [$processed, $failed, $cursor];
    }

    /**
     * @param Subscriber[] $subscribers
     * @return array<int, CustomerInterface>
     */
    private function loadCustomers(array $subscribers): array
    {
        $customerIds = [];
        foreach ($subscribers as $subscriber) {
            $customerId = (int)$subscriber->getCustomerId();
            if ($customerId > 0) {
                $customerIds[] = $customerId;
            }
        }
        if (!$customerIds) {
            return [];
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_unique($customerIds), 'in')
            ->create();

        $customers = [];
        foreach ($this->customerRepository->getList($criteria)->getItems() as $customer) {
            $customers[(int)$customer->getId()] = $customer;
        }

        return $customers;
    }

    /**
     * @param int[] $storeIds
     */
    private function countSubscribers(array $storeIds): int
    {
        $collection = $this->subscriberCollectionFactory->create();
        $collection->addFieldToFilter('store_id', ['in' => $storeIds]);

        return $collection->getSize();
    }

    /**
     * @return int[]
     */
    private function websiteStoreIds(int $websiteId): array
    {
        try {
            $website = $this->storeManager->getWebsite($websiteId);
        } catch (\Exception) {
            return [];
        }

        return $website instanceof Website ? array_map('intval', $website->getStoreIds()) : [];
    }
}
