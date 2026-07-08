<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Magento\Framework\FlagManager;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Mirrors Smaily's marketing-consent state back into Magento newsletter
 * subscribers (consent mode only).
 *
 * Delta-first, mirroring the WooCommerce ContactReconciler: polls the Smaily
 * action log (GET /api/history.php, since_seq_id cursor) for
 * optin/optout/delete/complaint events — O(changes), not O(contacts). Smaily
 * has no webhooks, so pull is the only option. Writes run inside the
 * ReconcileGuard so the subscriber-save observer never echoes them back
 * (and a Smaily delete never re-creates the contact, fighting GDPR erasure).
 */
class ContactReconcile
{
    private const RECONCILE_ACTIONS = ['optin', 'optout', 'delete', 'complaint'];
    private const PAGE_SIZE = 10000;
    private const MAX_PAGES = 50;
    private const FLAG_PREFIX = 'smaily_connect_reconcile_seq_w';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly SmailyClientProvider $clientProvider,
        private readonly FlagManager $flagManager,
        private readonly ReconcileGuard $guard,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly SubscriberResource $subscriberResource,
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
            if (!$this->config->isSyncEnabled($websiteId) || !$this->mode->reconciles($websiteId)) {
                continue;
            }

            $storeId = (int)$website->getDefaultStore()?->getId();
            if (!$storeId || !$this->config->isConnected($storeId)) {
                continue;
            }

            try {
                $changed = $this->reconcileWebsite($websiteId, $storeId);
                if ($changed > 0) {
                    $this->logger->info('Reconciled Smaily consent changes', [
                        'website_id' => $websiteId,
                        'changed' => $changed,
                    ]);
                }
            } catch (SmailyClientException $exception) {
                $this->logger->error('Consent reconcile failed', [
                    'website_id' => $websiteId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function reconcileWebsite(int $websiteId, int $storeId): int
    {
        $client = $this->clientProvider->forStore($storeId);
        $cursor = (int)$this->flagManager->getFlagData(self::FLAG_PREFIX . $websiteId);
        $changed = 0;
        $pages = 0;

        do {
            $rows = $client->get(SmailyClient::ENDPOINT_HISTORY, [
                'since_seq_id' => $cursor,
                'limit' => self::PAGE_SIZE,
                'actions' => implode(',', self::RECONCILE_ACTIONS),
            ]);
            $rows = array_values(array_filter($rows, 'is_array'));
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = max($cursor, (int)($row['seq_id'] ?? 0));
                $email = strtolower(trim((string)($row['email'] ?? '')));
                $action = (string)($row['action'] ?? '');
                if ($email !== '' && $action !== '') {
                    $changed += $this->apply($email, $action, $websiteId);
                }
            }

            $pages++;
            $fullPage = count($rows) >= self::PAGE_SIZE;
        } while ($fullPage && $pages < self::MAX_PAGES);

        $this->flagManager->saveFlag(self::FLAG_PREFIX . $websiteId, $cursor);

        return $changed;
    }

    /**
     * Mirror one Smaily action onto the matching subscriber. optin ->
     * subscribed; optout/delete/complaint -> unsubscribed. Idempotent: no
     * write when the state is already correct or the email is unknown.
     */
    private function apply(string $email, string $action, int $websiteId): int
    {
        $subscriber = $this->subscriberFactory->create()->loadBySubscriberEmail($email, $websiteId);
        if (!$subscriber->getId()) {
            return 0;
        }

        $desired = $action === 'optin'
            ? Subscriber::STATUS_SUBSCRIBED
            : Subscriber::STATUS_UNSUBSCRIBED;
        if ((int)$subscriber->getStatus() === $desired) {
            return 0;
        }

        $resource = $this->subscriberResource;
        $this->guard->runSuppressed(static function () use ($subscriber, $desired, $resource): void {
            $subscriber->setStatus($desired);
            // Import mode: Magento sends no confirmation/unsubscribe email
            // for a state change that originated in Smaily.
            $subscriber->setImportMode(true);
            $resource->save($subscriber);
        });

        return 1;
    }
}
