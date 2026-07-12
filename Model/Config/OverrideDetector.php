<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Finds the website / store-view `core_config_data` rows that shadow the
 * default-scope value the Settings page and wizard write (PRO-1274).
 *
 * The Settings surface always saves at the default scope, but a more specific
 * scope row (a website subdomain carried over by the 2.8.x migration, or the
 * per-store-view credentials multilingual mode A writes) wins at runtime — so
 * a merchant "saves" a value here and sees different effective behaviour. This
 * detector powers the awareness indicator that makes those overrides visible
 * and clearable.
 *
 * Reads straight from the config value collection (the real stored rows),
 * never the merged/cached ScopeConfig, so what it reports is exactly what is
 * on disk.
 */
class OverrideDetector
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleConfigPaths $modulePaths
    ) {
    }

    /**
     * Overrides keyed by config path: every module path that carries at least
     * one explicit non-default row, with the shadowing scope(s) labelled for
     * the admin.
     *
     * @return array<string, list<array{scope: string, scopeId: int, label: string}>>
     */
    public function detect(): array
    {
        $paths = $this->modulePaths->overridablePaths();
        if ($paths === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('path', ['in' => $paths]);
        $collection->addFieldToFilter('scope', ['neq' => 'default']);

        $overrides = [];
        foreach ($collection as $row) {
            $path = (string)$row->getData('path');
            $scope = (string)$row->getData('scope');
            $scopeId = (int)$row->getData('scope_id');
            if ($scopeId === 0 || ($scope !== ScopeInterface::SCOPE_WEBSITES && $scope !== ScopeInterface::SCOPE_STORES)) {
                // Global (website 0) rows do not shadow a specific scope's
                // default here; only real websites / store views do.
                continue;
            }
            $overrides[$path][] = [
                'scope' => $scope,
                'scopeId' => $scopeId,
                'label' => $this->label($scope, $scopeId),
            ];
        }

        return $overrides;
    }

    /**
     * Human label for a shadowing scope — the website or store-view name,
     * degrading to a stable identifier if the scope was since removed.
     */
    private function label(string $scope, int $scopeId): string
    {
        try {
            if ($scope === ScopeInterface::SCOPE_WEBSITES) {
                return (string)$this->storeManager->getWebsite($scopeId)->getName();
            }

            $store = $this->storeManager->getStore($scopeId);
            $website = $this->storeManager->getWebsite($store->getWebsiteId());

            return trim(sprintf(
                '%s / %s',
                (string)$website->getName(),
                (string)$store->getName()
            ), ' /');
        } catch (\Exception) {
            return $scope === ScopeInterface::SCOPE_WEBSITES
                ? sprintf('website #%d', $scopeId)
                : sprintf('store view #%d', $scopeId);
        }
    }
}
