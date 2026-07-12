<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\OverrideDetector;

/**
 * Feeds the Settings-page override awareness layer (PRO-1274): maps each
 * Settings field to its config path and, where a website / store-view row
 * shadows the default the page edits, to the shadowing scope(s). The template
 * renders an "Overridden for X" indicator with a "Use Default" affordance next
 * to exactly those fields.
 */
class ConfigOverrides implements ArgumentInterface
{
    /**
     * Settings-page field anchor (a DOM selector the indicator attaches after)
     * → the module config path the field edits. Only fields that exist on the
     * Settings surface are listed; the Campaign Intelligence tab is default
     * scope only, so it carries no overrides.
     *
     * @var array<string, string>
     */
    private const FIELD_ANCHORS = [
        '#smaily-w-subdomain' => Config::XML_PATH_SUBDOMAIN,
        '#smaily-w-username' => Config::XML_PATH_USERNAME,
        '#smaily-w-password' => Config::XML_PATH_PASSWORD,
        '#smaily-ml-modes' => Config::XML_PATH_MULTILINGUAL_MODE,
        '#smaily-w-sync-enabled' => Config::XML_PATH_SYNC_ENABLED,
        '#smaily-w-mode-group' => Config::XML_PATH_SYNC_MODE,
        '#smaily-w-fields' => Config::XML_PATH_SYNC_FIELDS,
        '#smaily-w-checkout-optin' => Config::XML_PATH_CHECKOUT_OPTIN_ENABLED,
        '#smaily-w-suppress' => Config::XML_PATH_SUPPRESS_OPTIN_EMAILS,
        '#smaily-w-welcome-enabled' => Config::XML_PATH_WELCOME_ENABLED,
        '#smaily-w-welcome-workflow' => Config::XML_PATH_WELCOME_WORKFLOW,
        '#smaily-w-firstorder-enabled' => Config::XML_PATH_FIRST_ORDER_ENABLED,
        '#smaily-w-firstorder-workflow' => Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        '#smaily-w-abandoned-enabled' => Config::XML_PATH_ABANDONED_ENABLED,
        '#smaily-w-abandoned-workflow' => Config::XML_PATH_ABANDONED_WORKFLOW,
        '#smaily-w-cutoff' => Config::XML_PATH_ABANDONED_CUTOFF,
        '#smaily-w-rss-enabled' => Config::XML_PATH_RSS_ENABLED,
    ];

    public function __construct(
        private readonly OverrideDetector $detector,
        private readonly Json $serializer
    ) {
    }

    /**
     * JSON keyed by field anchor for every Settings field whose default value
     * is shadowed, each with the config path and the shadowing scope(s):
     *
     *   {"#smaily-w-subdomain": {"path": "...", "overrides": [
     *       {"scope": "websites", "scopeId": 1, "label": "Main Website"}]}}
     *
     * Empty object when nothing is overridden.
     */
    public function getFieldOverridesJson(): string
    {
        $overrides = $this->detector->detect();
        if ($overrides === []) {
            return '{}';
        }

        $map = [];
        foreach (self::FIELD_ANCHORS as $anchor => $path) {
            if (!empty($overrides[$path])) {
                $map[$anchor] = [
                    'path' => $path,
                    'overrides' => $overrides[$path],
                ];
            }
        }

        return $this->serializer->serialize($map);
    }
}
