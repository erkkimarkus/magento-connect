<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * State access for smaily_abandoned_cart (side table on the checkout
 * connection; the core quote table is never altered).
 *
 * Statuses: open (tracked), mailed (automation fired), completed (order
 * placed), expired (aged out unmailed).
 */
class StateManager
{
    public const STATUS_OPEN = 'open';
    public const STATUS_MAILED = 'mailed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';

    private const TABLE_NAME = 'smaily_abandoned_cart';
    private const CONNECTION = 'checkout';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Record that an order was placed for a quote — the cart is no longer
     * abandoned and must never be mailed.
     */
    public function markCompleted(int $quoteId): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'status' => self::STATUS_COMPLETED,
            ],
            ['status']
        );
    }

    /**
     * Record that the abandoned cart automation fired for a quote.
     */
    public function markMailed(int $quoteId, int $storeId, ?string $email): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'status' => self::STATUS_MAILED,
                'abandoned_at' => $this->dateTime->gmtDate(),
                'mail_sent_at' => $this->dateTime->gmtDate(),
            ],
            ['status', 'abandoned_at', 'mail_sent_at']
        );
    }

    /**
     * Store the checkout newsletter opt-in choice for a quote.
     */
    public function setNewsletterOptin(int $quoteId, int $storeId, ?string $email, bool $optedIn): void
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'quote_id' => $quoteId,
                'store_id' => $storeId,
                'email' => $email,
                'status' => self::STATUS_OPEN,
                'newsletter_optin' => $optedIn ? 1 : 0,
            ],
            ['email', 'newsletter_optin']
        );
    }

    /**
     * Whether the customer ticked the checkout newsletter checkbox for a quote.
     */
    public function isOptedIn(int $quoteId): bool
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['newsletter_optin'])
            ->where('quote_id = ?', $quoteId);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Quote IDs that must not be (re)mailed: already mailed, completed or expired.
     *
     * @param int[] $quoteIds
     * @return int[]
     */
    public function filterAlreadyHandled(array $quoteIds): array
    {
        if (!$quoteIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['quote_id'])
            ->where('quote_id IN (?)', array_map('intval', $quoteIds))
            ->where('status IN (?)', [self::STATUS_MAILED, self::STATUS_COMPLETED, self::STATUS_EXPIRED]);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Delete every tracked cart for a contact (Art. 17 erasure). The address
     * is expected already lowercased.
     */
    public function deleteForEmail(string $email): int
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);

        return $connection->delete($this->table(), ['LOWER(email) = ?' => $email]);
    }

    /**
     * The tracked carts of a contact, for the Art. 15 export. The address is
     * expected already lowercased.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsForEmail(string $email): array
    {
        $connection = $this->resourceConnection->getConnection(self::CONNECTION);
        $select = $connection->select()
            ->from($this->table(), ['quote_id', 'store_id', 'status', 'created_at'])
            ->where('LOWER(email) = ?', $email)
            ->order('quote_id ASC');

        return $connection->fetchAll($select);
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE_NAME, self::CONNECTION);
    }
}
