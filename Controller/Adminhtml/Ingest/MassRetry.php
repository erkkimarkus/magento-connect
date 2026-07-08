<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Ingest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\CollectionFactory;

/**
 * Resets selected failed ingest events back to pending.
 */
class MassRetry extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
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
        $retried = $this->ingestQueue->retry(array_map('intval', $collection->getAllIds()));

        $this->messageManager->addSuccessMessage(
            (string)__('%1 ingest event(s) queued for retry.', $retried)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/ingest');
    }
}
