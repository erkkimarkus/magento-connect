<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * POST {action: start|status, job_type} -> aggregated progress:
 * {status, processed, failed, total, percent}
 *
 * "start" starts jobs (per website for contacts, installation-wide for
 * engine types) and is idempotent — an already-active job is not an error.
 */
class BackfillState extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly JobManager $jobManager,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $jobType = (string)($body['job_type'] ?? Job::TYPE_CONTACTS);
        $target = Job::TYPE_TARGETS[$jobType] ?? null;
        if ($target === null) {
            return $this->jsonResponse(['status' => 'idle', 'error' => 'unknown job type'], 400);
        }

        if ((string)($body['action'] ?? 'status') === 'start') {
            $websiteIds = $target === Job::TARGET_ENGINE
                ? [0]
                : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());
            foreach ($websiteIds as $websiteId) {
                try {
                    $this->jobManager->start($jobType, $target, $websiteId);
                } catch (\RuntimeException) {
                    // Already active — idempotent start.
                }
            }
        }

        return $this->jsonResponse($this->aggregate($jobType, $target));
    }

    /**
     * Aggregate the most recent job(s) of a type into one progress row.
     *
     * @return array<string, mixed>
     */
    private function aggregate(string $jobType, string $target): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('job_type', $jobType)
            ->addFieldToFilter('target', $target)
            ->setOrder('id', 'DESC')
            ->setPageSize(10);

        $processed = 0;
        $failed = 0;
        $total = 0;
        $statuses = [];
        $seenWebsites = [];
        foreach ($collection->getItems() as $job) {
            if (!$job instanceof Job || isset($seenWebsites[$job->getWebsiteId()])) {
                continue;
            }
            $seenWebsites[$job->getWebsiteId()] = true;
            $processed += $job->getProcessedCount();
            $failed += $job->getFailedCount();
            $total += (int)($job->getData('total_count') ?? 0);
            $statuses[] = $job->getStatus();
        }

        if (!$statuses) {
            $status = 'idle';
        } elseif (in_array(Job::STATUS_RUNNING, $statuses, true) || in_array(Job::STATUS_PENDING, $statuses, true)) {
            $status = 'running';
        } elseif (in_array(Job::STATUS_FAILED, $statuses, true)) {
            $status = 'failed';
        } else {
            $status = 'completed';
        }

        return [
            'status' => $status,
            'processed' => $processed,
            'failed' => $failed,
            'total' => $total,
            'percent' => $total > 0 ? (int)floor(min(100, $processed / $total * 100)) : 0,
        ];
    }
}
