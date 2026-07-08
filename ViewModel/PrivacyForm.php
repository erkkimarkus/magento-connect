<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * View model for the customer personalization preference form.
 */
class PrivacyForm implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ProfilingConsent $profilingConsent
    ) {
    }

    public function isProfilingAllowed(): bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return true;
        }
        $customer = $this->customerSession->getCustomerData();

        return $this->profilingConsent->isAllowed(
            (string)$customer->getEmail(),
            $customer->getStoreId()
        );
    }
}
