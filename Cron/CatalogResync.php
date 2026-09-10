<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The catalog reconciler: queues one full catalog re-sync a night so a change
 * no event can see (a CSV/`bin/magento import` run writes stock and price
 * straight to the tables) is corrected within a day instead of never.
 *
 * It is not a walker of its own — it queues an ordinary catalog backfill job
 * and lets Cron\BackfillTick page it in with the same cursor, time budget and
 * flood guard a merchant-started import gets. That also means it obeys the
 * same one-active-job-at-a-time lock: while a merchant's own import (or last
 * night's, on a very large catalog) is still open, the sweep skips a night
 * rather than trampling it.
 */
class CatalogResync
{
    public function __construct(
        private readonly Settings $settings,
        private readonly JobManager $jobManager,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isSendingAllowed()) {
            return;
        }

        try {
            $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        } catch (\RuntimeException) {
            // The one-active-job lock: a merchant's own import (or last
            // night's) is still open, so theirs wins and ours waits for
            // tomorrow.
            $this->logger->debug('Periodic catalog re-sync skipped — a catalog import is still active');

            return;
        }

        $this->logger->info('Periodic catalog re-sync queued');
    }
}
