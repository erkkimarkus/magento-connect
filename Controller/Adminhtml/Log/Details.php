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
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Per-row drill-down for the unified log grid: renders the delivery detail
 * panel (redacted payload, attempt history, last response) that the grid's
 * Details action loads into a slide-out modal. It also carries what the
 * grid cannot show (PRO-2454): why a row may not be sent again, and — for a
 * row that was — which failed row it repeats, at whose hand.
 */
class Details extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly LayoutInterface $layout,
        private readonly QueueRowLoader $rowLoader,
        private readonly PayloadRedactor $redactor,
        private readonly FailureMessage $failureMessage,
        private readonly ResendGuard $resendGuard,
        private readonly Resend $resend
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

        $logId = (string)$this->getRequest()->getParam('log_id');
        $row = $this->rowLoader->load($logId);
        if ($row === null) {
            $result->setHttpResponseCode(404);
            $row = [];
        }

        [$source, $id] = Collection::splitLogId($logId);
        $refusal = $row ? $this->resendGuard->refusalReason($source, $id, $row) : '';

        /** @var Template $block */
        $block = $this->layout->createBlock(Template::class, '', ['data' => [
            'template' => 'Smaily_Connect::log/details.phtml',
            'row' => $row,
            'redactor' => $this->redactor,
            'failure_message' => $this->failureMessage,
            'refusal' => $refusal === '' ? null : $this->resendGuard->message($refusal),
            'resend_record' => $this->resend->recordOf((string)($row['payload'] ?? '')),
        ]]);

        return $result->setContents($block->toHtml());
    }
}
