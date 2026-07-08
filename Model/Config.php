<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed accessors for Smaily Connect configuration.
 *
 * Connection credentials read at store-view scope so that per-language
 * Smaily accounts (multilingual mode A) can override them per store view;
 * store views inherit website and default scope values as usual.
 */
class Config
{
    public const XML_PATH_SUBDOMAIN = 'smaily_connect/connection/subdomain';
    public const XML_PATH_USERNAME = 'smaily_connect/connection/username';
    public const XML_PATH_PASSWORD = 'smaily_connect/connection/password';
    public const XML_PATH_LOG_VERBOSITY = 'smaily_connect/logging/verbosity';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Get the Smaily account subdomain.
     *
     * @param int|string|null $storeId
     */
    public function getSubdomain(int|string|null $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_SUBDOMAIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get the Smaily API username.
     *
     * @param int|string|null $storeId
     */
    public function getUsername(int|string|null $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_USERNAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Get the decrypted Smaily API password.
     *
     * @param int|string|null $storeId
     */
    public function getPassword(int|string|null $storeId = null): string
    {
        $encrypted = (string)$this->scopeConfig->getValue(
            self::XML_PATH_PASSWORD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $encrypted === '' ? '' : $this->encryptor->decrypt($encrypted);
    }

    /**
     * Whether a complete set of API credentials is configured for the scope.
     *
     * @param int|string|null $storeId
     */
    public function isConnected(int|string|null $storeId = null): bool
    {
        return $this->getSubdomain($storeId) !== ''
            && $this->getUsername($storeId) !== ''
            && $this->getPassword($storeId) !== '';
    }

    /**
     * Get configured log verbosity (error|info|debug).
     */
    public function getLogVerbosity(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LOG_VERBOSITY);
    }
}
