<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;

/**
 * Order -> WireOrder (contract §5).
 *
 * Status maps onto the closed engine enum; orders in transient states
 * (payment review, pending payment, hold) are never sent — build() returns
 * null for them. All money fields are GROSS (tax-inclusive) order-currency
 * values per contract v1.4.0 amount semantics: row_total_incl_tax for
 * lines, grand_total for total_amount; items carry pre-discount unit
 * prices and post-discount line totals. Attribution fields come
 * from the smaily_order_attribution side table. NB: the order wire key is
 * smaily_rec_ctx while browse events use smaily_ctx — never unify them.
 */
class OrderPayloadBuilder
{
    private const STATUS_MAP = [
        Order::STATE_COMPLETE => 'completed',
        Order::STATE_NEW => 'processing',
        Order::STATE_PROCESSING => 'processing',
        Order::STATE_CANCELED => 'cancelled',
        Order::STATE_CLOSED => 'refunded',
    ];

    private const ATTRIBUTION_TABLE = 'smaily_order_attribution';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the order state is not
     *     representable in the engine enum (skip, do not queue)
     */
    public function build(OrderInterface $order): ?array
    {
        $status = self::STATUS_MAP[(string)$order->getState()] ?? null;
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($status === null || $email === '') {
            return null;
        }

        $orderedAt = strtotime((string)$order->getCreatedAt()) ?: time();

        $item = [
            'external_order_id' => (string)$order->getIncrementId(),
            'customer_email' => $email,
            'ordered_at' => gmdate('Y-m-d\TH:i:s\Z', $orderedAt),
            'total_amount' => round((float)$order->getGrandTotal(), 4),
            'currency' => (string)$order->getOrderCurrencyCode() ?: 'EUR',
            'status' => $status,
            'items' => $this->items($order),
        ];

        $discount = abs((float)$order->getDiscountAmount());
        if ($discount > 0) {
            $item['discount_amount'] = round($discount, 4);
        }

        return array_merge($item, $this->attribution((int)$order->getEntityId()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $orderItem) {
            // Product rows only: children of configurables carry the price on
            // the parent row, so skip items with a parent to avoid doubling.
            if ($orderItem->getParentItemId()) {
                continue;
            }

            $qty = (float)$orderItem->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }

            $rowTotalInclTax = (float)($orderItem->getRowTotalInclTax() ?? $orderItem->getRowTotal());
            $itemDiscount = abs((float)$orderItem->getDiscountAmount());

            $row = [
                'sku' => $this->sku($orderItem),
                // Integer when whole (contract examples use ints); a genuinely
                // fractional qty (e.g. 1.5 kg) wires as a float.
                'qty' => $qty == (int)$qty ? (int)$qty : $qty,
                'unit_price' => round($rowTotalInclTax / $qty, 4),
                'line_total' => round(max(0, $rowTotalInclTax - $itemDiscount), 4),
            ];
            if ($itemDiscount > 0) {
                $row['discount_amount'] = round($itemDiscount, 4);
            }
            $items[] = $row;
        }

        return $items;
    }

    /**
     * The order-line identity key, symmetric with the catalog builder.
     *
     * Magento's catalog `sku` field IS the platform-canonical key (mandatory +
     * store-unique), so it is normally emitted verbatim. When the SKU field is
     * empty — pathological, but possible — the catalog builder keys the row on
     * `mag-<entity_id>` (CatalogPayloadBuilder::sku). The order item's
     * `product_id` (`sales_order_item.product_id`) IS that same catalog
     * `entity_id`, so an empty order-line SKU must key the SAME way, or the
     * catalog row (`mag-<entity_id>`) and the order line (`""`) diverge onto
     * different keys and never join — breaking attribution + cadence, and an
     * empty `sku` is a cross-product collision magnet (contract §3, "Same key
     * from every path" / catalog↔order-line fallback symmetry, PRO-1280).
     */
    private function sku(OrderItemInterface $orderItem): string
    {
        $sku = trim((string)$orderItem->getSku());

        return $sku !== '' ? $sku : 'mag-' . (int)$orderItem->getProductId();
    }

    /**
     * @return array<string, string>
     */
    private function attribution(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::ATTRIBUTION_TABLE, 'sales'),
                ['rec_id', 'visitor_token', 'rec_ctx', 'anon_session_id']
            )
            ->where('order_id = ?', $orderId);
        $row = $connection->fetchRow($select);
        if (!is_array($row)) {
            return [];
        }

        $attribution = [];
        if (!empty($row['rec_id'])) {
            $attribution['smaily_rec_id'] = (string)$row['rec_id'];
        }
        if (!empty($row['visitor_token'])) {
            $attribution['smaily_visitor_token'] = (string)$row['visitor_token'];
        }
        if (!empty($row['rec_ctx'])) {
            $attribution['smaily_rec_ctx'] = (string)$row['rec_ctx'];
        }
        if (!empty($row['anon_session_id'])) {
            $attribution['session_id'] = (string)$row['anon_session_id'];
        }

        return $attribution;
    }
}
