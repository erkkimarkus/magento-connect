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
use Smaily\Connect\Plugin\Engine\SourceItemsSaveAfter;

/**
 * PRO-1951: MSI writes stock without a legacy model save, so the source-item
 * save is the only hook that sees a shipment deduction or a
 * POST /V1/inventory/source-items. The plugin never names an MSI type — MSI
 * is removable — so the sku is read by duck typing.
 */
class SourceItemsSaveAfterTest extends TestCase
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

        (new SourceItemsSaveAfter($settings, $this->catalogIngest))
            ->afterExecute(new \stdClass(), null, [$this->sourceItem('TENT-1')]);
    }

    public function testTheSubjectResultIsPassedThrough(): void
    {
        self::assertSame('kept', $this->plugin()->afterExecute(new \stdClass(), 'kept', []));
    }

    private function plugin(): SourceItemsSaveAfter
    {
        return new SourceItemsSaveAfter($this->settings, $this->catalogIngest);
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
