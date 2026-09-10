<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\CacheInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client as EngineClient;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Shopper profiling consent (opt-out model, default on) — a separate lawful
 * axis from marketing consent (a marketing unsubscribe is not an Art. 21
 * profiling objection).
 *
 * State of record lives on the Smaily contact (smaily_rec_profiling 0/1 +
 * smaily_rec_profiling_ts); changes also fire the engine's opt-out endpoint
 * (contract §10). Reads are cached for a day and FAIL OPEN on transport
 * errors (matching the Woo ProfilingConsent posture).
 */
class ProfilingConsent
{
    private const CACHE_PREFIX = 'smaily_profiling_';
    private const CACHE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly EngineClient $engineClient,
        private readonly Settings $engineSettings,
        private readonly CacheInterface $cache,
        private readonly Logger $logger
    ) {
    }

    /**
     * Whether profiling is allowed for the contact (default true).
     */
    public function isAllowed(string $email, int|string|null $storeId = null): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return true;
        }

        $cacheKey = self::CACHE_PREFIX . sha1($email);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $cached === '1';
        }

        $allowed = true;
        try {
            $contact = $this->smailyClientProvider->forStore($storeId)
                ->get(SmailyClient::ENDPOINT_CONTACT, ['email' => $email]);
            $value = $contact['smaily_rec_profiling'] ?? ($contact[0]['smaily_rec_profiling'] ?? null);
            if ($value !== null && (string)$value === '0') {
                $allowed = false;
            }
        } catch (\Smaily\Connect\Model\Client\Exception\ApiException $exception) {
            if ($exception->getSmailyCode() !== \Smaily\Connect\Model\Client\Exception\ApiException::CODE_EMAIL_NOT_FOUND) {
                $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
            }
            // Unknown contact = default allowed; not an error condition.
        } catch (SmailyClientException $exception) {
            // Fail open: an unreachable API must not break the storefront.
            $this->logger->debug('Profiling consent read failed', ['error' => $exception->getMessage()]);
        }

        $this->cache->save($allowed ? '1' : '0', $cacheKey, [], self::CACHE_TTL_SECONDS);

        return $allowed;
    }

    /**
     * Record the shopper's choice: Smaily contact fields + engine opt-out.
     */
    public function setAllowed(string $email, bool $allowed, int|string|null $storeId = null): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        try {
            $this->smailyClientProvider->forStore($storeId)->post(SmailyClient::ENDPOINT_CONTACT, [[
                'email' => $email,
                'smaily_rec_profiling' => $allowed ? 1 : 0,
                'smaily_rec_profiling_ts' => $timestamp,
            ]]);
        } catch (SmailyClientException $exception) {
            $this->logger->error('Profiling consent write to Smaily failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        if ($this->engineSettings->isSendingAllowed()) {
            try {
                $this->engineClient->customerOptOut($email, !$allowed, 'user_preference', $timestamp);
            } catch (EngineException $exception) {
                $this->logger->error('Engine profiling opt-out failed', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->cache->save($allowed ? '1' : '0', self::CACHE_PREFIX . sha1($email), [], self::CACHE_TTL_SECONDS);
    }
}
