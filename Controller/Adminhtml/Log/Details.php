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
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

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
        private readonly Resend $resend,
        private readonly PayloadDecoder $payloadDecoder,
        private readonly QueueStatusOptions $statusOptions
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

        $payload = $this->payloadDecoder->decode((string)($row['payload'] ?? ''));
        $reason = $row
            ? $this->resendGuard->refusalReason((string)$row['source'], (int)$row['id'], $row)
            : '';
        $lastError = (string)($row['last_error'] ?? '');

        /** @var Template $block */
        $block = $this->layout->createBlock(Template::class, '', ['data' => [
            'template' => 'Smaily_Connect::log/details.phtml',
            'row' => $row,
            'redactor' => $this->redactor,
            'payload' => $payload,
            'status_labels' => array_column($this->statusOptions->toOptionArray(), 'label', 'value'),
            // A row that simply never failed is refused too, but the drawer
            // has its own honest line for a pending or delivered row.
            'refusal' => $reason === '' || $reason === ResendGuard::REASON_NOT_FAILED
                ? null
                : $this->resendGuard->message($reason),
            'resend_record' => $this->resend->recordOf($payload),
            'last_error' => $this->failureMessage->forDisplay($lastError),
            'failure_class' => $this->failureMessage->failureClass($lastError),
        ]]);

        return $result->setContents($block->toHtml());
    }
}
