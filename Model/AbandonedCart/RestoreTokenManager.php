<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * Deterministic HMAC tokens for abandoned cart restore links, keyed with the
 * installation crypt key — the link works without a session but cannot be
 * forged or enumerated.
 */
class RestoreTokenManager
{
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function generate(int $quoteId): string
    {
        return hash_hmac('sha256', 'smaily-cart-restore|' . $quoteId, $this->key());
    }

    public function validate(int $quoteId, string $token): bool
    {
        return $token !== '' && hash_equals($this->generate($quoteId), $token);
    }

    private function key(): string
    {
        $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        // Multiple keys are newline-separated after a key rotation; the
        // latest key signs new links (older links then expire, acceptable).
        $keys = array_values(array_filter(array_map('trim', explode("\n", $raw))));

        return $keys !== [] ? end($keys) : $raw;
    }
}
