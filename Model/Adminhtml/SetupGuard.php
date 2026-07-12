<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Notification\NotifierInterface;
use Smaily\Connect\Model\ModuleVersion;

/**
 * Wizard-first gating for the Smaily Connect admin pages:
 * - On a fresh install (setup not completed) every Smaily Connect page
 *   redirects to the setup wizard.
 * - After a MAJOR version upgrade a one-time "review what's new" notice is
 *   posted instead of a hard redirect — the store keeps running on the
 *   migrated settings.
 */
class SetupGuard
{
    public const XML_PATH_LAST_SEEN_VERSION = 'smaily_connect/internal/last_seen_version';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly NotifierInterface $notifier,
        private readonly ModuleVersion $moduleVersion
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->scopeConfig->isSetFlag(WizardStepSaver::XML_PATH_SETUP_COMPLETED);
    }

    /**
     * Record the running module version; on a major-version jump post the
     * one-time upgrade notice. Called from every Smaily Connect admin page.
     */
    public function checkVersionChange(): void
    {
        $current = $this->moduleVersion->current();
        if ($current === '') {
            return;
        }

        $lastSeen = (string)$this->scopeConfig->getValue(self::XML_PATH_LAST_SEEN_VERSION);
        if ($lastSeen === $current) {
            return;
        }

        if ($lastSeen !== ''
            && $this->moduleVersion->major($lastSeen) !== $this->moduleVersion->major($current)
        ) {
            $this->notifier->addNotice(
                (string)__('Smaily Connect was upgraded to version %1', $current),
                (string)__(
                    'A major upgrade can bring new features and changed screens.'
                    . ' Review the settings under Marketing > Smaily Connect > Settings,'
                    . ' or re-run the setup wizard — your saved configuration is untouched either way.'
                )
            );
        }

        $this->configWriter->save(self::XML_PATH_LAST_SEEN_VERSION, $current);
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
