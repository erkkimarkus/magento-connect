<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Rss;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Rss\FeedBuilder;

/**
 * Public product RSS feed at smaily/rss/feed (legacy-compatible route).
 *
 * Query parameters: category (category ID), limit (1-250, default 50),
 * sort (created_at|updated_at|name|price), order (asc|desc). Standard
 * Magento store resolution applies, so per-store feeds use the store's
 * base URL or ?___store=.
 */
class Feed implements HttpGetActionInterface
{
    private const CACHE_LIFETIME_SECONDS = 900;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly FeedBuilder $feedBuilder,
        private readonly Config $config,
        private readonly \Magento\Framework\App\CacheInterface $cache
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw
    {
        $result = $this->rawFactory->create();

        if (!$this->config->isRssEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');

            return $result;
        }

        $categoryParam = $this->request->getParam('category');
        $categoryId = is_numeric($categoryParam) ? (int)$categoryParam : null;
        $limit = (int)$this->request->getParam('limit', FeedBuilder::DEFAULT_LIMIT);
        $sort = (string)$this->request->getParam('sort', 'created_at');
        $order = (string)$this->request->getParam('order', 'desc');
        $store = $this->storeManager->getStore();

        // Server-side cache: the feed is unauthenticated and rebuilding it
        // loads up to 250 products, so identical requests must not hit the DB.
        $cacheKey = 'smaily_rss_' . sha1(implode('|', [
            (int)$store->getId(),
            (string)$categoryId,
            $limit,
            $sort,
            $order,
        ]));
        $xml = $this->cache->load($cacheKey);
        if ($xml === false) {
            $xml = $this->feedBuilder->build($store, $categoryId, $limit, $sort, $order);
            $this->cache->save($xml, $cacheKey, [], self::CACHE_LIFETIME_SECONDS);
        }

        $result->setHeader('Content-Type', 'application/rss+xml; charset=UTF-8', true);
        $result->setHeader(
            'Cache-Control',
            sprintf('public, max-age=%d', self::CACHE_LIFETIME_SECONDS),
            true
        );
        $result->setContents($xml);

        return $result;
    }
}
