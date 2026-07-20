<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;

/**
 * Boot data for the native setup wizard AND the tabbed settings page (both
 * render the same step partials): saved settings for prefill, store
 * environment for guidance texts, and connection state for step gating.
 */
class WizardData implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly EngineSettings $engineSettings,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AccountResolver $accountResolver,
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Json $serializer,
        private readonly MappingCollectionFactory $mappingCollectionFactory,
        private readonly WebsiteContext $websiteContext
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->scopeConfig->isSetFlag(WizardStepSaver::XML_PATH_SETUP_COMPLETED);
    }

    public function getBootJson(): string
    {
        return $this->serializer->serialize([
            'connected' => $this->config->isConnected(),
            'setupCompleted' => $this->isSetupCompleted(),
            'connection' => [
                'subdomain' => $this->config->getSubdomain(),
                'username' => $this->config->getUsername(),
                'hasPassword' => $this->config->getPassword() !== '',
                'multilingualMode' => $this->getMultilingualMode(),
            ],
            'multilingual' => [
                'languages' => $this->accountResolver->detectedLanguages($this->websiteContext->getWebsiteId()),
                'fallbackLanguage' => $this->config->getFallbackLanguage(),
            ],
            'subscribers' => [
                'syncEnabled' => $this->config->isSyncEnabled(),
                'syncMode' => $this->mode->mode(),
                'syncFields' => $this->config->getSyncFields(),
                'includeGuests' => $this->config->includeGuests(),
                'forceOptIn' => $this->config->automationForceOptIn(),
                'checkoutOptin' => $this->config->isCheckoutOptinEnabled(),
                'suppressOptinEmails' => $this->config->suppressOptinEmails(),
            ],
            'automations' => [
                'welcomeEnabled' => $this->config->isWelcomeEnabled(),
                'welcomeWorkflow' => (string)($this->config->getWelcomeWorkflow() ?: ''),
                'firstOrderEnabled' => $this->config->isFirstOrderEnabled(),
                'firstOrderWorkflow' => (string)($this->config->getFirstOrderWorkflow() ?: ''),
                'abandonedEnabled' => $this->config->isAbandonedCartEnabled(),
                'abandonedWorkflow' => (string)($this->config->getAbandonedCartWorkflow() ?: ''),
                'abandonedCutoff' => $this->config->getAbandonedCutoffMinutes(),
            ],
            'intelligence' => [
                'connected' => $this->engineSettings->isConnected(),
                'tenantName' => $this->engineSettings->getTenantName()
                    ?: $this->engineSettings->getTenantId(),
                'engineVersion' => $this->engineSettings->getEngineVersion(),
                'browseTracking' => $this->engineSettings->isBrowseTrackingEnabled(),
                'syncCatalog' => $this->engineSettings->isCatalogSyncEnabled(),
                'syncCustomers' => $this->engineSettings->isCustomerSyncEnabled(),
                'syncOrders' => $this->engineSettings->isOrderSyncEnabled(),
            ],
            'rss' => [
                'enabled' => $this->config->isRssEnabled(),
            ],
            'totals' => $this->getStoreTotals(),
        ]);
    }

    /**
     * Saved sync-field selection for the server-rendered step-2 checkboxes.
     *
     * @return string[]
     */
    public function getSelectedSyncFields(): array
    {
        return $this->config->getSyncFields();
    }

    /**
     * Saved abandoned-cart product-field selection for the server-rendered
     * checkboxes on the Automations tab.
     *
     * @return string[]
     */
    public function getSelectedAbandonedFields(): array
    {
        return $this->config->getAbandonedFields();
    }

    /**
     * @return array{customers: int, orders: int, products: int}
     */
    public function getStoreTotals(): array
    {
        return [
            'customers' => $this->customerCollectionFactory->create()->getSize(),
            'orders' => $this->orderCollectionFactory->create()->getSize(),
            'products' => $this->productCollectionFactory->create()->getSize(),
        ];
    }

    /**
     * The effective multilingual mode: single-language installations are
     * locked to 'single' (the panels hide the mode cards for them).
     */
    public function getMultilingualMode(): string
    {
        if (!$this->isMultilingual()) {
            return MultilingualMode::MODE_SINGLE;
        }

        return $this->config->getMultilingualMode() ?: MultilingualMode::MODE_SINGLE;
    }

    /**
     * More than one distinct storefront language configured?
     */
    public function isMultilingual(): bool
    {
        return count($this->accountResolver->detectedLanguages($this->websiteContext->getWebsiteId())) > 1;
    }

    /**
     * @return string[]
     */
    public function getDetectedLanguages(): array
    {
        return $this->accountResolver->detectedLanguages($this->websiteContext->getWebsiteId());
    }

    /**
     * The language whose account is the mode-A default fallback ('' = none
     * picked yet).
     */
    public function getFallbackLanguage(): string
    {
        return $this->config->getFallbackLanguage();
    }

    /**
     * Per-language account state for the mode-A credential blocks: saved
     * store-view scoped credentials of a representative store view per
     * detected language.
     *
     * @return array<int, array{language: string, storeId: int|null, subdomain: string,
     *     username: string, hasPassword: bool}>
     */
    public function getMultilingualAccounts(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $accounts = [];
        foreach ($this->accountResolver->detectedLanguages($websiteId) as $language) {
            $storeId = $this->accountResolver->storeIdForAccountKey($language, $websiteId);
            $accounts[] = [
                'language' => $language,
                'storeId' => $storeId,
                'subdomain' => $this->config->getSubdomain($storeId),
                'username' => $this->config->getUsername($storeId),
                'hasPassword' => $this->config->getPassword($storeId) !== '',
            ];
        }

        return $accounts;
    }

    /**
     * Saved workflow-mapping rows of the global scope (website 0 — the scope
     * the Settings/wizard mapping editor manages), keyed "trigger|language"
     * for template prefill.
     *
     * @return array<string, array{workflowId: int, isDefaultFallback: bool}>
     */
    public function getSavedMappings(): array
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['eq' => 0])
            ->addFieldToFilter('trigger_type', ['in' => Trigger::ALL]);

        $rows = [];
        /** @var Mapping $mapping */
        foreach ($collection as $mapping) {
            $rows[$mapping->getData('trigger_type') . '|' . $mapping->getData('language')] = [
                'workflowId' => $mapping->getWorkflowId(),
                'isDefaultFallback' => (bool)$mapping->getData('is_default_fallback'),
            ];
        }

        return $rows;
    }
}
