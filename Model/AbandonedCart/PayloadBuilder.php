<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\AbandonedFields;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Builds the abandoned cart automation address payload.
 *
 * Field names are the cross-platform contract shared with the WooCommerce
 * and Shopify plugins: numbered slots product_name_1..10, product_sku_N,
 * product_quantity_N, product_price_N (incl. tax), product_base_price_N,
 * product_description_N, product_image_url_N, plus over_10_products,
 * is_abandoned_cart and abandoned_cart_url.
 */
class PayloadBuilder
{
    private const MAX_PRODUCTS = 10;

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ImageHelperFactory $imageHelperFactory,
        private readonly RestoreTokenManager $restoreTokenManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function build(Quote $quote, int $websiteId): array
    {
        $address = [
            'email' => $this->resolveEmail($quote),
            'is_abandoned_cart' => 'true',
        ];

        $firstname = trim((string)$quote->getCustomerFirstname());
        $lastname = trim((string)$quote->getCustomerLastname());
        if ($firstname !== '') {
            $address['first_name'] = $firstname;
        }
        if ($lastname !== '') {
            $address['last_name'] = $lastname;
        }

        try {
            $store = $this->storeManager->getStore((int)$quote->getStoreId());
            if ($store instanceof \Magento\Store\Model\Store) {
                // Legacy Magento templates use {{store}} as the store NAME —
                // kept for upgrade continuity; store_url serves templates
                // shared with the Woo/Shopify plugins (which send a URL).
                $address['store'] = (string)$store->getName();
                $address['store_url'] = (string)$store->getBaseUrl();
                // getGroup(), not the magic getStoreGroup() (silently null).
                $group = $store->getGroup();
                $address['store_group'] = $group ? (string)$group->getName() : '';
                $address['store_website'] = (string)$store->getWebsite()->getName();
                // A tokenized recovery link that restores this exact quote.
                $address['abandoned_cart_url'] = $store->getUrl('smaily/cart/restore', [
                    'id' => (int)$quote->getId(),
                    'token' => $this->restoreTokenManager->generate((int)$quote->getId()),
                ]);
            }
        } catch (LocalizedException) {
            // Store context is decorative; the address stays valid without it.
        }

        return array_merge($address, $this->productFields($quote, $websiteId));
    }

    /**
     * Resolves the recipient email with the same fallback order as the cron's
     * quote selection: quote.customer_email (set once payment info is entered),
     * then the billing address email, then the shipping address email. Guests
     * who abandon at/before the shipping step have an empty customer_email and
     * carry their email only on the quote address (PRO-1275).
     *
     * @param Quote $quote
     */
    private function resolveEmail(Quote $quote): string
    {
        $email = trim((string)$quote->getCustomerEmail());

        if ($email === '') {
            $billing = $quote->getBillingAddress();
            $email = $billing ? trim((string)$billing->getEmail()) : '';
        }

        if ($email === '') {
            $shipping = $quote->getShippingAddress();
            $email = $shipping ? trim((string)$shipping->getEmail()) : '';
        }

        return strtolower($email);
    }

    /**
     * @return array<string, string>
     */
    private function productFields(Quote $quote, int $websiteId): array
    {
        $enabled = array_values(array_intersect(
            AbandonedFields::SUPPORTED_FIELDS,
            $this->config->getAbandonedFields($websiteId)
        ));
        if (!$enabled) {
            return [];
        }

        $items = $quote->getAllVisibleItems();
        $products = $this->loadProducts($items, (int)$quote->getStoreId());

        $fields = [];
        $slot = 0;
        foreach ($items as $item) {
            if ($slot >= self::MAX_PRODUCTS) {
                $fields['over_10_products'] = 'true';
                break;
            }
            $slot++;
            $product = $products[(int)$item->getProduct()->getId()] ?? null;

            foreach ($enabled as $field) {
                $value = $this->resolveField($field, $item, $product);
                if ($value !== null && $value !== '') {
                    $fields[sprintf('product_%s_%d', $this->fieldKey($field), $slot)] = $value;
                }
            }
        }

        return $fields;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    private function resolveField(string $field, $item, ?Product $product): ?string
    {
        switch ($field) {
            case AbandonedFields::FIELD_NAME:
                return (string)$item->getName();
            case AbandonedFields::FIELD_SKU:
                return (string)$item->getSku();
            case AbandonedFields::FIELD_QUANTITY:
                return (string)(float)$item->getQty();
            case AbandonedFields::FIELD_PRICE:
                $price = (float)($item->getPriceInclTax() ?: $item->getPrice());

                return number_format($price, 2, '.', '');
            case AbandonedFields::FIELD_BASE_PRICE:
                if ($product === null) {
                    return null;
                }
                $regular = (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

                return $regular > 0 ? number_format($regular, 2, '.', '') : null;
            case AbandonedFields::FIELD_DESCRIPTION:
                if ($product === null) {
                    return null;
                }
                $description = (string)($product->getData('short_description')
                    ?: $product->getData('description'));
                $description = trim(strip_tags($description));

                return $description !== '' ? $description : null;
            case AbandonedFields::FIELD_IMAGE_URL:
                return $product === null ? null : $this->imageUrl($product);
            default:
                return null;
        }
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item[] $items
     * @return array<int, Product>
     */
    private function loadProducts(array $items, int $storeId): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productIds[] = (int)$item->getProduct()->getId();
        }
        if (!$productIds) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'short_description', 'description', 'thumbnail', 'image', 'price'])
            ->addIdFilter($productIds)
            ->addPriceData();

        $products = [];
        foreach ($collection->getItems() as $product) {
            if ($product instanceof Product) {
                $products[(int)$product->getId()] = $product;
            }
        }

        return $products;
    }

    private function imageUrl(Product $product): ?string
    {
        try {
            $url = $this->imageHelperFactory->create()
                ->init($product, 'product_page_image_small')
                ->resize(346)
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable $exception) {
            $this->logger->debug('Abandoned cart image URL failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function fieldKey(string $field): string
    {
        // Config keys map 1:1 onto payload keys ("name" -> product_name_N).
        return $field;
    }
}
