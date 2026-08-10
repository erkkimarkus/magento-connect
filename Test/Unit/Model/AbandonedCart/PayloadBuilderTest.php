<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1275: the reminder recipient falls back from quote.customer_email (set
 * only once payment info is entered) to the billing then shipping address
 * email, so guests who abandon at/before the shipping step are still reached.
 *
 * PRO-1760: product details are always sent (no merchant selection), and all
 * ten slots are written on every send so a smaller cart clears the previous
 * one from the contact.
 */
class PayloadBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ImageFactory.php';
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';
    }

    public function testCustomerEmailIsUsedWhenPresent(): void
    {
        $quote = $this->quote(' Mari@Example.com ', 'billing@example.com', 'shipping@example.com');

        self::assertSame('mari@example.com', $this->build($quote)['email']);
    }

    public function testFallsBackToBillingAddressEmailWhenCustomerEmailIsNull(): void
    {
        $quote = $this->quote(null, ' Billing@Example.com ', 'shipping@example.com');

        self::assertSame('billing@example.com', $this->build($quote)['email']);
    }

    public function testFallsBackToShippingAddressEmailWhenNoBillingEmail(): void
    {
        $quote = $this->quote('', '', ' Shipping@Example.com ');

        self::assertSame('shipping@example.com', $this->build($quote)['email']);
    }

    public function testEmailIsEmptyWhenNoSourceCarriesOne(): void
    {
        $quote = $this->quote(null, '', null);

        self::assertSame('', $this->build($quote)['email']);
    }

    public function testEveryProductSlotIsWrittenEvenForAnEmptyCart(): void
    {
        $address = $this->build($this->quote('mari@example.com', null, null));

        foreach (['name', 'description', 'image_url', 'sku', 'quantity', 'price', 'base_price'] as $field) {
            for ($slot = 1; $slot <= 10; $slot++) {
                self::assertArrayHasKey(sprintf('product_%s_%d', $field, $slot), $address);
                self::assertSame('', $address[sprintf('product_%s_%d', $field, $slot)]);
            }
        }
    }

    /**
     * A second, smaller cart must clear the first one from the contact: the
     * slots the new cart does not use are sent empty, not omitted.
     */
    public function testUnusedSlotsAreSentEmptyAlongsideTheCartsOwnProducts(): void
    {
        $quote = $this->quote('mari@example.com', null, null, [
            $this->item('Tent', 'TENT-1', 2.0, 149.0),
            $this->item('Mug', 'MUG-1', 1.0, 9.5),
        ]);

        $address = $this->build($quote);

        self::assertSame('Tent', $address['product_name_1']);
        self::assertSame('TENT-1', $address['product_sku_1']);
        self::assertSame('2', $address['product_quantity_1']);
        self::assertSame('149.00', $address['product_price_1']);
        self::assertSame('Mug', $address['product_name_2']);
        self::assertSame('', $address['product_name_3']);
        self::assertSame('', $address['product_sku_10']);
        // Nothing resolved the product, so those fields keep their prefill.
        self::assertSame('', $address['product_description_1']);
        self::assertArrayNotHasKey('over_10_products', $address);
    }

    /**
     * @return array<string, string>
     */
    private function build(Quote $quote): array
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        // Store context is decorative; a failure keeps the address valid and
        // avoids code-generated store dependencies in the unit sandbox.
        $storeManager->method('getStore')->willThrowException(new LocalizedException(__('no store')));

        $builder = new PayloadBuilder(
            $storeManager,
            $this->productCollectionFactory(),
            new ImageHelperFactory(),
            $this->createMock(RestoreTokenManager::class),
            $this->createMock(Logger::class)
        );

        return $builder->build($quote);
    }

    /**
     * A collection that finds no product: the item-level fields still resolve,
     * the product-level ones keep their empty prefill.
     */
    private function productCollectionFactory(): ProductCollectionFactory
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addPriceData')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * @param Item[] $items
     */
    private function quote(
        ?string $customerEmail,
        ?string $billingEmail,
        ?string $shippingEmail,
        array $items = []
    ): Quote {
        // getCustomerEmail / *name are magic getters (addMethods); getStoreId,
        // getAllVisibleItems and the address getters are real methods.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerEmail', 'getCustomerFirstname', 'getCustomerLastname'])
            ->onlyMethods(['getStoreId', 'getBillingAddress', 'getShippingAddress', 'getAllVisibleItems'])
            ->getMock();
        $quote->method('getCustomerEmail')->willReturn($customerEmail);
        $quote->method('getCustomerFirstname')->willReturn(null);
        $quote->method('getCustomerLastname')->willReturn(null);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getBillingAddress')->willReturn($this->address($billingEmail));
        $quote->method('getShippingAddress')->willReturn($this->address($shippingEmail));
        $quote->method('getAllVisibleItems')->willReturn($items);

        return $quote;
    }

    private function item(string $name, string $sku, float $qty, float $price): Item
    {
        // getPriceInclTax is a magic data getter; the rest are real methods.
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPriceInclTax'])
            ->onlyMethods(['getName', 'getSku', 'getQty', 'getPrice', 'getProduct'])
            ->getMock();
        $item->method('getName')->willReturn($name);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getPrice')->willReturn($price);
        $item->method('getPriceInclTax')->willReturn($price);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(7);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }

    private function address(?string $email): ?Address
    {
        if ($email === null) {
            return null;
        }
        $address = $this->createMock(Address::class);
        $address->method('getEmail')->willReturn($email);

        return $address;
    }
}
