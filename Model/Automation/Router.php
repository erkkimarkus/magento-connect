<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory;

/**
 * Resolves the Smaily workflow for a (trigger, website, language) tuple.
 *
 * Mirrors the WooCommerce plugin's Multilingual\Router semantics: modes
 * "single" and "c" collapse every language into the default bucket (one
 * workflow per trigger, configured in system config); modes "a" and "b" look
 * up per-language rows in smaily_automation_mapping, falling back to the
 * trigger's default-fallback row and finally to the config default.
 *
 * Returns 0 when no workflow is mapped — callers treat that as a terminal
 * skip (retrying cannot make a mapping appear).
 */
class Router
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $mappingCollectionFactory
    ) {
    }

    public function resolveWorkflowId(string $trigger, int $websiteId, string $language): int
    {
        $mode = $this->config->getMultilingualMode($websiteId);

        if ($mode === MultilingualMode::MODE_SINGLE || $mode === MultilingualMode::MODE_SINGLE_WORKFLOW) {
            return $this->configDefault($trigger, $websiteId);
        }

        $effectiveLanguage = $language !== '' ? $language : Mapping::LANGUAGE_DEFAULT;

        $workflowId = $this->findMapping($trigger, $websiteId, $effectiveLanguage);
        if ($workflowId > 0) {
            return $workflowId;
        }

        $workflowId = $this->findFallbackMapping($trigger, $websiteId);
        if ($workflowId > 0) {
            return $workflowId;
        }

        return $this->configDefault($trigger, $websiteId);
    }

    private function findMapping(string $trigger, int $websiteId, string $language): int
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['eq' => $websiteId])
            ->addFieldToFilter('trigger_type', $trigger)
            ->addFieldToFilter('language', $language)
            ->setPageSize(1);

        $mapping = $collection->getFirstItem();

        return $mapping instanceof Mapping ? $mapping->getWorkflowId() : 0;
    }

    private function findFallbackMapping(string $trigger, int $websiteId): int
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['eq' => $websiteId])
            ->addFieldToFilter('trigger_type', $trigger)
            ->addFieldToFilter('is_default_fallback', ['eq' => 1])
            ->setPageSize(1);

        $mapping = $collection->getFirstItem();

        return $mapping instanceof Mapping ? $mapping->getWorkflowId() : 0;
    }

    private function configDefault(string $trigger, int $websiteId): int
    {
        return match ($trigger) {
            Trigger::WELCOME => $this->config->getWelcomeWorkflow($websiteId),
            Trigger::FIRST_ORDER => $this->config->getFirstOrderWorkflow($websiteId),
            Trigger::ABANDONED_CART => $this->config->getAbandonedCartWorkflow($websiteId),
            default => 0,
        };
    }
}
