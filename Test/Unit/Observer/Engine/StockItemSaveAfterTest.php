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
 * catalog ingest.
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

    public function testAStockItemSaveEnqueuesItsProduct(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueProductId')->with(42);

        $this->observer()->execute($this->eventFor($this->stockItem(42)));
    }

    public function testDisconnectedEngineIsANoOp(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->catalogIngest->expects(self::never())->method('enqueueProductId');

        (new StockItemSaveAfter($settings, $this->catalogIngest))
            ->execute($this->eventFor($this->stockItem(42)));
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

    private function stockItem(int $productId): StockItem&MockObject
    {
        $item = $this->createMock(StockItem::class);
        $item->method('getProductId')->willReturn($productId);

        return $item;
    }

    private function eventFor(?StockItem $item): Observer
    {
        $event = new Event(['item' => $item]);

        return new Observer(['event' => $event]);
    }
}
