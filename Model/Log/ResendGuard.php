<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Decides whether one log row may be sent again (PRO-2454) — the single
 * server-owned answer, used by the Log's read model (hide the action and
 * explain why), by the "Send again" route (refuse the request) and by the
 * mass retry (skip the row and report it skipped). Shaped after the Woo
 * plugin's TransactionalRetryGuard: a reason code, with the merchant
 * sentence for it owned right beside it.
 *
 * The three refusals are the three ways sending again would be wrong: the
 * reminder was withdrawn on purpose, a later message of the same kind
 * already reached the contact, or the contact's data is gone. Everything
 * else is safe — an engine ingest row is an idempotent upsert, and a
 * contact sync or an identity merge repeats a state, not a message.
 */
class ResendGuard
{
    /** Withdrawn because the shopper bought (EventQueue::CANCELLED_RESPONSE). */
    public const REASON_WITHDRAWN = 'withdrawn';

    /** A later message of the same kind already reached this contact. */
    public const REASON_SUPERSEDED = 'superseded';

    /** An Art. 17 erasure took the recipient (Erasure::PLACEHOLDER). */
    public const REASON_ERASED = 'erased';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $serializer,
        private readonly QueueRowLoader $rowLoader
    ) {
    }

    /**
     * Why this row may not be sent again, or '' when it may.
     *
     * The row is either a raw queue row or a grid row: the first carries the
     * stored `last_response` marker, the second the status derived from it,
     * and both name the same withdrawn row.
     *
     * @param array<string, mixed> $row
     */
    public function refusalReason(string $source, int $id, array $row): string
    {
        if ((string)($row['status'] ?? '') === Collection::STATUS_WITHDRAWN
            || (string)($row['last_response'] ?? '') === EventQueue::CANCELLED_RESPONSE
        ) {
            return self::REASON_WITHDRAWN;
        }

        $entityId = (string)($row['entity_id'] ?? '');
        if ($entityId === Erasure::PLACEHOLDER) {
            return self::REASON_ERASED;
        }

        if ($source !== Collection::SOURCE_SMAILY
            || (string)($row['type'] ?? '') !== EventType::AUTOMATION_TRIGGER
            || $entityId === ''
        ) {
            return '';
        }

        return $this->isSuperseded($id, $entityId) ? self::REASON_SUPERSEDED : '';
    }

    /**
     * The refusals among the failed rows of one queue — the mass retry's
     * question: which of the selected rows must it skip?
     *
     * @param int[] $ids
     * @return array<int, string> reason by row id, refused rows only
     */
    public function refusalReasons(string $source, array $ids): array
    {
        $refused = [];
        foreach ($this->rowLoader->loadFailed($source, $ids) as $id => $row) {
            $reason = $this->refusalReason($source, $id, $row);
            if ($reason !== '') {
                $refused[$id] = $reason;
            }
        }

        return $refused;
    }

    /**
     * The merchant-readable sentence for a refusal.
     */
    public function message(string $reason): Phrase
    {
        if ($reason === self::REASON_WITHDRAWN) {
            return __(
                'This reminder was withdrawn because the shopper completed the purchase; '
                . 'it cannot be re-sent.'
            );
        }

        if ($reason === self::REASON_SUPERSEDED) {
            return __(
                'A later message of this kind already reached this contact; '
                . 'sending again would deliver it twice.'
            );
        }

        return __('This contact\'s data was erased; the event cannot be re-sent.');
    }

    /**
     * Whether a later automation row of the SAME trigger already reached this
     * contact. One query asks both halves: the row's own trigger — it lives in
     * the payload, which the grid never carries — and the delivered rows that
     * could have superseded it. A withdrawn row supersedes nothing: it was
     * never sent.
     */
    private function isSuperseded(int $id, string $entityId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
                ['id', 'payload']
            )
            ->where('entity_id = ?', $entityId)
            ->where('event_type = ?', EventType::AUTOMATION_TRIGGER)
            // Spelled out rather than bound: one ? per condition is all the
            // query builder binds, and this clause needs four values.
            ->where(sprintf(
                'id = %d OR (id > %d AND status = %s AND (last_response IS NULL OR last_response != %s))',
                $id,
                $id,
                $connection->quote(Event::STATUS_SENT),
                $connection->quote(EventQueue::CANCELLED_RESPONSE)
            ));

        $payloads = $connection->fetchPairs($select);
        $trigger = $this->triggerOf((string)($payloads[$id] ?? ''));
        unset($payloads[$id]);
        if ($trigger === '') {
            return false;
        }

        foreach ($payloads as $payload) {
            if ($this->triggerOf((string)$payload) === $trigger) {
                return true;
            }
        }

        return false;
    }

    /**
     * The automation trigger a stored payload was queued for.
     */
    private function triggerOf(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $decoded = $this->serializer->unserialize($payload);

        return is_array($decoded) ? (string)($decoded['trigger_type'] ?? '') : '';
    }
}
