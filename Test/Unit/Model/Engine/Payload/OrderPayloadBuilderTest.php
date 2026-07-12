<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;

class OrderPayloadBuilderTest extends TestCase
{
    private OrderPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new OrderPayloadBuilder(
            $this->createMock(ResourceConnection::class)
        );
    }

    /**
     * Contract v1.4.0 amount semantics: every money field is GROSS
     * (tax-inclusive) — line basis row_total_incl_tax, total_amount is the
     * grand total charged — and the sender invariant
     * Σ line_total + shipping ≈ total_amount holds.
     */
    public function testAmountsAreGrossAndSenderInvariantHolds(): void
    {
        $shippingInclTax = 5.90;
        $order = $this->order([
            // [sku, qty, rowTotalInclTax, discount]
            ['POC-CAT', 1, 22.99, 0.0],
            ['POC-DENT', 2, 44.51, 5.00],
        ], 22.99 + (44.51 - 5.00) + $shippingInclTax);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertSame(68.40, $payload['total_amount']);
        self::assertSame(5.0, $payload['discount_amount']);

        [$first, $second] = $payload['items'];
        self::assertSame(22.99, $first['unit_price']);
        self::assertSame(22.99, $first['line_total']);
        self::assertArrayNotHasKey('discount_amount', $first);
        // Gross unit price = gross (pre-discount) line total / qty.
        self::assertSame(22.255, $second['unit_price']);
        // Gross line total after line-level discount.
        self::assertSame(39.51, $second['line_total']);
        self::assertSame(5.0, $second['discount_amount']);

        $lineSum = array_sum(array_column($payload['items'], 'line_total'));
        self::assertEqualsWithDelta($payload['total_amount'], $lineSum + $shippingInclTax, 0.01);
    }

    public function testChildRowsOfConfigurablesAreSkipped(): void
    {
        $parent = $this->item('CONF-1', 1, 10.00, 0.0);
        $child = $this->item('CONF-1-S', 1, 10.00, 0.0);
        $child->method('getParentItemId')->willReturn(7);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$parent, $child]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertCount(1, $payload['items']);
        self::assertSame('CONF-1', $payload['items'][0]['sku']);
    }

    public function testTransientStateIsNotRepresentable(): void
    {
        $order = $this->order([], 1.0, Order::STATE_HOLDED);

        self::assertNull($this->builder->build($order));
    }

    /**
     * Symmetric mag-<entity_id> fallback (PRO-1280): an empty order-line SKU
     * must key on `mag-<product_id>` — the same token the catalog builder emits
     * for the same empty-SKU product (`mag-<entity_id>`, and
     * `sales_order_item.product_id` IS that catalog entity_id) — so catalog and
     * order rows join instead of diverging onto `mag-...` vs `""`.
     */
    public function testEmptySkuOrderLineFallsBackToMagProductId(): void
    {
        $item = $this->item('', 1, 10.00, 0.0);
        $item->method('getProductId')->willReturn(42);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$item]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertCount(1, $payload['items']);
        // Matches CatalogPayloadBuilder::sku()'s 'mag-' . (int)$product->getId().
        self::assertSame('mag-42', $payload['items'][0]['sku']);
    }

    /**
     * A whitespace-only SKU is also treated as empty and falls back, mirroring
     * the catalog builder's trim() before the emptiness check.
     */
    public function testWhitespaceOnlySkuOrderLineFallsBackToMagProductId(): void
    {
        $item = $this->item('   ', 1, 10.00, 0.0);
        $item->method('getProductId')->willReturn(7);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$item]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertSame('mag-7', $payload['items'][0]['sku']);
    }

    /**
     * @param array<int, array{0: string, 1: int, 2: float, 3: float}> $items
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderInterface
     */
    private function order(array $items, float $grandTotal, string $state = Order::STATE_COMPLETE)
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getCustomerEmail')->willReturn('Mari@Example.com ');
        $order->method('getCreatedAt')->willReturn('2026-07-01 10:00:00');
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getDiscountAmount')->willReturn(
            -array_sum(array_column($items, 3))
        );
        // entity_id 0 short-circuits the attribution side-table lookup.
        $order->method('getEntityId')->willReturn(0);

        if ($items !== []) {
            $order->method('getItems')->willReturn(array_map(
                fn (array $row) => $this->item(...$row),
                $items
            ));
        }

        return $order;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderItemInterface
     */
    private function item(string $sku, int $qty, float $rowTotalInclTax, float $discount)
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyOrdered')->willReturn((float)$qty);
        $item->method('getRowTotalInclTax')->willReturn($rowTotalInclTax);
        $item->method('getDiscountAmount')->willReturn($discount);

        return $item;
    }
}
