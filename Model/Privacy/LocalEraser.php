<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * The local half of a data-subject request (PRO-2452): the module's OWN
 * tables that can still hold a contact's address after the engine has been
 * asked to forget them — both delivery queues and the abandoned-cart side
 * table. Until this existed, those rows only cleared when the janitor's
 * retention window came round, 30 or 90 days later.
 *
 * Two outcomes, per Erkki's binding decision (2026-09-10), mirroring the
 * WooCommerce sibling's PRO-2383:
 *
 * - **A row that could still send is DELETED.** Not sending is the point of
 *   the erasure, so nothing survives that a flusher could still put on the
 *   wire — `pending` and `sending` alike.
 * - **A row that is over is ANONYMISED in place.** Deleting it would erase
 *   the merchant's own record that they messaged this person. The row keeps
 *   its id, type, status, attempts and timestamps and loses everything that
 *   points at the person: `entity_id` (which for a contact.sync or an
 *   automation row IS the address, and is the Log grid's Entity column),
 *   the queued payload, the payload as sent, the last response and the last
 *   error.
 *
 * Rows are found by decoding, never by searching the raw JSON text — see
 * PayloadAnonymizer. Erasure is idempotent by construction: an anonymised
 * row no longer carries the address it was matched on.
 */
class LocalEraser
{
    /**
     * The queue tables and the column each one calls its event type.
     */
    private const QUEUES = [
        EventResource::TABLE_NAME => 'event_type',
        IngestEventResource::TABLE_NAME => 'domain',
    ];

    private const ABANDONED_CART_TABLE = 'smaily_abandoned_cart';
    private const ABANDONED_CART_CONNECTION = 'checkout';

    /**
     * Statuses a row can still be sent from. Both queues use the same
     * vocabulary. Naming the sendable set beats negating `sent`: a status
     * added later has to be placed on one side or the other on purpose.
     */
    private const SENDABLE_STATUSES = [Event::STATUS_PENDING, Event::STATUS_SENDING];

    /**
     * Rows read per scan pass. The blobs are mediumtext, so the scan is
     * chunked rather than loaded whole.
     */
    private const SCAN_CHUNK = 200;

    /**
     * The blob columns a queue row can hide an address in.
     */
    private const MATCHED_COLUMNS = ['payload', 'sent_payload', 'last_response', 'last_error'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PayloadAnonymizer $anonymizer
    ) {
    }

    /**
     * Erase everything local for a contact.
     *
     * @return array<string, array{removed: int, anonymised: int}> keyed by table
     */
    public function erase(string $email): array
    {
        $email = strtolower(trim($email));

        $counts = [];
        foreach (array_keys(self::QUEUES) as $table) {
            $counts[$table] = $this->eraseQueue($table, $email);
        }
        $counts[self::ABANDONED_CART_TABLE] = [
            'removed' => $this->eraseAbandonedCart($email),
            'anonymised' => 0,
        ];

        return $counts;
    }

    /**
     * The same rows the erasure would touch, for the Art. 15 export: what
     * the store queued for this address and when, never the message body
     * (which is built from data Magento and Smaily already export).
     *
     * @return array<string, array<int, array<string, mixed>>> keyed by table
     */
    public function export(string $email): array
    {
        $email = strtolower(trim($email));

        $rows = [];
        foreach (self::QUEUES as $table => $typeColumn) {
            $rows[$table] = array_map(
                static fn (array $row): array => [
                    'type' => (string)$row[$typeColumn],
                    'status' => (string)$row['status'],
                    'created_at' => (string)$row['created_at'],
                ],
                $this->matchingRows($table, $email)
            );
        }
        $rows[self::ABANDONED_CART_TABLE] = $this->abandonedCartRows($email);

        return $rows;
    }

    /**
     * @return array{removed: int, anonymised: int}
     */
    private function eraseQueue(string $table, string $email): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $result = ['removed' => 0, 'anonymised' => 0];
        $sendableIds = [];
        foreach ($this->matchingRows($table, $email) as $row) {
            if (in_array((string)$row['status'], self::SENDABLE_STATUSES, true)) {
                $sendableIds[] = (int)$row['id'];
                continue;
            }
            $connection->update(
                $tableName,
                [
                    'entity_id' => PayloadAnonymizer::ERASED_PLACEHOLDER,
                    'payload' => (string)$this->anonymizer->anonymize((string)$row['payload']),
                    'sent_payload' => $this->anonymizer->anonymize($this->column($row, 'sent_payload')),
                    'last_response' => $this->anonymizer->anonymize($this->column($row, 'last_response')),
                    'last_error' => $this->column($row, 'last_error') === null
                        ? null
                        : PayloadAnonymizer::ERASED_PLACEHOLDER,
                ],
                ['id = ?' => (int)$row['id']]
            );
            $result['anonymised']++;
        }

        if ($sendableIds) {
            $result['removed'] = $connection->delete($tableName, ['id IN (?)' => $sendableIds]);
        }

        return $result;
    }

    private function eraseAbandonedCart(string $email): int
    {
        if ($email === '') {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection(self::ABANDONED_CART_CONNECTION);

        return $connection->delete($this->abandonedCartTable(), ['LOWER(email) = ?' => $email]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function abandonedCartRows(string $email): array
    {
        if ($email === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection(self::ABANDONED_CART_CONNECTION);
        $select = $connection->select()
            ->from($this->abandonedCartTable(), ['quote_id', 'store_id', 'status', 'created_at'])
            ->where('LOWER(email) = ?', $email)
            ->order('quote_id ASC');

        return $connection->fetchAll($select);
    }

    /**
     * Every row of a queue table that mentions this contact.
     *
     * The scan is a full table walk in id chunks: neither queue carries a
     * recipient column to index (a contact.sync row's entity_id happens to
     * be the address, an ingest row's is a product or customer id), and the
     * address inside the JSON cannot be matched in SQL without falling into
     * the escaping trap PayloadAnonymizer exists to avoid. An erasure is an
     * admin-triggered one-off where completeness beats speed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function matchingRows(string $table, string $email): array
    {
        if ($email === '') {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $matched = [];
        $lastId = 0;
        while (true) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($tableName)
                    ->where('id > ?', $lastId)
                    ->order('id ASC')
                    ->limit(self::SCAN_CHUNK)
            );
            if (!$rows) {
                return $matched;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                if ($this->rowMatches($row, $email)) {
                    $matched[] = $row;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatches(array $row, string $email): bool
    {
        if (strtolower(trim((string)($row['entity_id'] ?? ''))) === $email) {
            return true;
        }
        foreach (self::MATCHED_COLUMNS as $column) {
            if ($this->anonymizer->matches($this->column($row, $column), $email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function column(array $row, string $name): ?string
    {
        $value = $row[$name] ?? null;

        return $value === null ? null : (string)$value;
    }

    private function abandonedCartTable(): string
    {
        return $this->resourceConnection->getTableName(
            self::ABANDONED_CART_TABLE,
            self::ABANDONED_CART_CONNECTION
        );
    }
}
