<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\CollectionFactory;

/**
 * Durable engine ingest queue with the shared cross-platform retry policy:
 * backoff 60s/5m/15m/1h/6h, max 5 attempts (contract queue semantics; same
 * numbers as the Woo IngestQueue and the Shopify IngestQueueRow).
 *
 * Rows store the final wire payload; event_uuid doubles as the wire
 * event_id, so engine-side transport dedup makes retries safe.
 */
class IngestQueue
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public function __construct(
        private readonly IngestEventFactory $eventFactory,
        private readonly IngestEventResource $eventResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Json $serializer,
        private readonly DateTime $dateTime,
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue one wire item for a domain. Payload is stored as the final wire
     * object; event_id is filled from the row UUID at flush time.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $domain,
        array $payload,
        ?string $entityId = null,
        ?int $storeId = null,
        ?string $eventUuid = null
    ): bool {
        $event = $this->eventFactory->create();
        $event->addData([
            'domain' => $domain,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'store_id' => $storeId,
            'payload' => $this->serializer->serialize($payload),
            'status' => IngestEvent::STATUS_PENDING,
            'attempts' => 0,
        ]);

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            return false;
        }

        return true;
    }

    /**
     * Claim due pending events for one domain (marks them as sending).
     *
     * @return IngestEvent[]
     */
    public function claimBatch(string $domain, int $limit): array
    {
        $now = $this->dateTime->gmtDate();
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('domain', $domain)
            ->addFieldToFilter('status', IngestEvent::STATUS_PENDING)
            ->addFieldToFilter('next_retry_at', [
                ['null' => true],
                ['lteq' => $now],
            ])
            ->setOrder('id', 'ASC')
            ->setPageSize($limit);

        $events = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof IngestEvent) {
                $events[(int)$item->getId()] = $item;
            }
        }
        if (!$events) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME),
            ['status' => IngestEvent::STATUS_SENDING],
            [
                'id IN (?)' => array_keys($events),
                'status = ?' => IngestEvent::STATUS_PENDING,
            ]
        );

        foreach ($events as $event) {
            $event->setData('status', IngestEvent::STATUS_SENDING);
        }

        return array_values($events);
    }

    public function markSent(IngestEvent $event, ?string $response = null): void
    {
        $event->addData([
            'status' => IngestEvent::STATUS_SENT,
            'last_error' => null,
            'last_response' => $response,
        ]);
        $this->eventResource->save($event);
    }

    public function markFailed(IngestEvent $event, string $error, bool $terminal = false): void
    {
        $attempts = $event->getAttempts() + 1;
        $exhausted = $terminal || $attempts >= self::MAX_ATTEMPTS;

        $event->addData([
            'attempts' => $attempts,
            'status' => $exhausted ? IngestEvent::STATUS_FAILED : IngestEvent::STATUS_PENDING,
            'next_retry_at' => $exhausted ? null : $this->nextRetryAt($attempts),
            'last_error' => mb_substr($error, 0, 60000),
        ]);
        $this->eventResource->save($event);

        if ($exhausted) {
            $this->logger->error('Ingest event failed permanently', [
                'id' => $event->getId(),
                'domain' => $event->getDomain(),
                'error' => $error,
            ]);
        }
    }

    /**
     * Reset failed events back to pending (admin manual retry).
     *
     * @param int[] $ids
     */
    public function retry(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME),
            [
                'status' => IngestEvent::STATUS_PENDING,
                'attempts' => 0,
                'next_retry_at' => null,
            ],
            [
                'id IN (?)' => array_map('intval', $ids),
                'status = ?' => IngestEvent::STATUS_FAILED,
            ]
        );
    }

    /**
     * Decode a row payload and stamp the wire event_id from the row UUID.
     *
     * @return array<string, mixed>
     */
    public function decodePayload(IngestEvent $event): array
    {
        $decoded = $this->serializer->unserialize($event->getPayload());
        $payload = is_array($decoded) ? $decoded : [];
        $payload['event_id'] = $event->getEventUuid();

        return $payload;
    }

    private function nextRetryAt(int $attempts): string
    {
        $backoff = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
