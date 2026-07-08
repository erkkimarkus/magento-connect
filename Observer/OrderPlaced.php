<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;

/**
 * Order placement side effects (sales_order_place_after):
 * 1. The quote is no longer an abandoned cart candidate.
 * 2. Checkout newsletter opt-in and guest-email contact sync.
 * 3. First-order automation for a customer's first purchase.
 */
class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly SyncDispatcher $dispatcher,
        private readonly StateManager $abandonedCartState,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $quoteId = (int)$order->getQuoteId();
        if ($quoteId > 0) {
            $optedIn = $this->abandonedCartState->isOptedIn($quoteId);
            $this->abandonedCartState->markCompleted($quoteId);
        } else {
            $optedIn = false;
        }

        $storeId = (int)$order->getStoreId();
        if (!$this->config->isConnected($storeId ?: null)) {
            return;
        }

        $websiteId = $this->dispatcher->websiteId($storeId);
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($email === '') {
            return;
        }

        $this->syncContact($order, $email, $storeId, $websiteId, $optedIn);
        $this->triggerFirstOrder($order, $email, $storeId, $websiteId);
    }

    private function syncContact(
        OrderInterface $order,
        string $email,
        int $storeId,
        int $websiteId,
        bool $optedIn
    ): void {
        if (!$this->config->isSyncEnabled($websiteId)) {
            return;
        }

        if ($optedIn) {
            // Explicit checkout opt-in: subscribe regardless of mode.
            $this->dispatcher->dispatchContactSync($email, $storeId, false, null);

            return;
        }

        // Guest-email inclusion without explicit opt-in (mode-driven toggle):
        // is_unsubscribed is omitted so Smaily's own suppression state rules.
        $isGuest = (bool)$order->getCustomerIsGuest();
        if ($isGuest && $this->mode->includeGuests($websiteId)
            && $this->mode->mode($websiteId) !== \Smaily\Connect\Model\Config\Source\SyncMode::MODE_CHECKOUT_OPTIN
        ) {
            $this->dispatcher->dispatchContactSync($email, $storeId, null, null);
        }
    }

    private function triggerFirstOrder(OrderInterface $order, string $email, int $storeId, int $websiteId): void
    {
        $customerId = (int)$order->getCustomerId();
        if ($customerId <= 0 || !$this->config->isFirstOrderEnabled($websiteId)) {
            return;
        }

        $orderCount = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', ['eq' => $customerId])
            ->getSize();
        // The current order may or may not be persisted yet at place_after.
        if ($orderCount > 1) {
            return;
        }

        $address = [
            'email' => $email,
            'is_first_order' => 'true',
            'order_id' => (string)$order->getIncrementId(),
            'order_total' => (string)$order->getGrandTotal(),
            'order_currency' => (string)$order->getOrderCurrencyCode(),
        ];
        $firstname = trim((string)$order->getCustomerFirstname());
        $lastname = trim((string)$order->getCustomerLastname());
        if ($firstname !== '') {
            $address['first_name'] = $firstname;
        }
        if ($lastname !== '') {
            $address['last_name'] = $lastname;
        }
        try {
            $address['store'] = (string)$this->storeManager->getStore($storeId)->getName();
        } catch (LocalizedException) {
            // Store name is decorative in the payload; proceed without it.
        }

        $this->dispatcher->dispatchAutomation(Trigger::FIRST_ORDER, $storeId, $address);
    }
}
