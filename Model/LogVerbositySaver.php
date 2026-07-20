<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Persists the Log page's verbosity setting (default scope only — the field
 * is installation-wide) and invalidates the config cache, keeping the
 * controller a thin orchestrator like the module's other config-writing
 * endpoints (SaveStep -> WizardStepSaver).
 */
class LogVerbositySaver
{
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    public function save(string $verbosity): void
    {
        $this->configWriter->save(Config::XML_PATH_LOG_VERBOSITY, $verbosity);
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
