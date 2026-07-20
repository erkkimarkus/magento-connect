<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The website the admin surfaces (wizard + Settings) currently target. No
 * website chooser exists yet (Phase 2, RFC_MULTI_WEBSITE.md §2) — every
 * surface targets the installation's default website, which for a
 * single-website install is its only website. Phase 2 swaps this single
 * resolution point for the chooser's selection.
 */
class WebsiteContext
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getWebsiteId(): int
    {
        try {
            return (int)($this->storeManager->getDefaultStoreView()?->getWebsiteId() ?? 0);
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
