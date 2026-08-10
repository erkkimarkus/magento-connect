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
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Order ingest on every order save whose state maps onto the engine enum, or
 * whose refunded total moved. Natural-key upsert engine-side
 * (external_order_id) makes repeated status saves safe; transient states
 * (hold, payment review) are skipped.
 *
 * Recommendation attribution is captured here (not at place_after) because
 * the order entity_id only exists after the save; on the placement request
 * the visitor's cookies are still present, elsewhere (admin, cron) they are
 * simply absent and the capture is a no-op.
 */
class OrderSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly AttributionManager $attributionManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order) {
            return;
        }

        $isNewOrder = $order->getOrigData('state') === null;
        if ($isNewOrder && $order->getEntityId()) {
            try {
                $this->attributionManager->saveForOrder((int)$order->getEntityId());
            } catch (\Throwable) {
                // Attribution must never block order processing.
            }
        }

        if (!$this->settings->isConnected()) {
            return;
        }

        // Only enqueue when something the engine cares about actually changed
        // (or the order is new) — otherwise every invoice/shipment/comment
        // save would cost a queue row. A refund counts as such a change even
        // when the state does not move: a PARTIAL credit memo leaves the order
        // processing/complete, and its per-line return signal (contract §5)
        // would never reach the engine on a state-only gate.
        $refunded = (float)$order->getTotalRefunded() !== (float)$order->getOrigData('total_refunded');
        if (!$isNewOrder && !$refunded && $order->getOrigData('state') === $order->getState()) {
            return;
        }

        $item = $this->payloadBuilder->build($order);
        if ($item === null) {
            return;
        }

        $this->ingestQueue->enqueue(Client::DOMAIN_ORDERS, $item, (string)$order->getIncrementId());
    }
}
