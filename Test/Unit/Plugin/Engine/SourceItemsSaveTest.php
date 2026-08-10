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
use Smaily\Connect\Plugin\Engine\SourceItemsSave;

/**
 * PRO-1951: MSI writes the legacy stock row with direct SQL, so this plugin is
 * the only hook that sees a POST /V1/inventory/source-items or a Sources-grid
 * save. It never names an MSI type — MSI is removable — so the sku is read by
 * duck typing off the saved source items.
 */
class SourceItemsSaveTest extends TestCase
{
    private CatalogIngest&MockObject $catalogIngest;

    protected function setUp(): void
    {
        $this->catalogIngest = $this->createMock(CatalogIngest::class);
    }

    public function testEverySavedSkuIsQueuedOnce(): void
    {
        $queued = [];
        $this->catalogIngest->method('enqueueSku')->willReturnCallback(
            static function (string $sku) use (&$queued): bool {
                $queued[] = $sku;

                return true;
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

    public function testAnUnrecognisedPayloadQueuesNothing(): void
    {
        $this->catalogIngest->expects(self::never())->method('enqueueSku');

        $this->plugin()->afterExecute(new \stdClass(), null, 'nonsense');
    }

    public function testTheSubjectResultIsPassedThrough(): void
    {
        self::assertSame('kept', $this->plugin()->afterExecute(new \stdClass(), 'kept', []));
    }

    private function plugin(): SourceItemsSave
    {
        return new SourceItemsSave($this->catalogIngest);
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
