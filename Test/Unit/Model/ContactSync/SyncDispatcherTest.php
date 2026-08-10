<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\ContactSync;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\EventQueue;

class SyncDispatcherTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $enqueued = [];

    private SubscriberPayloadBuilder&MockObject $payloadBuilder;
    private SyncDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->enqueued = [];

        $this->payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        $languageResolver = $this->createMock(LanguageResolver::class);
        $languageResolver->method('forStore')->willReturn('en');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('enqueue')->willReturnCallback(
            function (string $eventType, array $payload): bool {
                $this->enqueued[] = ['event_type' => $eventType, 'payload' => $payload];

                return true;
            }
        );

        $this->dispatcher = new SyncDispatcher(
            $this->payloadBuilder,
            $languageResolver,
            $storeManager,
            $eventQueue
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markerProvider(): array
    {
        return [
            'welcome' => [Trigger::WELCOME, 'welcome_automation_at'],
            'first order' => [Trigger::FIRST_ORDER, 'first_order_automation_at'],
            'abandoned cart' => [Trigger::ABANDONED_CART, 'abandoned_cart_automation_at'],
        ];
    }

    /**
     * @dataProvider markerProvider
     */
    public function testEachTriggerStampsItsOwnMarkerWithTheRunTimestamp(string $trigger, string $field): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $this->dispatcher->dispatchAutomation($trigger, 1, ['email' => 'shopper@example.com']);
        $after = gmdate('Y-m-d H:i:s');

        $address = $this->enqueued[0]['payload']['address'];
        self::assertArrayHasKey($field, $address);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $address[$field]);
        self::assertGreaterThanOrEqual($before, $address[$field]);
        self::assertLessThanOrEqual($after, $address[$field]);

        // Only its own — a trigger never speaks for one that did not fire.
        foreach (Trigger::MARKER_FIELDS as $other) {
            if ($other !== $field) {
                self::assertArrayNotHasKey($other, $address);
            }
        }
    }

    public function testExistingPayloadFieldsAreLeftUntouched(): void
    {
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, [
            'email' => 'shopper@example.com',
            'is_abandoned_cart' => 'true',
        ]);

        $address = $this->enqueued[0]['payload']['address'];
        self::assertSame('true', $address['is_abandoned_cart']);
        self::assertSame('shopper@example.com', $address['email']);
    }

    public function testContactSyncCarriesNoMarker(): void
    {
        $this->payloadBuilder->method('build')->willReturn(['email' => 'shopper@example.com']);

        $this->dispatcher->dispatchContactSync('shopper@example.com', 1, false);

        $contact = $this->enqueued[0]['payload']['contact'];
        foreach (Trigger::MARKER_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $contact);
        }
    }
}
