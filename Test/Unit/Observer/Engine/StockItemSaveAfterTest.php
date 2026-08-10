<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Observer\Engine\StockItemSaveAfter;

/**
 * PRO-1951: a stock change that bypasses product save must still reach
 * catalog ingest — but a product save, which writes its stock item along the
 * way without moving the stock, must not queue the row twice.
 */
class StockItemSaveAfterTest extends TestCase
{
    private Settings&MockObject $settings;
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testIsInStockChangeEnqueuesTheProduct(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueProductId')->with(42);

        $this->observer()->execute($this->eventFor($this->stockItem(42, ['is_in_stock' => 0], ['is_in_stock' => 1])));
    }

    public function testQtyChangeEnqueuesTheProduct(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueProductId')->with(42);

        $this->observer()->execute($this->eventFor($this->stockItem(42, ['qty' => 5.0], ['qty' => 4.0])));
    }

    public function testASaveThatMovedNeitherQtyNorStatusIsIgnored(): void
    {
        $this->catalogIngest->expects(self::never())->method('enqueueProductId');

        $item = $this->stockItem(42, ['qty' => 5.0, 'is_in_stock' => 1], ['qty' => 5.0, 'is_in_stock' => 1]);
        $this->observer()->execute($this->eventFor($item));
    }

    public function testABrandNewStockItemAlwaysCounts(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueProductId')->with(42);

        $this->observer()->execute($this->eventFor($this->stockItem(42, ['qty' => 5.0], null)));
    }

    public function testDisconnectedEngineIsANoOp(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->catalogIngest->expects(self::never())->method('enqueueProductId');

        (new StockItemSaveAfter($settings, $this->catalogIngest))
            ->execute($this->eventFor($this->stockItem(42, ['is_in_stock' => 0], ['is_in_stock' => 1])));
    }

    public function testAnEventWithoutAStockItemIsANoOp(): void
    {
        $this->catalogIngest->expects(self::never())->method('enqueueProductId');

        $this->observer()->execute($this->eventFor(null));
    }

    private function observer(): StockItemSaveAfter
    {
        return new StockItemSaveAfter($this->settings, $this->catalogIngest);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $origData null = never loaded (a new row)
     */
    private function stockItem(int $productId, array $data, ?array $origData): StockItem&MockObject
    {
        if ($origData !== null) {
            $origData += ['item_id' => 1]; // a loaded row always has its own id
        }
        $item = $this->createMock(StockItem::class);
        $item->method('getProductId')->willReturn($productId);
        $item->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null) => $origData === null ? null : ($origData[$key] ?? null)
        );
        $item->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $key): bool => ($origData[$key] ?? null) !== ($data[$key] ?? null)
        );

        return $item;
    }

    private function eventFor(?StockItem $item): Observer
    {
        $event = new Event(['item' => $item]);

        return new Observer(['event' => $event]);
    }
}
