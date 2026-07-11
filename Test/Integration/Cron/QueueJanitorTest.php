<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Smaily\Connect\Cron\QueueJanitor;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * Retention sweep against real queue tables: sent rows kept 30 days,
 * failed rows 90 days, undelivered rows never pruned.
 */
class QueueJanitorTest extends IntegrationTestCase
{
    private const DAY = 86400;

    public function testPruneRespectsPerStatusRetentionInBothQueueTables(): void
    {
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $this->seedRow($table, 'sent-fresh', 'sent', 29);
            $this->seedRow($table, 'sent-old', 'sent', 31);
            $this->seedRow($table, 'failed-fresh', 'failed', 89);
            $this->seedRow($table, 'failed-old', 'failed', 91);
            $this->seedRow($table, 'pending-ancient', 'pending', 400);
        }

        /** @var QueueJanitor $janitor */
        $janitor = $this->objectManager->create(QueueJanitor::class);
        $janitor->execute();

        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $remaining = array_column($this->fetchAll($table), 'event_uuid');
            sort($remaining);
            self::assertSame(
                ['failed-fresh', 'pending-ancient', 'sent-fresh'],
                $remaining,
                sprintf('Retention boundaries must hold in %s', $table)
            );
        }
    }

    private function seedRow(string $table, string $uuid, string $status, int $ageDays): void
    {
        $row = [
            'entity_id' => null,
            'event_uuid' => $uuid,
            'payload' => '{}',
            'status' => $status,
            'attempts' => 0,
        ];
        if ($table === EventResource::TABLE_NAME) {
            $row['event_type'] = 'contact.sync';
            $row['website_id'] = 0;
        } else {
            $row['domain'] = 'catalog';
        }
        $this->connection->insert($table, $row);

        // Backdate updated_at explicitly (overrides ON UPDATE CURRENT_TIMESTAMP).
        $this->connection->update(
            $table,
            ['updated_at' => $this->clockDate(-$ageDays * self::DAY)],
            ['event_uuid = ?' => $uuid]
        );
    }
}
