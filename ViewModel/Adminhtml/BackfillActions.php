<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Available import actions for the Historical Import admin page.
 */
class BackfillActions implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Settings $engineSettings
    ) {
    }

    /**
     * @return array<string, string> job type => button label
     */
    public function getAvailableTypes(): array
    {
        $types = [];
        if ($this->config->isConnected()) {
            $types[Job::TYPE_CONTACTS] = (string)__('Import Subscribers to Smaily');
        }
        if ($this->engineSettings->isConnected()) {
            $types[Job::TYPE_CATALOG] = (string)__('Import Catalog to Intelligence');
            $types[Job::TYPE_CUSTOMERS] = (string)__('Import Customers to Intelligence');
            $types[Job::TYPE_ORDERS] = (string)__('Import Orders to Intelligence');
        }

        return $types;
    }
}
