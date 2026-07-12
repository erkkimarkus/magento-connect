<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\AbandonedFields;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Persists wizard steps into the SAME system config paths the
 * Stores > Configuration page edits — one source of truth, the wizard is
 * just a guided view over it.
 */
class WizardStepSaver
{
    public const XML_PATH_SETUP_COMPLETED = 'smaily_connect/internal/setup_completed';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly SubdomainNormalizer $normalizer,
        private readonly AccountResolver $accountResolver,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly MappingSaver $mappingSaver,
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly ConfigRowNormalizer $rowNormalizer
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function save(string $step, array $data): array
    {
        $errors = match ($step) {
            'connect' => $this->saveConnect($data),
            'subscribers' => $this->saveSubscribers($data),
            'automations' => $this->saveAutomations($data),
            'intelligence' => $this->saveIntelligence($data),
            'rss' => $this->saveRss($data),
            'finish' => $this->saveFinish(),
            default => [['field' => 'step', 'message' => (string)__('Unknown wizard step "%1".', $step)]],
        };

        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveConnect(array $data): array
    {
        $subdomain = $this->normalizer->normalize((string)($data['subdomain'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($subdomain === '' || $username === '') {
            return [['field' => 'subdomain', 'message' => (string)__('Subdomain and username are required.')]];
        }

        $this->configWriter->save(Config::XML_PATH_SUBDOMAIN, $subdomain);
        $this->configWriter->save(Config::XML_PATH_USERNAME, $username);
        if ($password !== '' && preg_match('/^\*+$/', $password) !== 1) {
            $this->configWriter->save(Config::XML_PATH_PASSWORD, $this->encryptor->encrypt($password));
        }

        $previousMode = $this->config->getMultilingualMode() ?: MultilingualMode::MODE_SINGLE;
        $mode = strtolower((string)($data['multilingual_mode'] ?? 'single'));
        if (in_array($mode, ['single', 'a', 'b', 'c'], true)) {
            $this->configWriter->save(Config::XML_PATH_MULTILINGUAL_MODE, $mode);
        } else {
            $mode = $previousMode;
        }

        // Mode A: per-language accounts land as store-view scoped credentials
        // on every store view speaking that language.
        foreach ((array)($data['accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }
            $language = (string)($account['language'] ?? '');
            $accountSubdomain = $this->normalizer->normalize((string)($account['subdomain'] ?? ''));
            $accountUsername = trim((string)($account['username'] ?? ''));
            $accountPassword = (string)($account['password'] ?? '');
            if ($language === '' || $accountSubdomain === '' || $accountUsername === '') {
                continue;
            }
            foreach ($this->accountResolver->storeIdsForAccountKey($language) as $storeId) {
                $this->configWriter->save(
                    Config::XML_PATH_SUBDOMAIN,
                    $accountSubdomain,
                    ScopeInterface::SCOPE_STORES,
                    $storeId
                );
                $this->configWriter->save(
                    Config::XML_PATH_USERNAME,
                    $accountUsername,
                    ScopeInterface::SCOPE_STORES,
                    $storeId
                );
                if ($accountPassword !== '' && preg_match('/^\*+$/', $accountPassword) !== 1) {
                    $this->configWriter->save(
                        Config::XML_PATH_PASSWORD,
                        $this->encryptor->encrypt($accountPassword),
                        ScopeInterface::SCOPE_STORES,
                        $storeId
                    );
                }
            }
        }

        // The default-fallback account (mode A): its credentials are also
        // posted as the top-level subdomain/username/password, so the default
        // scope IS the fallback account; the language is remembered for the
        // admin UI's fallback picker.
        $fallbackLanguage = strtolower(trim((string)($data['fallback_language'] ?? '')));
        if ($fallbackLanguage !== '' && preg_match('/^[a-z]{2,3}$/', $fallbackLanguage) === 1) {
            $this->configWriter->save(Config::XML_PATH_FALLBACK_LANGUAGE, $fallbackLanguage);
        }

        // Leaving mode A is destructive by design (the UI confirms first):
        // the per-store-view credential overrides written for the
        // per-language accounts are removed, so every store view follows the
        // single account again. Mapping rows are kept — the Router ignores
        // them outside modes a/b.
        if ($previousMode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            && $mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
        ) {
            foreach ($this->storeManager->getStores() as $store) {
                foreach ([Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD] as $path) {
                    $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, (int)$store->getId());
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSubscribers(array $data): array
    {
        $this->saveFlag(Config::XML_PATH_SYNC_ENABLED, $data, 'sync_enabled');
        $this->saveFlag(Config::XML_PATH_INCLUDE_GUESTS, $data, 'include_guests');
        $this->saveFlag(Config::XML_PATH_AUTOMATION_FORCE_OPT_IN, $data, 'automation_force_opt_in');
        $this->saveFlag(Config::XML_PATH_CHECKOUT_OPTIN_ENABLED, $data, 'checkout_optin_enabled');
        $this->saveFlag(Config::XML_PATH_SUPPRESS_OPTIN_EMAILS, $data, 'suppress_optin_emails');

        $mode = (string)($data['sync_mode'] ?? '');
        if (in_array($mode, [
            SyncMode::MODE_CONSENT,
            SyncMode::MODE_LEGITIMATE_INTEREST,
            SyncMode::MODE_CHECKOUT_OPTIN,
        ], true)) {
            $this->configWriter->save(Config::XML_PATH_SYNC_MODE, $mode);
        }

        if (isset($data['sync_fields']) && is_array($data['sync_fields'])) {
            $fields = array_values(array_intersect(
                SyncFields::SUPPORTED_FIELDS,
                array_map('strval', $data['sync_fields'])
            ));
            $this->configWriter->save(Config::XML_PATH_SYNC_FIELDS, implode(',', $fields));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveAutomations(array $data): array
    {
        $this->saveFlag(Config::XML_PATH_WELCOME_ENABLED, $data, 'welcome_enabled');
        $this->saveFlag(Config::XML_PATH_FIRST_ORDER_ENABLED, $data, 'first_order_enabled');
        $this->saveFlag(Config::XML_PATH_ABANDONED_ENABLED, $data, 'abandoned_enabled');

        // A saved workflow id that is missing from the freshly loaded Smaily
        // list was never offered in the select, so an empty post is not a
        // deliberate clear — the stored binding is kept rather than dropped
        // (PRO-1286, same rule as the engine-automations single mode). Only
        // resolved lazily below, when a workflow key is actually posted.
        $availableWorkflowIds = null;
        foreach ([
            'welcome_workflow' => [Config::XML_PATH_WELCOME_WORKFLOW, $this->config->getWelcomeWorkflow()],
            'first_order_workflow' => [Config::XML_PATH_FIRST_ORDER_WORKFLOW, $this->config->getFirstOrderWorkflow()],
            'abandoned_workflow' => [Config::XML_PATH_ABANDONED_WORKFLOW, $this->config->getAbandonedCartWorkflow()],
        ] as $key => [$path, $savedWorkflow]) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $postedId = ($id = (int)$data[$key]) > 0 ? (string)$id : '';
            $savedId = $savedWorkflow > 0 ? (string)$savedWorkflow : '';
            $availableWorkflowIds ??= $this->workflowIdsForStore(null);
            if ($this->rowNormalizer->isMissingFromList($postedId, $savedId, $availableWorkflowIds)) {
                // Missing-from-list preserve: leave the stored value untouched.
                continue;
            }
            $this->configWriter->save($path, $postedId === '' ? '0' : $postedId);
        }

        if (array_key_exists('abandoned_cutoff', $data)) {
            $this->configWriter->save(
                Config::XML_PATH_ABANDONED_CUTOFF,
                (string)max(Config::MIN_ABANDONED_CUTOFF_MINUTES, min(1440, (int)$data['abandoned_cutoff']))
            );
        }

        if (isset($data['abandoned_fields']) && is_array($data['abandoned_fields'])) {
            $fields = array_values(array_intersect(
                AbandonedFields::SUPPORTED_FIELDS,
                array_map('strval', $data['abandoned_fields'])
            ));
            $this->configWriter->save(Config::XML_PATH_ABANDONED_FIELDS, implode(',', $fields));
        }

        // Per-language workflow mappings (multilingual modes a/b). The panel
        // sends the full desired state, so absent selections delete their
        // rows; single/c saves omit the key and leave the table untouched.
        // The per-account available-workflow lists let the saver preserve a
        // saved mapping row whose id is missing from its account's live list
        // instead of dropping it on the full sync (PRO-1286).
        if (isset($data['mappings']) && is_array($data['mappings'])) {
            return $this->mappingSaver->save(
                $data['mappings'],
                0,
                $this->availableWorkflowIdsByAccount()
            );
        }

        return [];
    }

    /**
     * Available workflow ids per mapping account key: 'default' (the shared /
     * mode-B account) plus each detected language (mode A, where a language's
     * rows route through that language's own Smaily account). An account whose
     * list cannot be loaded maps to an empty list, which the saver reads as
     * "unknown" and preserves.
     *
     * @return array<string, array<int, string>>
     */
    private function availableWorkflowIdsByAccount(): array
    {
        $byAccount = [Mapping::ACCOUNT_DEFAULT => $this->workflowIdsForStore(null)];
        foreach ($this->accountResolver->detectedLanguages() as $language) {
            $byAccount[$language] = $this->workflowIdsForStore(
                $this->accountResolver->storeIdForAccountKey($language)
            );
        }

        return $byAccount;
    }

    /**
     * Workflow ids the Smaily account bound to the given store scope can list,
     * as strings. An empty array means the list is unavailable (credentials
     * missing or the listing failed) — the caller then keeps every saved id.
     *
     * @return array<int, string>
     */
    private function workflowIdsForStore(?int $storeId): array
    {
        try {
            $workflows = $this->smailyClientProvider->forStore($storeId)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }

        return array_map(static fn (array $workflow): string => (string)$workflow['id'], $workflows);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveIntelligence(array $data): array
    {
        $this->saveFlag(EngineSettings::XML_PATH_BROWSE_TRACKING, $data, 'browse_tracking');
        $this->saveFlag(EngineSettings::XML_PATH_SYNC_CATALOG, $data, 'sync_catalog');
        $this->saveFlag(EngineSettings::XML_PATH_SYNC_CUSTOMERS, $data, 'sync_customers');
        $this->saveFlag(EngineSettings::XML_PATH_SYNC_ORDERS, $data, 'sync_orders');

        return [];
    }

    /**
     * Settings-page RSS tab (the wizard has no RSS step of its own).
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveRss(array $data): array
    {
        $this->saveFlag(Config::XML_PATH_RSS_ENABLED, $data, 'rss_enabled');

        return [];
    }

    /**
     * @return array<int, array{field: string, message: string}>
     */
    private function saveFinish(): array
    {
        $this->configWriter->save(self::XML_PATH_SETUP_COMPLETED, '1');

        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveFlag(string $path, array $data, string $key): void
    {
        if (array_key_exists($key, $data)) {
            $this->configWriter->save($path, $data[$key] ? '1' : '0');
        }
    }
}
