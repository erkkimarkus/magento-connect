<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Retention sweep for terminal queue rows: sent rows are kept 30 days,
 * failed rows 90 days (mirrors the WooCommerce plugin janitor).
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

        if ($deleted > 0) {
            $this->logger->info('Queue janitor pruned rows', ['deleted' => $deleted]);
        }
    }

    private function prune(string $tableName, string $status, int $retentionDays): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($tableName);
        $cutoff = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - $retentionDays * 86400
        );

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
}
