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
     * @param array<string, string|int> $address must contain "email"
     */
    public function dispatchAutomation(string $trigger, int $storeId, array $address): void
    {
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

    public function websiteId(int $storeId): int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (LocalizedException) {
            return 0;
        }
    }
}
