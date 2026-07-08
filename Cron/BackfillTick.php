<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Advances the oldest active backfill job one chunk per cron run.
 * Processors are registered per "{job_type}:{target}" key via di.xml.
 */
class BackfillTick
{
    /**
     * @param array<string, ProcessorInterface> $processors
     */
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly Logger $logger,
        private readonly array $processors = []
    ) {
    }

    public function execute(): void
    {
        $job = $this->jobManager->nextActive();
        if ($job === null) {
            return;
        }

        $key = sprintf('%s:%s', $job->getJobType(), $job->getTarget());
        $processor = $this->processors[$key] ?? null;
        if (!$processor instanceof ProcessorInterface) {
            $this->jobManager->fail($job, sprintf('No backfill processor registered for "%s"', $key));

            return;
        }

        try {
            $processor->process($job);
        } catch (\Throwable $exception) {
            $this->jobManager->fail($job, $exception->getMessage());
            $this->logger->error('Backfill job failed', [
                'job_id' => $job->getId(),
                'type' => $key,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($job->getStatus() === Job::STATUS_COMPLETED) {
            $this->logger->info('Backfill job completed', [
                'job_id' => $job->getId(),
                'type' => $key,
                'processed' => $job->getProcessedCount(),
                'failed' => $job->getFailedCount(),
            ]);
        }
    }
}
