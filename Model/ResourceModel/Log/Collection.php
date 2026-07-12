<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Log;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Unified log collection: one grid over BOTH delivery queues. The two
 * tables share the operational column set (status/attempts/last_error/
 * timestamps), so the rows are merged with a plain UNION ALL wrapped as a
 * derived table — grid filters and sorting apply to the outer select.
 * "source" tells the queues apart; "log_id" ("smaily-<id>" /
 * "intelligence-<id>") keys rows uniquely across both tables and routes
 * mass retries back to the right queue.
 */
class Collection extends SearchResult
{
    public const SOURCE_SMAILY = 'smaily';
    public const SOURCE_INTELLIGENCE = 'intelligence';

    /**
     * @inheritDoc
     */
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            EventResource::TABLE_NAME,
            UnifiedRow::class
        );
    }

    /**
     * @inheritDoc
     */
    protected function _initSelect()
    {
        $union = $this->getConnection()->select()->union(
            [
                $this->sourceSelect(
                    $this->getTable(EventResource::TABLE_NAME),
                    self::SOURCE_SMAILY,
                    'event_type'
                ),
                $this->sourceSelect(
                    $this->getTable(IngestEventResource::TABLE_NAME),
                    self::SOURCE_INTELLIGENCE,
                    'domain'
                ),
            ],
            Select::SQL_UNION_ALL
        );
        $this->getSelect()->from(['main_table' => $union]);
    }

    /**
     * The normalized per-queue half of the union.
     */
    private function sourceSelect(string $table, string $source, string $typeColumn): Select
    {
        $connection = $this->getConnection();

        return $connection->select()->from(
            ['q' => $table],
            [
                'log_id' => new \Zend_Db_Expr(
                    'CONCAT(' . $connection->quote($source . '-') . ', q.id)'
                ),
                'source' => new \Zend_Db_Expr($connection->quote($source)),
                'type' => 'q.' . $typeColumn,
                'entity_id' => 'q.entity_id',
                'status' => 'q.status',
                'attempts' => 'q.attempts',
                'last_error' => 'q.last_error',
                'created_at' => 'q.created_at',
                'updated_at' => 'q.updated_at',
            ]
        );
    }
}
