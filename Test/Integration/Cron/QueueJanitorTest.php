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
 * Retention sweep against real tables: queue rows (sent kept 30 days, failed
 * 90, undelivered never) and the abandoned-cart tracker (PRO-2469).
 */
class QueueJanitorTest extends IntegrationTestCase
{
    private const DAY = 86400;

    private const CART_TABLE = 'smaily_abandoned_cart';

    protected function setUp(): void
    {
        parent::setUp();

        // The tracker sweep joins the core quote table; the standalone
        // integration environment installs only the module's own schema.
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        $this->connection->query(
            'CREATE TABLE `quote` ('
            . ' `entity_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `store_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' PRIMARY KEY (`entity_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

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

    /**
     * PRO-2469: nothing else prunes the tracker — Magento's quote cleanup
     * does not cascade onto the side table — so the janitor drops terminal
     * rows past the retention window and rows whose quote is gone, and
     * nothing else.
     */
    public function testAbandonedCartRowsGoOnRetentionAndOnAVanishedQuote(): void
    {
        $this->seedQuote(1);
        $this->seedQuote(2);
        $this->seedQuote(3);
        $this->seedQuote(4);
        $this->seedQuote(5);
        // Quotes 6 and 7 are gone from the store (Magento's own cleanup).

        $this->seedCartRow(1, 'completed', 31);
        $this->seedCartRow(2, 'completed', 29);
        $this->seedCartRow(3, 'erased', 31);
        $this->seedCartRow(4, 'mailed', 31);
        $this->seedCartRow(5, 'open', 400);
        $this->seedCartRow(6, 'open', 1);
        $this->seedCartRow(7, 'mailed', 1);

        /** @var QueueJanitor $janitor */
        $janitor = $this->objectManager->create(QueueJanitor::class);
        $janitor->execute();

        $remaining = array_map('intval', array_column($this->fetchAll(self::CART_TABLE), 'quote_id'));
        sort($remaining);
        self::assertSame(
            [2, 5],
            $remaining,
            'A fresh terminal row and a live non-terminal row survive; everything else goes'
        );
    }

    private function seedQuote(int $entityId): void
    {
        $this->connection->insert('quote', ['entity_id' => $entityId, 'store_id' => 1]);
    }

    private function seedCartRow(int $quoteId, string $status, int $ageDays): void
    {
        $this->connection->insert(self::CART_TABLE, [
            'quote_id' => $quoteId,
            'store_id' => 1,
            'email' => $status === 'erased' ? null : sprintf('cart-%d@example.test', $quoteId),
            'status' => $status,
        ]);
        $this->connection->update(
            self::CART_TABLE,
            ['updated_at' => $this->clockDate(-$ageDays * self::DAY)],
            ['quote_id = ?' => $quoteId]
        );
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
