<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\ImageFactory as ImageHelperFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * Product -> WireProduct (contract §3).
 *
 * One row per sellable catalog entry: visible products (parents included)
 * — configurable children resolve to their visible parent for
 * recommendations, matching the RSS feed behavior. Multilingual stores get
 * {lang: value} maps for name/description/product_url built from per-store
 * attribute values; the default store view is the canonical fallback.
 */
class CatalogPayloadBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ImageHelperFactory $imageHelperFactory,
        private readonly LanguageResolver $languageResolver,
        private readonly ParentProductResolver $parentProductResolver,
        private readonly Emulation $emulation
    ) {
    }

    /**
     * Whether the product belongs in the engine catalog at all.
     */
    public function isIngestible(Product $product): bool
    {
        return (int)$product->getStatus() === Status::STATUS_ENABLED
            && in_array((int)$product->getVisibility(), [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_BOTH,
                Visibility::VISIBILITY_IN_SEARCH,
            ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product): array
    {
        $languageValues = $this->languageValues($product);

        $item = [
            'sku' => $this->sku($product),
            'name' => $languageValues['name'],
            'category_path' => $this->categoryPath($product),
            'price' => round((float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(), 4),
            'in_stock' => $this->isInStock($product),
            'product_url' => $languageValues['product_url'],
            'external_id' => (string)$product->getId(),
            // The engine's primary structural exclusion signal (§3): gift
            // card types are excluded engine-side from this, never by us.
            'product_type' => (string)$product->getTypeId(),
            'is_virtual' => in_array($product->getTypeId(), [Type::TYPE_VIRTUAL, 'downloadable'], true),
            'is_downloadable' => $product->getTypeId() === 'downloadable',
        ];

        $regular = (float)$product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
        if ($regular > (float)$item['price']) {
            $item['compare_price'] = round($regular, 4);
            $saleUntil = (string)$product->getData('special_to_date');
            if ($saleUntil !== '') {
                $timestamp = strtotime($saleUntil);
                if ($timestamp !== false) {
                    $item['on_sale_until'] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                }
            }
        }

        if ($languageValues['description'] !== null) {
            $item['description'] = $languageValues['description'];
        }

        $imageUrl = $this->imageUrl($product);
        if ($imageUrl !== null) {
            $item['image_url'] = $imageUrl;
        }

        $tags = $this->tags($product, (string)$item['category_path']);
        if ($tags) {
            $item['tags'] = $tags;
        }

        $item['raw_attributes'] = [
            'type_id' => (string)$product->getTypeId(),
            'attribute_set_id' => (string)$product->getAttributeSetId(),
        ];

        return $item;
    }

    /**
     * Tombstone payload for deleted products: the engine never deletes,
     * an out-of-stock upsert removes the product from recommendations.
     *
     * @return array<string, mixed>
     */
    public function buildTombstone(Product $product): array
    {
        $item = $this->build($product);
        $item['in_stock'] = false;

        return $item;
    }

    private function sku(Product $product): string
    {
        $sku = trim((string)$product->getSku());

        return $sku !== '' ? $sku : 'mag-' . (int)$product->getId();
    }

    /**
     * name/description/product_url as plain strings (single-language) or
     * {lang: value} maps (multi-language storefronts).
     *
     * @return array{name: string|array<string, string>,
     *     description: string|array<string, string>|null,
     *     product_url: string|array<string, string>}
     */
    private function languageValues(Product $product): array
    {
        $storesByLanguage = $this->storesByLanguage($product);

        if (count($storesByLanguage) <= 1) {
            $storeId = $storesByLanguage ? (int)reset($storesByLanguage) : $this->fallbackStoreId($product);

            return [
                'name' => (string)$product->getName(),
                'description' => $this->description($product),
                'product_url' => $this->productUrl($product, $storeId),
            ];
        }

        $names = [];
        $descriptions = [];
        $urls = [];
        foreach ($storesByLanguage as $language => $storeId) {
            try {
                $storeProduct = $this->productRepository->getById((int)$product->getId(), false, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$storeProduct instanceof Product) {
                continue;
            }
            $names[$language] = (string)$storeProduct->getName();
            $description = $this->description($storeProduct);
            if ($description !== null) {
                $descriptions[$language] = $description;
            }
            $urls[$language] = $this->productUrl($storeProduct, (int)$storeId);
        }

        return [
            'name' => $names ?: (string)$product->getName(),
            'description' => $descriptions ?: $this->description($product),
            'product_url' => $urls ?: $this->productUrl($product, $this->fallbackStoreId($product)),
        ];
    }

    /**
     * @return array<string, int> language code -> representative store ID
     */
    private function storesByLanguage(Product $product): array
    {
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        $byLanguage = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($websiteIds && !in_array((int)$store->getWebsiteId(), $websiteIds, true)) {
                continue;
            }
            $language = $this->languageResolver->forStore((int)$store->getId());
            if ($language !== '' && !isset($byLanguage[$language])) {
                $byLanguage[$language] = (int)$store->getId();
            }
        }

        return $byLanguage;
    }

    /**
     * Frontend-scoped product URL.
     *
     * `getProductUrl()` resolves against the *current* app environment, so in
     * a CLI/cron context (backfill jobs, cron flushers) the generated URL can
     * embed the invoking PHP entry script path (observed:
     * `.../run-job.php/some-product.html`) — a link that 404s in an email.
     * Forcing frontend store emulation makes the URL come out exactly as the
     * storefront would render it, in any execution context (web/CLI/cron).
     * Emulation is always stopped, even on failure (try/finally).
     */
    private function productUrl(Product $product, int $storeId): string
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            return (string)$product->getProductUrl();
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * A storefront scope to emulate when the product carries none of its own.
     *
     * Under cron/CLI a product is typically loaded in the admin scope
     * (`store_id` 0), which is NOT a storefront and would still yield an
     * entry-script URL — so fall back to the default store view (the class's
     * canonical single-language fallback).
     */
    private function fallbackStoreId(Product $product): int
    {
        $productStoreId = (int)$product->getStoreId();
        if ($productStoreId > 0) {
            return $productStoreId;
        }

        try {
            return (int)$this->storeManager->getDefaultStoreView()?->getId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }

    private function description(Product $product): ?string
    {
        $raw = (string)($product->getData('short_description') ?: $product->getData('description'));
        $text = trim(strip_tags($raw));
        if ($text === '') {
            return null;
        }

        // Engine truncates at 500 anyway; trim client-side to keep payloads lean.
        return mb_substr($text, 0, 500);
    }

    private function categoryPath(Product $product): string
    {
        $deepest = null;
        $deepestLevel = -1;
        foreach (array_map('intval', (array)$product->getCategoryIds()) as $categoryId) {
            try {
                $category = $this->categoryRepository->get($categoryId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if ((int)$category->getLevel() > $deepestLevel) {
                $deepest = $category;
                $deepestLevel = (int)$category->getLevel();
            }
        }
        if ($deepest === null) {
            return 'uncategorized';
        }

        $segments = [];
        $pathIds = array_slice(explode('/', (string)$deepest->getPath()), 2); // skip root + default
        foreach (array_map('intval', $pathIds) as $pathId) {
            try {
                $pathCategory = $this->categoryRepository->get($pathId);
            } catch (NoSuchEntityException) {
                continue;
            }
            if (!$pathCategory instanceof \Magento\Catalog\Model\Category) {
                continue;
            }
            $slug = (string)($pathCategory->getData('url_key') ?: $this->slugify((string)$pathCategory->getName()));
            if ($slug !== '') {
                $segments[] = $slug;
            }
        }

        return $segments ? implode('/', $segments) : 'uncategorized';
    }

    private function isInStock(Product $product): bool
    {
        try {
            return (bool)$this->stockRegistry->getStockItem((int)$product->getId())->getIsInStock();
        } catch (\Exception) {
            return true;
        }
    }

    private function imageUrl(Product $product): ?string
    {
        try {
            $url = $this->imageHelperFactory->create()
                ->init($product, 'product_page_image_large')
                ->getUrl();

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function tags(Product $product, string $categoryPath): array
    {
        $tags = [
            'category_path' => $categoryPath,
            // §3 identity: the platform parent product id — the configurable
            // parent's entity id for a child, the product's own otherwise.
            // Keys §3b product-level removal (PRO-1231); the `sku` keying
            // itself is intentionally unchanged (PRO-1267: order lines and
            // catalog rows must keep keying consistently).
            'product_id' => $this->parentProductResolver->productIdOf((int)$product->getId()),
        ];

        $brand = $product->getAttributeText('manufacturer');
        if (is_string($brand) && trim($brand) !== '') {
            $tags['brand'] = trim($brand);
        }

        return $tags;
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));

        return $slug;
    }
}
