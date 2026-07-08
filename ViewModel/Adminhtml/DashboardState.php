<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Setup checklist and operational counters for the Getting Started page.
 */
class DashboardState implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly Settings $engineSettings,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isSmailyConnected(): bool
    {
        return $this->config->isConnected();
    }

    public function isSyncEnabled(): bool
    {
        return $this->config->isSyncEnabled();
    }

    public function getSyncModeLabel(): string
    {
        return match ($this->mode->mode()) {
            'legitimate_interest' => (string)__('All customers (legitimate interest)'),
            'checkout_optin' => (string)__('Checkout opt-in only'),
            default => (string)__('Subscribers only (consent)'),
        };
    }

    public function hasAutomationsConfigured(): bool
    {
        return $this->config->getWelcomeWorkflow() > 0
            || $this->config->getFirstOrderWorkflow() > 0
            || $this->config->getAbandonedCartWorkflow() > 0;
    }

    public function isEngineConnected(): bool
    {
        return $this->engineSettings->isConnected();
    }

    public function getEngineTenantLabel(): string
    {
        return $this->engineSettings->getTenantName() ?: $this->engineSettings->getTenantId();
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function getEventCounts(): array
    {
        return $this->countByStatus(EventResource::TABLE_NAME);
    }

    /**
     * @return array{pending: int, failed: int}
     */
    public function getIngestCounts(): array
    {
        return $this->countByStatus(IngestEventResource::TABLE_NAME);
    }

    /**
     * @return array{pending: int, failed: int}
     */
    private function countByStatus(string $tableName): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName($tableName), ['status', 'cnt' => 'COUNT(*)'])
            ->group('status');

        $counts = ['pending' => 0, 'failed' => 0];
        foreach ($connection->fetchPairs($select) as $status => $count) {
            if ($status === 'pending' || $status === 'sending') {
                $counts['pending'] += (int)$count;
            } elseif ($status === 'failed') {
                $counts['failed'] += (int)$count;
            }
        }

        return $counts;
    }
}
