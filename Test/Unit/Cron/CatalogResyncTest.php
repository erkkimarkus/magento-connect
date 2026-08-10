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
        $this->settings->method('isConnected')->willReturn(true);
        $this->jobManager = $this->createMock(JobManager::class);
    }

    public function testQueuesACatalogBackfillJobForTheEngineTenant(): void
    {
        $this->jobManager->method('findActive')->willReturn(null);
        $this->jobManager->expects(self::once())->method('start')
            ->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);

        $this->cron()->execute();
    }

    public function testAMerchantStartedImportIsNeverTrampled(): void
    {
        $this->jobManager->method('findActive')->willReturn($this->createMock(Job::class));
        $this->jobManager->expects(self::never())->method('start');

        $this->cron()->execute();
    }

    public function testLosingTheRaceToAManualStartIsNotAnError(): void
    {
        $this->jobManager->method('findActive')->willReturn(null);
        $this->jobManager->method('start')->willThrowException(new \RuntimeException('already running'));

        $this->cron()->execute();

        $this->expectNotToPerformAssertions();
    }

    public function testADisconnectedEngineNeverSweeps(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->jobManager->expects(self::never())->method('findActive');
        $this->jobManager->expects(self::never())->method('start');

        (new CatalogResync($settings, $this->jobManager, $this->createMock(Logger::class)))->execute();
    }

    private function cron(): CatalogResync
    {
        return new CatalogResync($this->settings, $this->jobManager, $this->createMock(Logger::class));
    }
}
