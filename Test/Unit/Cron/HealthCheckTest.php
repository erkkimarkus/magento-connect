<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use Magento\Framework\FlagManager;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\HealthCheck;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\Model\Logger\Logger;

/**
 * A deactivated account is a verdict, not an outage, and the two need
 * opposite wording: an outage is waited out, a verdict is acted on
 * (PRO-2451 / PRO-1953).
 */
class HealthCheckTest extends TestCase
{
    public function testARefusedAccountIsNamedAndTheOutageClockIsDropped(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $saved = [];
        $flags->method('saveFlag')->willReturnCallback(
            static function (string $flag) use (&$saved): bool {
                $saved[] = $flag;

                return true;
            }
        );
        $deleted = [];
        $flags->method('deleteFlag')->willReturnCallback(
            static function (string $flag) use (&$deleted): bool {
                $deleted[] = $flag;

                return true;
            }
        );

        $notifier = $this->createMock(NotifierInterface::class);
        $title = '';
        $body = '';
        $notifier->expects(self::once())->method('addMajor')->willReturnCallback(
            static function (string $t, string $b) use (&$title, &$body): void {
                $title = $t;
                $body = $b;
            }
        );

        $this->createCron(true, $flags, $notifier)->execute();

        self::assertContains(HealthCheck::FLAG_ENGINE_DOWN_SINCE, $deleted);
        self::assertNotContains(HealthCheck::FLAG_ENGINE_DOWN_SINCE, $saved);
        self::assertStringContainsString('not active', $title);
        self::assertStringNotContainsString('recover', $body);
    }

    public function testAnUnreachableEngineStillStartsTheOutageClock(): void
    {
        $flags = $this->createMock(FlagManager::class);
        $flags->expects(self::once())->method('saveFlag')
            ->with(HealthCheck::FLAG_ENGINE_DOWN_SINCE, self::anything());
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects(self::never())->method('addMajor');

        $this->createCron(false, $flags, $notifier)->execute();
    }

    private function createCron(
        bool $refused,
        FlagManager&MockObject $flags,
        NotifierInterface&MockObject $notifier
    ): HealthCheck {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn($refused);

        $client = $this->createMock(Client::class);
        $client->method('ping')->willThrowException(new EngineRequestException('HTTP 403', 403));

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_757_000_000);

        $queueHealth = $this->createMock(QueueHealth::class);
        $queueHealth->method('failedSince')->willReturn(0);

        return new HealthCheck(
            $settings,
            $client,
            $flags,
            $notifier,
            $queueHealth,
            $dateTime,
            $this->createMock(Logger::class)
        );
    }
}
