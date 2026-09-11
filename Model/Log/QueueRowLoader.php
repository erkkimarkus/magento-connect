<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Loads the queue row a composite log id ("smaily-<id>" / "intelligence-<id>")
 * addresses, normalized the way the Log reads it: the per-queue type column
 * becomes "type" and the queue it came from becomes "source". Both log
 * actions that work on a single row — Details and Send again — start here.
 */
class QueueRowLoader
{
    /** Queue source -> [table, type column]. */
    private const SOURCES = [
        Collection::SOURCE_SMAILY => [EventResource::TABLE_NAME, 'event_type'],
        Collection::SOURCE_INTELLIGENCE => [IngestEventResource::TABLE_NAME, 'domain'],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(string $logId): ?array
    {
        [$source, $id] = Collection::splitLogId($logId);
        if ($source === '') {
            return null;
        }
        [$table, $typeColumn] = self::SOURCES[$source];

        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName($table))
                ->where('id = ?', $id)
        );
        if (!is_array($row) || !$row) {
            return null;
        }

        $row['source'] = $source;
        $row['type'] = (string)($row[$typeColumn] ?? '');

        return $row;
    }

    /**
     * The rows of one queue that are parked as failed, keyed by id and
     * narrowed to the columns a resend decision reads. The mass retry asks
     * about a whole selection at once, where a payload per row would be
     * dead weight.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function loadFailed(string $source, array $ids): array
    {
        if (!$ids || !isset(self::SOURCES[$source])) {
            return [];
        }
        [$table, $typeColumn] = self::SOURCES[$source];

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName($table),
                    ['id', 'entity_id', 'status', 'last_response', 'type' => $typeColumn]
                )
                ->where('id IN (?)', array_map('intval', $ids))
                ->where('status = ?', Event::STATUS_FAILED)
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        return $byId;
    }
}
