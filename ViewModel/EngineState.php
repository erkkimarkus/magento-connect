<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Storefront view model for the attribution and browse-tracking scripts.
 */
class EngineState implements ArgumentInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AttributionManager $attributionManager,
        private readonly CookieHelper $cookieHelper,
        private readonly UrlInterface $urlBuilder,
        private readonly Json $serializer
    ) {
    }

    public function isAttributionActive(): bool
    {
        return $this->settings->isConnected();
    }

    public function isBrowseTrackingActive(): bool
    {
        return $this->settings->isBrowseTrackingEnabled();
    }

    public function getAttributionConfigJson(): string
    {
        return $this->serializer->serialize($this->attributionManager->getClientConfig());
    }

    public function getTrackerConfigJson(): string
    {
        return $this->serializer->serialize([
            'relayUrl' => $this->urlBuilder->getUrl('smaily/relay'),
            'consentRequired' => $this->cookieHelper->isCookieRestrictionModeEnabled(),
            'attribution' => $this->attributionManager->getClientConfig(),
        ]);
    }
}
