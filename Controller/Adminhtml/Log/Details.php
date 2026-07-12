<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Per-row drill-down for the unified log grid: renders the delivery detail
 * panel (redacted payload, attempt history, last response) that the grid's
 * Details action loads into a slide-out modal. The composite log id
 * ("smaily-<id>" / "intelligence-<id>") routes the lookup to the right
 * queue table.
 */
class Details extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    /** Queue source -> [table, type column]. */
    private const SOURCES = [
        Collection::SOURCE_SMAILY => [EventResource::TABLE_NAME, 'event_type'],
        Collection::SOURCE_INTELLIGENCE => [IngestEventResource::TABLE_NAME, 'domain'],
    ];

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly LayoutInterface $layout,
        private readonly ResourceConnection $resourceConnection,
        private readonly PayloadRedactor $redactor
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);

        $row = $this->loadRow((string)$this->getRequest()->getParam('log_id'));
        if ($row === null) {
            $result->setHttpResponseCode(404);
            $row = [];
        }

        /** @var Template $block */
        $block = $this->layout->createBlock(Template::class, '', ['data' => [
            'template' => 'Smaily_Connect::log/details.phtml',
            'row' => $row,
            'redactor' => $this->redactor,
        ]]);

        return $result->setContents($block->toHtml());
    }

    /**
     * Load the queue row addressed by a composite log id, normalized for
     * the detail template (the per-queue type column becomes "type").
     *
     * @return array<string, mixed>|null
     */
    private function loadRow(string $logId): ?array
    {
        [$source, $id] = array_pad(explode('-', $logId, 2), 2, '');
        if (!isset(self::SOURCES[$source]) || (int)$id < 1) {
            return null;
        }
        [$table, $typeColumn] = self::SOURCES[$source];

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName($table))
            ->where('id = ?', (int)$id);
        $row = $connection->fetchRow($select);
        if (!is_array($row) || !$row) {
            return null;
        }

        $row['source'] = $source;
        $row['type'] = (string)($row[$typeColumn] ?? '');

        return $row;
    }
}
