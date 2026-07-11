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
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\AbandonedFields;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
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
        private readonly AccountResolver $accountResolver
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

        $mode = strtolower((string)($data['multilingual_mode'] ?? 'single'));
        if (in_array($mode, ['single', 'a', 'b', 'c'], true)) {
            $this->configWriter->save(Config::XML_PATH_MULTILINGUAL_MODE, $mode);
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

        foreach ([
            'welcome_workflow' => Config::XML_PATH_WELCOME_WORKFLOW,
            'first_order_workflow' => Config::XML_PATH_FIRST_ORDER_WORKFLOW,
            'abandoned_workflow' => Config::XML_PATH_ABANDONED_WORKFLOW,
        ] as $key => $path) {
            if (array_key_exists($key, $data)) {
                $this->configWriter->save($path, (string)(int)$data[$key]);
            }
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

        return [];
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
