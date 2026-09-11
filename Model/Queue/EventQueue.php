<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event\CollectionFactory;

/**
 * Durable, idempotent queue for outbound Smaily marketing events.
 *
 * Retry semantics mirror the WooCommerce plugin: exponential backoff of
 * 60s, 5m, 15m, 1h, 6h with at most 5 attempts, after which a row is
 * parked as failed for manual retry from the admin event log. Which
 * failures earn a retry at all is RetryPolicy's call.
 */
class EventQueue
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];
    public const MAX_ERROR_LENGTH = 60000;

    /**
     * What a withdrawn row records in place of an API reply. Not a status:
     * a status is what the flusher reads to decide what to send, and a
     * cancelled row is terminal exactly like a delivered one.
     */
    public const CANCELLED_RESPONSE = 'cancelled';

    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly EventResource $eventResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Json $serializer,
        private readonly DateTime $dateTime,
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger
    ) {
    }

    /**
     * Add an event to the queue.
     *
     * Passing a deterministic $eventUuid makes the enqueue idempotent:
     * a duplicate is silently skipped.
     *
     * @param array<int|string, mixed> $payload
     * @return bool true when queued, false when skipped as a duplicate
     */
    public function enqueue(
        string $eventType,
        array $payload,
        ?string $entityId = null,
        int $websiteId = 0,
        ?string $eventUuid = null
    ): bool {
        $event = $this->eventFactory->create();
        $event->addData([
            'event_type' => $eventType,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'website_id' => $websiteId,
            'payload' => $this->serializer->serialize($payload),
            'status' => Event::STATUS_PENDING,
            'attempts' => 0,
        ]);

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            $this->logger->debug('Skipped duplicate queue event', [
                'event_type' => $eventType,
                'event_uuid' => $eventUuid,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Claim due pending events for processing (marks them as sending).
     *
     * @return Event[]
     */
    public function claimBatch(int $limit = 200): array
    {
        $now = $this->dateTime->gmtDate();
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', Event::STATUS_PENDING)
            ->addFieldToFilter('next_retry_at', [
                ['null' => true],
                ['lteq' => $now],
            ])
            ->setOrder('id', 'ASC')
            ->setPageSize($limit);

        $events = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof Event) {
                $events[(int)$item->getId()] = $item;
            }
        }
        if (!$events) {
            return [];
        }

        // Claim with a per-worker token: only rows this worker actually
        // transitioned are processed, so a concurrent flush (manual cron run,
        // multi-node cron) can never double-send the same event.
        $token = $this->identityGenerator->generateId();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(EventResource::TABLE_NAME);
        $connection->update(
            $table,
            [
                'status' => Event::STATUS_SENDING,
                'claim_token' => $token,
                'claimed_at' => $now,
            ],
            [
                'id IN (?)' => array_keys($events),
                'status = ?' => Event::STATUS_PENDING,
            ]
        );

        $claimedIds = array_map('intval', $connection->fetchCol(
            $connection->select()->from($table, ['id'])
                ->where('claim_token = ?', $token)
                ->where('status = ?', Event::STATUS_SENDING)
        ));

        $claimed = [];
        foreach ($claimedIds as $id) {
            if (isset($events[$id])) {
                $events[$id]->setData('status', Event::STATUS_SENDING);
                $claimed[] = $events[$id];
            }
        }

        return $claimed;
    }

    /**
     * Return rows stuck in "sending" (killed worker, OOM, deploy) back to
     * pending so they are retried instead of being lost forever.
     */
    public function requeueStale(int $olderThanSeconds = 900): int
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
            ['status' => Event::STATUS_PENDING, 'claim_token' => null],
            [
                'status = ?' => Event::STATUS_SENDING,
                'claimed_at < ?' => $this->dateTime->gmtDate(
                    'Y-m-d H:i:s',
                    $this->dateTime->gmtTimestamp() - $olderThanSeconds
                ),
            ]
        );
    }

    /**
     * Mark an event as delivered.
     */
    public function markSent(Event $event, ?string $sentPayload = null, ?string $response = null): void
    {
        $event->addData($this->terminalFields($response) + [
            'last_error' => null,
            'sent_payload' => $sentPayload,
        ]);
        $this->eventResource->save($event);
    }

    /**
     * Record a failed delivery attempt; reschedules with backoff (or with the
     * delay Smaily itself asked for) or parks the event as failed once
     * attempts are exhausted.
     *
     * $terminal parks the row on the spot with its remaining attempts unspent:
     * a refusal that no amount of retrying can change (RetryPolicy decides
     * which those are). The attempt that WAS refused is still counted.
     */
    public function markFailed(
        Event $event,
        string $error,
        ?string $sentPayload = null,
        ?string $response = null,
        ?int $retryAfter = null,
        bool $terminal = false
    ): void {
        $attempts = $event->getAttempts() + 1;
        $exhausted = $terminal || $attempts >= self::MAX_ATTEMPTS;

        $event->addData([
            'attempts' => $attempts,
            'status' => $exhausted ? Event::STATUS_FAILED : Event::STATUS_PENDING,
            'next_retry_at' => $exhausted ? null : $this->nextRetryAt($attempts, $retryAfter),
            'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
            'sent_payload' => $sentPayload,
            'last_response' => $response,
        ]);
        $this->eventResource->save($event);

        if ($exhausted) {
            $this->logger->error('Queue event failed permanently', [
                'id' => $event->getId(),
                'event_type' => $event->getEventType(),
                'error' => $error,
            ]);
        }
    }

    /**
     * Reset failed events back to pending (admin manual retry).
     *
     * A row anonymised by an Art. 17 erasure (PRO-2452) is skipped: it no
     * longer holds a recipient, so retrying it would put the placeholder on
     * the wire.
     *
     * @param int[] $ids
     * @return int number of rows reset
     */
    public function retry(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
            [
                'status' => Event::STATUS_PENDING,
                'attempts' => 0,
                'next_retry_at' => null,
            ],
            [
                'id IN (?)' => array_map('intval', $ids),
                'status = ?' => Event::STATUS_FAILED,
                'entity_id IS NULL OR entity_id != ?' => Erasure::PLACEHOLDER,
            ]
        );
    }

    /**
     * Withdraw a contact's still-pending automation rows of one trigger
     * (PRO-2453): the shopper bought, so the reminder must not go out. Only
     * `pending` rows are taken — a claimed one is a worker's.
     *
     * @return int number of rows cancelled
     */
    public function cancelPendingAutomation(string $trigger, string $entityId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(EventResource::TABLE_NAME);

        $rows = $connection->fetchPairs(
            $connection->select()->from($table, ['id', 'payload'])
                ->where('event_type = ?', EventType::AUTOMATION_TRIGGER)
                ->where('entity_id = ?', $entityId)
                ->where('status = ?', Event::STATUS_PENDING)
        );

        $ids = [];
        foreach ($rows as $id => $payload) {
            if (($this->decodeArray((string)$payload)['trigger_type'] ?? null) === $trigger) {
                $ids[] = (int)$id;
            }
        }
        if (!$ids) {
            return 0;
        }

        return $connection->update(
            $table,
            $this->terminalFields(self::CANCELLED_RESPONSE),
            [
                'id IN (?)' => $ids,
                'status = ?' => Event::STATUS_PENDING,
            ]
        );
    }

    /**
     * Decode an event payload.
     *
     * @return array<int|string, mixed>
     */
    public function decodePayload(Event $event): array
    {
        $payload = $this->decodeArray($event->getPayload());
        // The Log's "Send again" record is our own bookkeeping (PRO-2454) —
        // it is stored with the row, never sent.
        unset($payload[Resend::PAYLOAD_KEY]);

        return $payload;
    }

    /**
     * A stored JSON payload as an array; anything else decodes to nothing.
     *
     * @return array<int|string, mixed>
     */
    private function decodeArray(string $payload): array
    {
        $decoded = $this->serializer->unserialize($payload);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The fields that close a row for good — delivered, or withdrawn because
     * the reminder became moot: status sent and nothing left to retry, with
     * $response holding the API reply or CANCELLED_RESPONSE in its place, so
     * the Log keeps the row either way.
     *
     * @return array<string, mixed>
     */
    private function terminalFields(?string $response): array
    {
        return [
            'status' => Event::STATUS_SENT,
            'next_retry_at' => null,
            'last_response' => $response,
        ];
    }

    private function nextRetryAt(int $attempts, ?int $retryAfter = null): string
    {
        // A delay Smaily asked for wins over the ladder, capped at the
        // ladder's own ceiling so a wild header cannot park a row for days.
        $backoff = $retryAfter !== null && $retryAfter > 0
            ? min($retryAfter, self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1])
            : self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
