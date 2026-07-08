<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Detects abandoned quotes and enqueues abandoned cart automation events.
 *
 * Scans the native quote table (is_active, items, email) — Magento already
 * tracks cart state, so no checkout webhooks or extra tracking are needed.
 * A quote is a candidate when idle past the configured cutoff but younger
 * than MAX_AGE (24h backlog guard shared with the Woo/Shopify plugins:
 * a long-broken cron must not blast stale reminders on recovery). Send
 * state lives in the smaily_abandoned_cart side table; delivery, retries
 * and the event log come from the queue.
 */
class AbandonedCart
{
    private const MAX_AGE_SECONDS = 86400;
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly QuoteCollectionFactory $quoteCollectionFactory,
        private readonly StateManager $stateManager,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly SyncDispatcher $dispatcher,
        private readonly Emulation $emulation,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if (!$website instanceof \Magento\Store\Model\Website) {
                continue;
            }
            $websiteId = (int)$website->getId();
            if (!$this->config->isAbandonedCartEnabled($websiteId)) {
                continue;
            }

            $storeIds = array_map('intval', $website->getStoreIds());
            if (!$storeIds || !$this->config->isConnected((int)$website->getDefaultStore()?->getId())) {
                continue;
            }

            $this->processWebsite($websiteId, $storeIds);
        }
    }

    /**
     * @param int[] $storeIds
     */
    private function processWebsite(int $websiteId, array $storeIds): void
    {
        $now = $this->dateTime->gmtTimestamp();
        $idleSince = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $now - $this->config->getAbandonedCutoffMinutes($websiteId) * 60
        );
        $maxAge = $this->dateTime->gmtDate('Y-m-d H:i:s', $now - self::MAX_AGE_SECONDS);

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1])
            ->addFieldToFilter('items_count', ['gt' => 0])
            ->addFieldToFilter('customer_email', ['notnull' => true])
            ->addFieldToFilter('customer_email', ['neq' => ''])
            ->addFieldToFilter('store_id', ['in' => $storeIds])
            ->addFieldToFilter('updated_at', ['from' => $maxAge, 'to' => $idleSince])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize(self::BATCH_SIZE);

        $quotes = [];
        foreach ($collection->getItems() as $quote) {
            if ($quote instanceof Quote) {
                $quotes[(int)$quote->getId()] = $quote;
            }
        }
        if (!$quotes) {
            return;
        }

        $handled = $this->stateManager->filterAlreadyHandled(array_keys($quotes));
        $candidates = array_diff_key($quotes, array_flip($handled));

        $mailed = 0;
        foreach ($candidates as $quote) {
            $storeId = (int)$quote->getStoreId();
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $address = $this->payloadBuilder->build($quote, $websiteId);
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }

            if (($address['email'] ?? '') === '') {
                continue;
            }

            // Mark first, dispatch second: if we crash in between, the shopper
            // misses one reminder instead of receiving a duplicate.
            $this->stateManager->markMailed((int)$quote->getId(), $storeId, $address['email']);
            $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, $storeId, $address);
            $mailed++;
        }

        if ($mailed > 0) {
            $this->logger->info('Abandoned cart automations enqueued', [
                'website_id' => $websiteId,
                'count' => $mailed,
            ]);
        }
    }
}
