<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * The Log's "Send again" action (PRO-2454): queue a fresh attempt of a row
 * that failed.
 *
 * The failed row is never touched — it is the history of what went wrong,
 * and the new row is the audit record of the decision to try again (the
 * same rule the Woo plugin settled on). The record rides inside the new
 * row's stored payload under a reserved key, so it needs no column of its
 * own; both queues strip it back out at flush time, so nothing of it ever
 * reaches Smaily or the engine.
 */
class Resend
{
    /** The reserved payload key holding {of, by, at}. */
    public const PAYLOAD_KEY = '_resend';

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly IngestQueue $ingestQueue,
        private readonly Json $serializer
    ) {
    }

    /**
     * Queue one row again. False when the queue refused the insert.
     *
     * @param array<string, mixed> $row raw queue row, as QueueRowLoader reads it
     */
    public function resend(array $row, string $user): bool
    {
        $payload = $this->decode((string)($row['payload'] ?? ''));
        $payload[self::PAYLOAD_KEY] = [
            'of' => (int)$row['id'],
            'by' => $user,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $entityId = isset($row['entity_id']) ? (string)$row['entity_id'] : null;

        if ((string)$row['source'] === Collection::SOURCE_SMAILY) {
            return $this->eventQueue->enqueue(
                (string)$row['event_type'],
                $payload,
                $entityId,
                (int)($row['website_id'] ?? 0)
            );
        }

        return $this->ingestQueue->enqueue(
            (string)$row['domain'],
            $payload,
            $entityId,
            isset($row['store_id']) ? (int)$row['store_id'] : null
        );
    }

    /**
     * The resend record a stored payload carries, or null for a row that was
     * queued by the store itself.
     *
     * @return array{of: int, by: string, at: string}|null
     */
    public function recordOf(string $payload): ?array
    {
        $record = $this->decode($payload)[self::PAYLOAD_KEY] ?? null;
        if (!is_array($record)) {
            return null;
        }

        return [
            'of' => (int)($record['of'] ?? 0),
            'by' => (string)($record['by'] ?? ''),
            'at' => (string)($record['at'] ?? ''),
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = $this->serializer->unserialize($payload);

        return is_array($decoded) ? $decoded : [];
    }
}
