<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * PRO-1231: every catalog row (upsert and tombstone alike) carries the §3
 * identity tag `tags.product_id` — the platform parent product id §3b
 * removal matches on — while the `sku` keying stays untouched (PRO-1267).
 */
class CatalogPayloadBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../Support/Stub/ImageFactory.php';
    }

    public function testBuildEmitsParentProductIdTagWithoutChangingSkuKeying(): void
    {
        $builder = $this->createBuilder('17');

        $item = $builder->build($this->product(42, 'SHIRT-S'));

        self::assertSame('SHIRT-S', $item['sku'], 'sku keying must stay untouched (PRO-1267)');
        self::assertSame('42', $item['external_id']);
        self::assertSame('17', $item['tags']['product_id'], 'configurable child carries the PARENT id');
        self::assertSame('uncategorized', $item['tags']['category_path']);
    }

    public function testStandaloneProductTagsItsOwnEntityId(): void
    {
        $builder = $this->createBuilder('42');

        $item = $builder->build($this->product(42, 'SHIRT'));

        self::assertSame('42', $item['tags']['product_id']);
    }

    public function testTombstonePayloadKeepsTheProductIdTag(): void
    {
        $builder = $this->createBuilder('17');

        $item = $builder->buildTombstone($this->product(42, 'SHIRT-S'));

        self::assertFalse($item['in_stock']);
        self::assertSame('17', $item['tags']['product_id']);
    }

    /**
     * PRO-1269: product_url must be generated under frontend store emulation
     * so it is the clean storefront URL in any execution context (web/CLI/
     * cron) — never one embedding the invoking PHP entry script path. The
     * emulation is forced to the frontend area and always stopped.
     */
    public function testProductUrlIsBuiltUnderFrontendStoreEmulation(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::once())
            ->method('startEnvironmentEmulation')
            ->with(self::anything(), Area::AREA_FRONTEND, true);
        $emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $builder = $this->createBuilder('42', $emulation);

        $item = $builder->build($this->product(42, 'SHIRT'));

        self::assertSame('https://shop.example/shirt', $item['product_url']);
    }

    private function createBuilder(string $resolvedProductId, ?Emulation $emulation = null): CatalogPayloadBuilder
    {
        $parentResolver = $this->createMock(ParentProductResolver::class);
        $parentResolver->method('productIdOf')->with(42)->willReturn($resolvedProductId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([]);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItem')->willReturn($stockItem);

        return new CatalogPayloadBuilder(
            $storeManager,
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(CategoryRepositoryInterface::class),
            $stockRegistry,
            new ImageHelperFactory(),
            $this->createMock(LanguageResolver::class),
            $parentResolver,
            $emulation ?? $this->createMock(Emulation::class)
        );
    }

    private function product(int $id, string $sku): Product&MockObject
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn(19.99);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn('Shirt');
        $product->method('getProductUrl')->willReturn('https://shop.example/shirt');
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getAttributeSetId')->willReturn(4);
        $product->method('getCategoryIds')->willReturn([]);
        $product->method('getWebsiteIds')->willReturn([]);
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = '', $index = null) => ['short_description' => 'A fine shirt'][$key] ?? ''
        );

        return $product;
    }
}
