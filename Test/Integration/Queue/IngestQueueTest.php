<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Queue;

use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * IngestQueue semantics against a real smaily_ingest_queue table:
 * per-domain claiming, terminal failures, the pending counter and the
 * event_id stamping used by the engine wire format.
 */
class IngestQueueTest extends IntegrationTestCase
{
    private IngestQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

    public function testEnqueueIsIdempotentPerEventUuid(): void
    {
        self::assertTrue($this->queue->enqueue('catalog', ['sku' => 'A'], 'A', 1, 'ing-1'));
        self::assertFalse($this->queue->enqueue('catalog', ['sku' => 'B'], 'B', 1, 'ing-1'));

        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame('catalog', $rows[0]['domain']);
        self::assertSame('1', (string)$rows[0]['store_id']);
        self::assertSame(['sku' => 'A'], json_decode((string)$rows[0]['payload'], true));
    }

    public function testClaimBatchIsScopedToOneDomain(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-cat');
        $this->queue->enqueue('orders', [], null, null, 'ing-ord');

        $claimed = $this->queue->claimBatch('catalog', 100);

        self::assertCount(1, $claimed);
        self::assertSame('ing-cat', $claimed[0]->getEventUuid());

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENDING, $rows['ing-cat']['status']);
        self::assertSame(IngestEvent::STATUS_PENDING, $rows['ing-ord']['status'], 'Other domains stay untouched');
    }

    public function testMarkFailedTerminalParksImmediately(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-term');
        $claimed = $this->queue->claimBatch('catalog', 100);

        $this->queue->markFailed($claimed[0], 'price: must be a number', true);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status'], 'Validation errors must not be retried');
        self::assertSame('1', (string)$row['attempts']);
        self::assertNull($row['next_retry_at']);
    }

    public function testMarkFailedReschedulesWithSharedBackoffPolicy(): void
    {
        $this->queue->enqueue('browse', [], null, null, 'ing-retry');
        $claimed = $this->queue->claimBatch('browse', 100);

        $this->queue->markFailed($claimed[0], 'HTTP 503');

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame(
            $this->clockDate(IngestQueue::BACKOFF_SECONDS[0]),
            $row['next_retry_at'],
            'First retry follows the shared cross-platform 60s backoff'
        );
    }

    public function testCountPendingCoversPendingAndSendingRowsOfDomain(): void
    {
        $this->queue->enqueue('customers', [], null, null, 'ing-p1');
        $this->queue->enqueue('customers', [], null, null, 'ing-p2');
        $this->queue->enqueue('orders', [], null, null, 'ing-other');
        $this->queue->claimBatch('customers', 1);

        self::assertSame(2, $this->queue->countPending('customers'));
        self::assertSame(1, $this->queue->countPending('orders'));
        self::assertSame(0, $this->queue->countPending('catalog'));
    }

    public function testDecodePayloadStampsWireEventIdFromRowUuid(): void
    {
        $this->queue->enqueue('orders', ['order' => ['id' => '7']], '7', null, 'ing-wire');
        $claimed = $this->queue->claimBatch('orders', 100);

        $payload = $this->queue->decodePayload($claimed[0]);

        self::assertSame('ing-wire', $payload['event_id'], 'event_uuid doubles as the wire event_id');
        self::assertSame(['id' => '7'], $payload['order']);
    }

    public function testRetryResetsFailedRowsForManualRedelivery(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-parked');
        $claimed = $this->queue->claimBatch('catalog', 100);
        $this->queue->markFailed($claimed[0], 'bad payload', true);

        $id = (int)$this->fetchAll(IngestEventResource::TABLE_NAME)[0]['id'];
        self::assertSame(1, $this->queue->retry([$id]));

        $row = $this->fetchRow(IngestEventResource::TABLE_NAME, $id);
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame('0', (string)$row['attempts']);
    }
}
