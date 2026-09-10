<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\ViewModel\Adminhtml\DashboardData;

/**
 * A deactivated Campaign Intelligence account is a health state of its own
 * (PRO-2451): the dashboard must name it rather than report an outage.
 */
class DashboardDataTest extends TestCase
{
    public function testARefusedAccountIsReportedAndDegradesTheVerdict(): void
    {
        $viewModel = $this->createViewModel(true);

        self::assertTrue($viewModel->isEngineRefused());
        self::assertSame(DashboardData::VERDICT_DEGRADED, $viewModel->getVerdict());
    }

    public function testAHealthyInstallIsUnaffected(): void
    {
        $viewModel = $this->createViewModel(false);

        self::assertFalse($viewModel->isEngineRefused());
        self::assertSame(DashboardData::VERDICT_OK, $viewModel->getVerdict());
    }

    private function createViewModel(bool $refused): DashboardData
    {
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn(true);
        $engineSettings->method('isRefused')->willReturn($refused);

        $setupGuard = $this->createMock(SetupGuard::class);
        $setupGuard->method('isSetupCompleted')->willReturn(true);

        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('failedSince')->willReturn(0);

        return new DashboardData(
            $this->createMock(Config::class),
            $engineSettings,
            $setupGuard,
            $queueHealth,
            $this->createMock(DashboardStats::class),
            $this->createMock(FlagManager::class)
        );
    }
}
