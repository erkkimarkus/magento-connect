<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Removes one website / store-view `core_config_data` row so the default the
 * Settings page edits becomes effective again — Magento's native "Use Default"
 * semantics, applied to the module's own Settings surface (PRO-1274).
 *
 * The delete is guarded twice: the scope must be a real website / store view
 * (never the default the Settings page owns), and the path must be on the
 * module's own allowlist — so this affordance can never delete unrelated store
 * configuration even if the request is tampered with.
 */
class OverrideClearer
{
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly ModuleConfigPaths $modulePaths
    ) {
    }

    /**
     * @return array{cleared: bool, path: string, scope: string, scopeId: int, message: string}
     */
    public function clear(string $path, string $scope, int $scopeId): array
    {
        if ($scope !== ScopeInterface::SCOPE_WEBSITES && $scope !== ScopeInterface::SCOPE_STORES) {
            return $this->reject($path, $scope, $scopeId, (string)__('Only website or store-view overrides can be cleared.'));
        }

        if ($scopeId <= 0) {
            return $this->reject($path, $scope, $scopeId, (string)__('Invalid override scope.'));
        }

        if (!$this->modulePaths->isAllowed($path)) {
            return $this->reject(
                $path,
                $scope,
                $scopeId,
                (string)__('That setting is not managed by Smaily Connect, so it was not touched.')
            );
        }

        $this->configWriter->delete($path, $scope, $scopeId);
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);

        return [
            'cleared' => true,
            'path' => $path,
            'scope' => $scope,
            'scopeId' => $scopeId,
            'message' => (string)__('Override cleared — the default now applies here.'),
        ];
    }

    /**
     * @return array{cleared: bool, path: string, scope: string, scopeId: int, message: string}
     */
    private function reject(string $path, string $scope, int $scopeId, string $message): array
    {
        return [
            'cleared' => false,
            'path' => $path,
            'scope' => $scope,
            'scopeId' => $scopeId,
            'message' => $message,
        ];
    }
}
