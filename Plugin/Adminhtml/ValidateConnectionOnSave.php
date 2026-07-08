<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Adminhtml;

use Magento\Config\Model\Config as SystemConfig;
use Magento\Framework\Exception\ValidatorException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Validates Smaily API credentials when the connection group is saved.
 *
 * Rejected credentials block the save with an admin-visible error; transient
 * network failures do not block the save (they are logged instead), so an
 * unreachable API cannot lock the merchant out of their own configuration.
 */
class ValidateConnectionOnSave
{
    public function __construct(
        private readonly SmailyClientFactory $clientFactory,
        private readonly Config $config,
        private readonly SubdomainNormalizer $normalizer,
        private readonly Logger $logger
    ) {
    }

    /**
     * @throws ValidatorException
     */
    public function beforeSave(SystemConfig $subject): void
    {
        if ($subject->getSection() !== 'smaily_connect') {
            return;
        }

        $groups = (array)$subject->getData('groups');
        $fields = $groups['connection']['fields'] ?? null;
        if (!is_array($fields)) {
            return;
        }

        $storeId = $subject->getStore() !== '' ? $subject->getStore() : null;

        $subdomain = $this->normalizer->normalize(
            $this->resolveValue($fields, 'subdomain') ?? $this->config->getSubdomain($storeId)
        );
        $username = trim($this->resolveValue($fields, 'username') ?? $this->config->getUsername($storeId));
        $password = $this->resolveValue($fields, 'password');
        if ($password === null || preg_match('/^\*+$/', $password)) {
            // Obscured/unchanged value: fall back to the stored password.
            $password = $this->config->getPassword($storeId);
        }

        if ($subdomain === '' || $username === '' || $password === '') {
            return;
        }

        $client = $this->clientFactory->create([
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
        ]);

        try {
            $client->validateCredentials();
        } catch (AuthenticationException) {
            throw new ValidatorException(
                __('Smaily rejected the API credentials. Please check the subdomain, username and password.')
            );
        } catch (SmailyClientException $exception) {
            $this->logger->info('Skipped credential validation, Smaily API unreachable', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolveValue(array $fields, string $field): ?string
    {
        if (!isset($fields[$field]['value'])) {
            return null;
        }

        return (string)$fields[$field]['value'];
    }
}
