<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Retention sweep for terminal rows: queue rows are kept 30 days when sent
 * and 90 days when failed (mirrors the WooCommerce plugin janitor), and the
 * abandoned-cart tracker is swept on the same 30-day window (PRO-2469).
 */
class QueueJanitor
{
    private const SENT_RETENTION_DAYS = 30;
    private const FAILED_RETENTION_DAYS = 90;
    private const DELETE_CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    /**
     * The module's queue tables. Public because this sweep is not their only
     * walker: the Art. 17 eraser (Model\Privacy\LocalEraser) covers the same
     * pair, and a third queue must reach both from one place.
     */
    public const TABLES = [
        EventResource::TABLE_NAME,
        IngestEventResource::TABLE_NAME,
    ];

    public function execute(): void
    {
        $deleted = 0;
        foreach (self::TABLES as $table) {
            $deleted += $this->prune($table, Event::STATUS_SENT, self::SENT_RETENTION_DAYS)
                + $this->prune($table, Event::STATUS_FAILED, self::FAILED_RETENTION_DAYS);
        }

        $deleted += $this->pruneAbandonedCarts();

        if ($deleted > 0) {
            $this->logger->info('Queue janitor pruned rows', ['deleted' => $deleted]);
        }
    }

    /**
     * Sweep the abandoned-cart tracker (PRO-2469). Magento's own quote
     * cleanup does not cascade onto this side table and nothing else prunes
     * it, so two kinds of row go: a terminal one — including the Art. 17
     * tombstone — past the sent-row retention window, and any row whose
     * quote is gone from `quote`, whatever its status, because the marker
     * has nothing left to mark. A live quote in a non-terminal status is
     * never touched: that row is what keeps the cron honest.
     */
    private function pruneAbandonedCarts(): int
    {
        $connection = $this->resourceConnection->getConnection(StateManager::CONNECTION);
        $table = $this->resourceConnection->getTableName(StateManager::TABLE_NAME, StateManager::CONNECTION);
        $quoteTable = $this->resourceConnection->getTableName('quote', StateManager::CONNECTION);
        $cutoff = $this->cutoff(self::SENT_RETENTION_DAYS);

        $condition = sprintf(
            '(%s AND %s) OR quote_table.entity_id IS NULL',
            $connection->quoteInto('cart.status IN (?)', StateManager::TERMINAL_STATUSES),
            $connection->quoteInto('cart.updated_at < ?', $cutoff)
        );

        $totalDeleted = 0;
        do {
            $select = $connection->select()
                ->from(['cart' => $table], ['id'])
                ->joinLeft(['quote_table' => $quoteTable], 'quote_table.entity_id = cart.quote_id', [])
                ->where($condition)
                ->limit(self::DELETE_CHUNK);
            $ids = $connection->fetchCol($select);
            if ($ids) {
                $totalDeleted += $connection->delete($table, ['id IN (?)' => $ids]);
            }
        } while (count($ids) === self::DELETE_CHUNK);

        return $totalDeleted;
    }

    private function prune(string $tableName, string $status, int $retentionDays): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($tableName);
        $cutoff = $this->cutoff($retentionDays);

        $totalDeleted = 0;
        do {
            $select = $connection->select()
                ->from($table, ['id'])
                ->where('status = ?', $status)
                ->where('updated_at < ?', $cutoff)
                ->limit(self::DELETE_CHUNK);
            $ids = $connection->fetchCol($select);
            if ($ids) {
                $totalDeleted += $connection->delete($table, ['id IN (?)' => $ids]);
            }
        } while (count($ids) === self::DELETE_CHUNK);

        return $totalDeleted;
    }

    private function cutoff(int $retentionDays): string
    {
        return $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - $retentionDays * 86400
        );
    }
}
