<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

/**
 * Builds payloads and enqueues contact-sync and automation events. The thin
 * observers delegate here so every dispatch path shares one payload shape.
 */
class SyncDispatcher
{
    public function __construct(
        private readonly SubscriberPayloadBuilder $payloadBuilder,
        private readonly LanguageResolver $languageResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly EventQueue $eventQueue
    ) {
    }

    public function dispatchContactSync(
        string $email,
        int $storeId,
        ?bool $isUnsubscribed,
        ?CustomerInterface $customer = null
    ): void {
        $contact = $this->payloadBuilder->build($email, $storeId, $isUnsubscribed, $customer);

        $this->eventQueue->enqueue(
            EventType::CONTACT_SYNC,
            ['store_id' => $storeId, 'contact' => $contact],
            $contact['email'],
            $this->websiteId($storeId)
        );
    }

    /**
     * Stamps the trigger's own run marker onto the address and enqueues it.
     * The stamp is taken here, where the trigger FIRES, not when the queue row
     * is POSTed — a retry then resends the moment the store event happened,
     * which is the moment a merchant means. See Trigger::MARKER_FIELDS.
     *
     * @param array<string, string|int> $address must contain "email"
     */
    public function dispatchAutomation(string $trigger, int $storeId, array $address): void
    {
        $marker = Trigger::MARKER_FIELDS[$trigger] ?? null;
        if ($marker !== null) {
            $address[$marker] = gmdate('Y-m-d H:i:s');
        }

        $this->eventQueue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            [
                'trigger_type' => $trigger,
                'store_id' => $storeId,
                'website_id' => $this->websiteId($storeId),
                'language' => $this->languageResolver->forStore($storeId),
                'address' => $address,
            ],
            (string)($address['email'] ?? ''),
            $this->websiteId($storeId)
        );
    }

    /**
     * The abandoned-cart workflow's exit signal, for a contact the store has
     * already tracked as abandoned (PRO-2453). Two halves of one thing: a
     * reminder still waiting in the queue is withdrawn, and the purchase
     * moment goes onto the contact as a plain contact.sync carrying the
     * address and that one field — no automation runs, and the reminder's
     * own cart and product fields are left exactly as the reminder wrote
     * them. Stamped here, at the order-placed moment, for the same reason
     * dispatchAutomation() stamps its marker at the trigger.
     */
    public function dispatchCartPurchase(string $email, int $storeId): void
    {
        $this->eventQueue->cancelPendingAutomation(Trigger::ABANDONED_CART, $email);

        $this->eventQueue->enqueue(
            EventType::CONTACT_SYNC,
            [
                'store_id' => $storeId,
                'contact' => [
                    'email' => $email,
                    Trigger::ABANDONED_CART_PURCHASED_FIELD => gmdate('Y-m-d H:i:s'),
                ],
            ],
            $email,
            $this->websiteId($storeId)
        );
    }

    public function websiteId(int $storeId): int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (LocalizedException) {
            return 0;
        }
    }
}
