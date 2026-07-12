<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Log\CollectionFactory;

/**
 * Resets selected failed rows back to pending — the composite log id routes
 * each row to its own queue (Smaily events / Intelligence ingest).
 */
class MassRetry extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly EventQueue $eventQueue,
        private readonly IngestQueue $ingestQueue
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $ids = [Collection::SOURCE_SMAILY => [], Collection::SOURCE_INTELLIGENCE => []];
        foreach ($collection->getAllIds() as $logId) {
            [$source, $id] = array_pad(explode('-', (string)$logId, 2), 2, '');
            if (isset($ids[$source]) && $id !== '') {
                $ids[$source][] = (int)$id;
            }
        }

        $retried = $this->eventQueue->retry($ids[Collection::SOURCE_SMAILY])
            + $this->ingestQueue->retry($ids[Collection::SOURCE_INTELLIGENCE]);

        $this->messageManager->addSuccessMessage(
            (string)__('%1 event(s) queued for retry.', $retried)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/log');
    }
}
