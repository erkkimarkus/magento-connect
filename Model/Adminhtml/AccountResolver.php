<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * Maps the SPA's account keys onto Magento store views.
 *
 * The Woo-shaped admin app addresses per-language Smaily accounts by an
 * accountKey (the language code); in Magento a per-language account is a
 * store-view scoped credential set, so an account key resolves to the store
 * views whose locale matches that language.
 */
class AccountResolver
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LanguageResolver $languageResolver
    ) {
    }

    /**
     * Store view IDs whose language matches the account key.
     *
     * @return int[]
     */
    public function storeIdsForAccountKey(string $accountKey): array
    {
        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->languageResolver->forStore((int)$store->getId()) === $accountKey) {
                $storeIds[] = (int)$store->getId();
            }
        }

        return $storeIds;
    }

    /**
     * A representative store view for credential resolution ('default' =
     * default scope).
     */
    public function storeIdForAccountKey(string $accountKey): ?int
    {
        if ($accountKey === '' || $accountKey === 'default') {
            return null;
        }
        $storeIds = $this->storeIdsForAccountKey($accountKey);

        return $storeIds[0] ?? null;
    }

    /**
     * Distinct storefront languages, default store's language first.
     *
     * @return string[]
     */
    public function detectedLanguages(): array
    {
        $languages = [];
        $defaultStore = $this->storeManager->getDefaultStoreView();
        if ($defaultStore !== null) {
            $default = $this->languageResolver->forStore((int)$defaultStore->getId());
            if ($default !== '') {
                $languages[] = $default;
            }
        }
        foreach ($this->storeManager->getStores() as $store) {
            $language = $this->languageResolver->forStore((int)$store->getId());
            if ($language !== '' && !in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }

        return $languages;
    }
}
