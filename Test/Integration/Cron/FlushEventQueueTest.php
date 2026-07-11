<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\RecordingHandler;

/**
 * The queue flush cron end-to-end against a real database, with the
 * HTTP-facing handlers replaced by scriptable stubs: delivery outcomes,
 * batch failures, missing handlers and stale-claim recovery.
 */
class FlushEventQueueTest extends IntegrationTestCase
{
    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
    }

    public function testSuccessfulRunDeliversAllClaimedEvents(): void
    {
        $this->queue->enqueue('contact.sync', ['email' => 'a@example.com'], null, 0, 'f-1');
        $this->queue->enqueue('contact.sync', ['email' => 'b@example.com'], null, 0, 'f-2');

        $handler = new RecordingHandler(
            static fn (array $events): array => array_fill_keys(
                array_map(static fn (Event $event): int => (int)$event->getId(), $events),
                true
            )
        );
        $this->runCron(['contact.sync' => $handler]);

        self::assertCount(1, $handler->getBatches(), 'One batch per event type per run');
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_SENT, $row['status']);
        }
    }

    public function testPerEventErrorsMarkOnlyThoseEventsFailed(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-ok');
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-bad');

        $handler = new RecordingHandler(static function (array $events): array {
            $results = [];
            foreach ($events as $event) {
                $results[(int)$event->getId()] = $event->getEventUuid() === 'f-bad'
                    ? 'recipient rejected'
                    : true;
            }

            return $results;
        });
        $this->runCron(['contact.sync' => $handler]);

        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_SENT, $rows['f-ok']['status']);
        self::assertSame(Event::STATUS_PENDING, $rows['f-bad']['status'], 'Failed event is rescheduled');
        self::assertSame('1', (string)$rows['f-bad']['attempts']);
        self::assertSame('recipient rejected', $rows['f-bad']['last_error']);
    }

    public function testClientExceptionReschedulesTheWholeBatch(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-b1');
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-b2');

        $handler = new RecordingHandler(static function (): array {
            throw new SmailyClientException('Smaily API is unreachable');
        });
        $this->runCron(['contact.sync' => $handler]);

        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame('1', (string)$row['attempts']);
            self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
            self::assertSame('Smaily API is unreachable', $row['last_error']);
        }
    }

    public function testEventsWithoutAHandlerAreParkedImmediately(): void
    {
        $this->queue->enqueue('unknown.event', [], null, 0, 'f-unknown');

        $this->runCron([]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status'], 'Retrying cannot make a handler appear');
        self::assertStringContainsString('No handler registered', (string)$row['last_error']);
    }

    public function testStaleClaimIsRecoveredAndDeliveredInTheSameRun(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-stale');
        $this->queue->claimBatch();
        $this->clock->travel(901);

        $handler = new RecordingHandler(
            static fn (array $events): array => array_fill_keys(
                array_map(static fn (Event $event): int => (int)$event->getId(), $events),
                true
            )
        );
        $this->runCron(['contact.sync' => $handler]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status'], 'Stale sending rows are requeued, then delivered');
    }

    /**
     * @param array<string, RecordingHandler> $handlers
     */
    private function runCron(array $handlers): void
    {
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool($handlers),
        ]);
        $cron->execute();
    }
}
