<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1275: the reminder recipient falls back from quote.customer_email (set
 * only once payment info is entered) to the billing then shipping address
 * email, so guests who abandon at/before the shipping step are still reached.
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

    /**
     * @return array<string, string>
     */
    private function build(Quote $quote): array
    {
        $config = $this->createMock(Config::class);
        // No product fields configured -> the product path is not exercised.
        $config->method('getAbandonedFields')->willReturn([]);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        // Store context is decorative; a failure keeps the address valid and
        // avoids code-generated store dependencies in the unit sandbox.
        $storeManager->method('getStore')->willThrowException(new LocalizedException(__('no store')));

        $builder = new PayloadBuilder(
            $config,
            $storeManager,
            new ProductCollectionFactory(),
            new ImageHelperFactory(),
            $this->createMock(RestoreTokenManager::class),
            $this->createMock(Logger::class)
        );

        return $builder->build($quote, 1);
    }

    private function quote(?string $customerEmail, ?string $billingEmail, ?string $shippingEmail): Quote
    {
        // getCustomerEmail / *name are magic getters (addMethods); getStoreId
        // and the address getters are real methods (onlyMethods).
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCustomerEmail', 'getCustomerFirstname', 'getCustomerLastname'])
            ->onlyMethods(['getStoreId', 'getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('getCustomerEmail')->willReturn($customerEmail);
        $quote->method('getCustomerFirstname')->willReturn(null);
        $quote->method('getCustomerLastname')->willReturn(null);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getBillingAddress')->willReturn($this->address($billingEmail));
        $quote->method('getShippingAddress')->willReturn($this->address($shippingEmail));

        return $quote;
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
