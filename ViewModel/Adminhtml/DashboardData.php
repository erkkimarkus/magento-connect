<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\FlagManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Cron\HealthCheck;
use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Health\QueueHealth;

/**
 * Operational dashboard data. The verdict reuses the HealthCheck cron's
 * state (engine-down flag) and query (failed rows in 24h) so the dashboard
 * and the admin notifications always agree.
 */
class DashboardData implements ArgumentInterface
{
    public const VERDICT_INCOMPLETE = 'incomplete';
    public const VERDICT_DEGRADED = 'degraded';
    public const VERDICT_OK = 'ok';

    private ?int $failed24h = null;

    public function __construct(
        private readonly Config $config,
        private readonly EngineSettings $engineSettings,
        private readonly SetupGuard $setupGuard,
        private readonly QueueHealth $queueHealth,
        private readonly DashboardStats $stats,
        private readonly FlagManager $flagManager
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->setupGuard->isSetupCompleted();
    }

    public function isSmailyConnected(): bool
    {
        return $this->config->isConnected();
    }

    public function getSmailySubdomain(): string
    {
        return $this->config->getSubdomain();
    }

    public function isEngineConnected(): bool
    {
        return $this->engineSettings->isConnected();
    }

    public function getEngineTenantName(): string
    {
        return $this->engineSettings->getTenantName() ?: $this->engineSettings->getTenantId();
    }

    public function isBrowseTrackingEnabled(): bool
    {
        return $this->engineSettings->isBrowseTrackingEnabled();
    }

    /**
     * Whether the HealthCheck cron currently sees the engine as unreachable.
     */
    public function isEngineDown(): bool
    {
        return $this->isEngineConnected()
            && (int)$this->flagManager->getFlagData(HealthCheck::FLAG_ENGINE_DOWN_SINCE) > 0;
    }

    public function getFailedLast24h(): int
    {
        if ($this->failed24h === null) {
            $this->failed24h = $this->queueHealth->failedSince(86400);
        }

        return $this->failed24h;
    }

    /**
     * One-word health state: incomplete | degraded | ok.
     */
    public function getVerdict(): string
    {
        if (!$this->isSetupCompleted()) {
            return self::VERDICT_INCOMPLETE;
        }
        if ($this->getFailedLast24h() > 0 || $this->isEngineDown()) {
            return self::VERDICT_DEGRADED;
        }

        return self::VERDICT_OK;
    }

    public function getContactSyncsDelivered(): int
    {
        return $this->stats->contactSyncsDelivered();
    }

    public function getCatalogItemsDelivered(): int
    {
        return $this->stats->catalogItemsDelivered();
    }

    public function getQueuedToday(): int
    {
        return $this->stats->queuedToday();
    }

    /**
     * @return array<int, array{source: string, type: string, entity_id: string,
     *     status: string, updated_at: string}>
     */
    public function getRecentActivity(int $limit = 10): array
    {
        return $this->stats->recentActivity($limit);
    }
}
