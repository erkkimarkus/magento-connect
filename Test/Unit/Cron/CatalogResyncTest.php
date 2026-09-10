<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\CatalogResync;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1951: the nightly reconciler queues an ordinary catalog backfill job —
 * and never one on top of an import that is already running.
 */
class CatalogResyncTest extends TestCase
{
    private Settings&MockObject $settings;
    private JobManager&MockObject $jobManager;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->jobManager = $this->createMock(JobManager::class);
    }

    public function testQueuesACatalogBackfillJobForTheEngineTenant(): void
    {
        $this->jobManager->expects(self::once())->method('start')
            ->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);

        $this->cron()->execute();
    }

    /**
     * The one-active-job lock is the whole no-trample mechanism: start()
     * refuses while a merchant's own import is open, and the sweep skips
     * that night instead of erroring.
     */
    public function testAnActiveImportIsNeverTrampled(): void
    {
        $this->jobManager->method('start')->willThrowException(new \RuntimeException('already running'));

        $this->cron()->execute();

        $this->expectNotToPerformAssertions();
    }

    public function testADisconnectedEngineNeverSweeps(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);
        $this->jobManager->expects(self::never())->method('start');

        (new CatalogResync($settings, $this->jobManager, $this->createMock(Logger::class)))->execute();
    }

    private function cron(): CatalogResync
    {
        return new CatalogResync($this->settings, $this->jobManager, $this->createMock(Logger::class));
    }
}
