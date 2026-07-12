<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * The canonical inventory of this module's own `core_config_data` paths.
 *
 * It backs two safety layers of the Settings-page override awareness feature
 * (PRO-1274): the detector only ever looks for shadowing rows under the
 * overridable subset, and the clear-override controller refuses to delete any
 * path that is not on the module's allowlist — so the "Use Default" affordance
 * can never touch unrelated store configuration.
 */
class ModuleConfigPaths
{
    /**
     * Every config path the module owns. Derived from the typed Config /
     * EngineSettings constants so it cannot drift from the real reads/writes.
     *
     * @var string[]
     */
    private const OWN_PATHS = [
        Config::XML_PATH_SUBDOMAIN,
        Config::XML_PATH_USERNAME,
        Config::XML_PATH_PASSWORD,
        Config::XML_PATH_MULTILINGUAL_MODE,
        Config::XML_PATH_FALLBACK_LANGUAGE,
        Config::XML_PATH_SYNC_ENABLED,
        Config::XML_PATH_SYNC_MODE,
        Config::XML_PATH_SYNC_FIELDS,
        Config::XML_PATH_INCLUDE_GUESTS,
        Config::XML_PATH_AUTOMATION_FORCE_OPT_IN,
        Config::XML_PATH_CHECKOUT_OPTIN_ENABLED,
        Config::XML_PATH_SUPPRESS_OPTIN_EMAILS,
        Config::XML_PATH_WELCOME_ENABLED,
        Config::XML_PATH_WELCOME_WORKFLOW,
        Config::XML_PATH_FIRST_ORDER_ENABLED,
        Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        Config::XML_PATH_ABANDONED_ENABLED,
        Config::XML_PATH_ABANDONED_WORKFLOW,
        Config::XML_PATH_ABANDONED_CUTOFF,
        Config::XML_PATH_ABANDONED_FIELDS,
        Config::XML_PATH_RSS_ENABLED,
        Config::XML_PATH_LOG_VERBOSITY,
        EngineSettings::XML_PATH_SYNC_CATALOG,
        EngineSettings::XML_PATH_SYNC_CUSTOMERS,
        EngineSettings::XML_PATH_SYNC_ORDERS,
        EngineSettings::XML_PATH_BROWSE_TRACKING,
    ];

    /**
     * The subset that can carry a website / store-view override shadowing the
     * default value the Settings page edits (i.e. showInWebsite or showInStore
     * in system.xml). The Campaign Intelligence and logging paths are
     * default-scope only, so they can never be shadowed.
     *
     * @var string[]
     */
    private const OVERRIDABLE_PATHS = [
        Config::XML_PATH_SUBDOMAIN,
        Config::XML_PATH_USERNAME,
        Config::XML_PATH_PASSWORD,
        Config::XML_PATH_MULTILINGUAL_MODE,
        Config::XML_PATH_SYNC_ENABLED,
        Config::XML_PATH_SYNC_MODE,
        Config::XML_PATH_SYNC_FIELDS,
        Config::XML_PATH_INCLUDE_GUESTS,
        Config::XML_PATH_AUTOMATION_FORCE_OPT_IN,
        Config::XML_PATH_CHECKOUT_OPTIN_ENABLED,
        Config::XML_PATH_SUPPRESS_OPTIN_EMAILS,
        Config::XML_PATH_WELCOME_ENABLED,
        Config::XML_PATH_WELCOME_WORKFLOW,
        Config::XML_PATH_FIRST_ORDER_ENABLED,
        Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        Config::XML_PATH_ABANDONED_ENABLED,
        Config::XML_PATH_ABANDONED_WORKFLOW,
        Config::XML_PATH_ABANDONED_CUTOFF,
        Config::XML_PATH_ABANDONED_FIELDS,
        Config::XML_PATH_RSS_ENABLED,
    ];

    /**
     * Whether a config path belongs to this module — the delete allowlist the
     * clear-override action validates against before removing any row.
     */
    public function isAllowed(string $path): bool
    {
        return in_array($path, self::OWN_PATHS, true);
    }

    /**
     * Paths the detector scans for shadowing website / store-view rows.
     *
     * @return string[]
     */
    public function overridablePaths(): array
    {
        return self::OVERRIDABLE_PATHS;
    }
}
