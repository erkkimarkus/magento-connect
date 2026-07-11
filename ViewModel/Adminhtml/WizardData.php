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
use Smaily\Connect\Model\Adminhtml\AccountResolver;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * Boot data for the native setup wizard: saved settings for prefill, store
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
        private readonly Json $serializer
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
                'multilingualMode' => $this->config->getMultilingualMode() ?: 'single',
            ],
            'languages' => $this->accountResolver->detectedLanguages(),
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
}
