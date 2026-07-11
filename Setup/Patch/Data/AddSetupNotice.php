<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Points the merchant at the setup wizard after installation/upgrade.
 */
class AddSetupNotice implements DataPatchInterface
{
    public function __construct(
        private readonly NotifierInterface $notifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [MigrateLegacyConfig::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $this->notifier->addNotice(
            (string)__('Smaily Connect is ready to set up'),
            (string)__(
                'Open Marketing > Smaily Connect > Setup Wizard to connect your Smaily account'
                . ' in a few guided steps. Existing settings from an earlier version were migrated automatically.'
                . ' The full user guide is linked below under Read Details.'
            ),
            // Hosted on GitHub for now — update once a hosted docs site exists.
            'https://github.com/erkkimarkus/magento-connect/blob/v3/docs/USER_GUIDE.md'
        );

        return $this;
    }
}
