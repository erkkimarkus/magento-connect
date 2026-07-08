<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;

/**
 * The merchant's lawful-basis preset and the policy it implies (per website).
 *
 * Mirrors the WooCommerce plugin's ContactSyncMode:
 * - legitimate_interest: all registered customers, no opt-in filter, no
 *   reconcile; automations honour unsubscribes unless the advanced toggle
 *   is on.
 * - consent (DEFAULT): only opted-in newsletter subscribers; bidirectional
 *   Smaily<->Magento reconcile; automations never re-subscribe.
 * - checkout_optin: no account sync; checkout checkbox only (guests
 *   intrinsically included).
 *
 * An unknown stored value falls back to the lawful-safe default so a bogus
 * mode never broadens the audience.
 */
class Mode
{
    public const DEFAULT_MODE = SyncMode::MODE_CONSENT;

    private const VALID_MODES = [
        SyncMode::MODE_CONSENT,
        SyncMode::MODE_LEGITIMATE_INTEREST,
        SyncMode::MODE_CHECKOUT_OPTIN,
    ];

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function mode(?int $websiteId = null): string
    {
        $raw = $this->config->getSyncMode($websiteId);

        return in_array($raw, self::VALID_MODES, true) ? $raw : self::DEFAULT_MODE;
    }

    /**
     * Registered customers are synced in every preset except checkout-only.
     */
    public function syncsAccounts(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) !== SyncMode::MODE_CHECKOUT_OPTIN;
    }

    /**
     * A contact must have opted in under consent + checkout; not under
     * legitimate interest.
     */
    public function requiresOptin(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) !== SyncMode::MODE_LEGITIMATE_INTEREST;
    }

    /**
     * Guest-order emails are synced — intrinsic to checkout-only, a toggle
     * (default off) otherwise.
     */
    public function includeGuests(?int $websiteId = null): bool
    {
        if ($this->mode($websiteId) === SyncMode::MODE_CHECKOUT_OPTIN) {
            return true;
        }

        return $this->config->includeGuests($websiteId);
    }

    /**
     * The store mirrors Smaily's unsubscribes back into Magento (consent only).
     */
    public function reconciles(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) === SyncMode::MODE_CONSENT;
    }

    /**
     * Whether automation triggers send force_opt_in=true. Consent + checkout:
     * always false (never re-subscribe). Legitimate interest: only with the
     * advanced toggle (GDPR Art. 21 honoured by default).
     */
    public function automationForceOptIn(?int $websiteId = null): bool
    {
        if ($this->mode($websiteId) !== SyncMode::MODE_LEGITIMATE_INTEREST) {
            return false;
        }

        return $this->config->automationForceOptIn($websiteId);
    }
}
