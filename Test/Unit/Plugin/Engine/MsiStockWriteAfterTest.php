<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\Engine;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Plugin\Engine\MsiStockWriteAfter;

/**
 * PRO-1951: MSI writes stock without a legacy model save, so this plugin is
 * the only hook that sees a shipment deduction, a credit-memo return to stock
 * or a POST /V1/inventory/source-items. It never names an MSI type — MSI is
 * removable — so the sku is read by duck typing off either shape: the saved
 * source items, or a source deduction request's items.
 */
class MsiStockWriteAfterTest extends TestCase
{
    private Settings&MockObject $settings;
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testEverySavedSkuIsQueuedOnce(): void
    {
        $queued = [];
        $this->catalogIngest->method('enqueueSku')->willReturnCallback(
            static function (string $sku) use (&$queued): void {
                $queued[] = $sku;
            }
        );

        $this->plugin()->afterExecute(
            new \stdClass(),
            null,
            [$this->sourceItem('TENT-1'), $this->sourceItem('MUG-2')]
        );

        self::assertSame(['TENT-1', 'MUG-2'], $queued);
    }

    public function testTheSameSkuOnTwoSourcesCollapsesIntoOneRow(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueSku')->with('TENT-1');

        $this->plugin()->afterExecute(
            new \stdClass(),
            null,
            [$this->sourceItem('TENT-1'), $this->sourceItem('TENT-1')]
        );
    }

    public function testANumericSkuStaysAString(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueSku')->with('12345');

        $this->plugin()->afterExecute(new \stdClass(), null, [$this->sourceItem('12345')]);
    }

    public function testDisconnectedEngineIsANoOp(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->catalogIngest->expects(self::never())->method('enqueueSku');

        (new MsiStockWriteAfter($settings, $this->catalogIngest))
            ->afterExecute(new \stdClass(), null, [$this->sourceItem('TENT-1')]);
    }

    public function testASourceDeductionRequestIsReadThroughItsItems(): void
    {
        $this->catalogIngest->expects(self::once())->method('enqueueSku')->with('TENT-1');

        $this->plugin()->afterExecute(new \stdClass(), null, $this->deductionRequest('TENT-1'));
    }

    public function testAnUnrecognisedPayloadQueuesNothing(): void
    {
        $this->catalogIngest->expects(self::never())->method('enqueueSku');

        $this->plugin()->afterExecute(new \stdClass(), null, 'nonsense');
    }

    public function testTheSubjectResultIsPassedThrough(): void
    {
        self::assertSame('kept', $this->plugin()->afterExecute(new \stdClass(), 'kept', []));
    }

    private function plugin(): MsiStockWriteAfter
    {
        return new MsiStockWriteAfter($this->settings, $this->catalogIngest);
    }

    private function deductionRequest(string $sku): object
    {
        $items = [$this->sourceItem($sku)];

        return new class ($items) {
            /** @param object[] $items */
            public function __construct(private readonly array $items)
            {
            }

            /** @return object[] */
            public function getItems(): array
            {
                return $this->items;
            }
        };
    }

    private function sourceItem(string $sku): object
    {
        return new class ($sku) {
            public function __construct(private readonly string $sku)
            {
            }

            public function getSku(): string
            {
                return $this->sku;
            }
        };
    }
}
