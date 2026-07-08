<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;

/**
 * Injects the newsletter opt-in checkbox into the Luma/Knockout checkout
 * payment step. Hyvä Checkout has its own integration surface and is
 * handled by a separate compatibility package.
 */
class AddNewsletterOptinToLayout
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        $websiteId = (int)$this->storeManager->getWebsite()->getId();
        if (!$this->config->isCheckoutOptinEnabled($websiteId)
            || !$this->config->isSyncEnabled($websiteId)
            || !$this->config->isConnected()
        ) {
            return $jsLayout;
        }

        $paymentChildren = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['billing-step']['children']['payment']['children'];
        if (!is_array($paymentChildren)) {
            return $jsLayout;
        }

        $paymentChildren['smaily-newsletter-optin'] = [
            'component' => 'Smaily_Connect/js/view/checkout/newsletter-optin',
            'displayArea' => 'beforePlaceOrder',
            'sortOrder' => 100,
        ];

        return $jsLayout;
    }
}
