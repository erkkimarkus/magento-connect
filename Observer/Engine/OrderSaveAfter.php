<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Order ingest on every order save whose state maps onto the engine enum.
 * Natural-key upsert engine-side (external_order_id) makes repeated status
 * saves safe; transient states (hold, payment review) are skipped.
 */
class OrderSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isOrderSyncEnabled()) {
            return;
        }

        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order) {
            return;
        }

        // Only enqueue when the state actually changed (or the order is new)
        // to avoid a queue row for every invoice/shipment/comment save.
        if (!$order->isObjectNew() && $order->getOrigData('state') === $order->getState()) {
            return;
        }

        $item = $this->payloadBuilder->build($order);
        if ($item === null) {
            return;
        }

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $item, (string)$order->getIncrementId());
    }
}
