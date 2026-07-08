<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * Lifecycle management for chunked backfill jobs: one active job per
 * (job_type, target, website) at a time; the cron tick advances the oldest
 * active job one time-budgeted chunk per run.
 */
class JobManager
{
    public function __construct(
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @throws \RuntimeException when an equivalent job is already active
     */
    public function start(string $jobType, string $target, int $websiteId, ?int $totalCount = null): Job
    {
        if ($this->findActive($jobType, $target, $websiteId) !== null) {
            throw new \RuntimeException(
                sprintf('A %s/%s backfill is already active for website %d', $jobType, $target, $websiteId)
            );
        }

        $job = $this->jobFactory->create();
        $job->addData([
            'job_type' => $jobType,
            'target' => $target,
            'website_id' => $websiteId,
            'status' => Job::STATUS_PENDING,
            'total_count' => $totalCount,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);
        $this->jobResource->save($job);

        return $job;
    }

    /**
     * The oldest job that still needs work.
     */
    public function nextActive(): ?Job
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [Job::STATUS_PENDING, Job::STATUS_RUNNING]])
            ->setOrder('id', 'ASC')
            ->setPageSize(1);
        $job = $collection->getFirstItem();

        return $job instanceof Job && $job->getId() ? $job : null;
    }

    public function findActive(string $jobType, string $target, int $websiteId): ?Job
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [Job::STATUS_PENDING, Job::STATUS_RUNNING]])
            ->addFieldToFilter('job_type', $jobType)
            ->addFieldToFilter('target', $target)
            ->addFieldToFilter('website_id', ['eq' => $websiteId])
            ->setPageSize(1);
        $job = $collection->getFirstItem();

        return $job instanceof Job && $job->getId() ? $job : null;
    }

    public function markRunning(Job $job): void
    {
        if ($job->getStatus() === Job::STATUS_PENDING) {
            $job->addData([
                'status' => Job::STATUS_RUNNING,
                'started_at' => $this->dateTime->gmtDate(),
            ]);
            $this->jobResource->save($job);
        }
    }

    /**
     * @param int $processedDelta rows handled in this chunk
     * @param int $failedDelta rows that failed in this chunk
     */
    public function recordProgress(Job $job, int $processedDelta, int $failedDelta, ?string $cursor): void
    {
        $job->addData([
            'processed_count' => $job->getProcessedCount() + $processedDelta,
            'failed_count' => $job->getFailedCount() + $failedDelta,
            'cursor_value' => $cursor,
        ]);
        $this->jobResource->save($job);
    }

    public function complete(Job $job): void
    {
        $job->addData([
            'status' => Job::STATUS_COMPLETED,
            'completed_at' => $this->dateTime->gmtDate(),
        ]);
        $this->jobResource->save($job);
    }

    public function fail(Job $job, string $error): void
    {
        $job->addData([
            'status' => Job::STATUS_FAILED,
            'error_message' => mb_substr($error, 0, 60000),
            'completed_at' => $this->dateTime->gmtDate(),
        ]);
        $this->jobResource->save($job);
    }

    public function cancel(Job $job): void
    {
        $job->addData([
            'status' => Job::STATUS_CANCELLED,
            'completed_at' => $this->dateTime->gmtDate(),
        ]);
        $this->jobResource->save($job);
    }
}
