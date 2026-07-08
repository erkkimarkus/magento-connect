<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Proactive health notices (mirrors the Woo NotificationManager):
 * - Campaign Intelligence unreachable for over an hour -> major notice once
 *   per incident.
 * - Failed queue rows exceed a threshold in 24h -> minor notice at most once
 *   a day.
 */
class HealthCheck
{
    private const FLAG_ENGINE_DOWN_SINCE = 'smaily_connect_engine_down_since';
    private const FLAG_ENGINE_NOTIFIED = 'smaily_connect_engine_down_notified';
    private const FLAG_FAILURES_NOTIFIED_AT = 'smaily_connect_failures_notified_at';

    private const ENGINE_DOWN_NOTIFY_SECONDS = 3600;
    private const FAILED_EVENTS_THRESHOLD = 25;

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly FlagManager $flagManager,
        private readonly NotifierInterface $notifier,
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        $this->checkEngine();
        $this->checkFailureVolume();
    }

    private function checkEngine(): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        try {
            $this->client->ping();
            $this->flagManager->deleteFlag(self::FLAG_ENGINE_DOWN_SINCE);
            $this->flagManager->deleteFlag(self::FLAG_ENGINE_NOTIFIED);
        } catch (EngineException $exception) {
            $now = $this->dateTime->gmtTimestamp();
            $downSince = (int)$this->flagManager->getFlagData(self::FLAG_ENGINE_DOWN_SINCE);
            if ($downSince === 0) {
                $this->flagManager->saveFlag(self::FLAG_ENGINE_DOWN_SINCE, $now);

                return;
            }

            $alreadyNotified = (bool)$this->flagManager->getFlagData(self::FLAG_ENGINE_NOTIFIED);
            if (!$alreadyNotified && ($now - $downSince) >= self::ENGINE_DOWN_NOTIFY_SECONDS) {
                $this->notifier->addMajor(
                    (string)__('Smaily Campaign Intelligence is unreachable'),
                    (string)__(
                        'The engine has not responded for over an hour (%1). Data is queued locally and will sync when the connection recovers.',
                        $exception->getMessage()
                    )
                );
                $this->flagManager->saveFlag(self::FLAG_ENGINE_NOTIFIED, 1);
                $this->logger->error('Engine down for over an hour', ['error' => $exception->getMessage()]);
            }
        }
    }

    private function checkFailureVolume(): void
    {
        $now = $this->dateTime->gmtTimestamp();
        $lastNotified = (int)$this->flagManager->getFlagData(self::FLAG_FAILURES_NOTIFIED_AT);
        if ($now - $lastNotified < 86400) {
            return;
        }

        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - 86400);
        $connection = $this->resourceConnection->getConnection();
        $failed = 0;
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName($table), ['cnt' => 'COUNT(*)'])
                ->where('status = ?', 'failed')
                ->where('updated_at >= ?', $cutoff);
            $failed += (int)$connection->fetchOne($select);
        }

        if ($failed >= self::FAILED_EVENTS_THRESHOLD) {
            $this->notifier->addMinor(
                (string)__('Smaily Connect: %1 events failed in the last 24 hours', $failed),
                (string)__('Review the event logs under Marketing > Smaily Connect and retry the failed rows.')
            );
            $this->flagManager->saveFlag(self::FLAG_FAILURES_NOTIFIED_AT, $now);
        }
    }
}
