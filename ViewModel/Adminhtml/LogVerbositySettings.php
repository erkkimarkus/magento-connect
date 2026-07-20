<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\LogVerbosity;

/**
 * Log-page verbosity control data (target-spec §2.4/§4.2): the current
 * `logging/verbosity` value plus its option list, installation-wide
 * (default scope only, no website/store meaning).
 */
class LogVerbositySettings implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly LogVerbosity $verbositySource
    ) {
    }

    public function getVerbosity(): string
    {
        return $this->config->getLogVerbosity();
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getOptions(): array
    {
        return $this->verbositySource->toOptionArray();
    }
}
