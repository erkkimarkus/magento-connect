<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ModuleInfo;

/**
 * Campaign Intelligence engine client (RECENGINE_API_CONTRACT.md v1.2).
 *
 * - Bearer auth; the API key never reaches client-side code.
 * - Endpoint URLs come from the stored endpoints map, never concatenated.
 * - Retry policy per contract: exponential backoff 1/2/4/8/16s (max 5) on
 *   429 (honouring retry_after_seconds from the body) and 5xx; other 4xx
 *   never retry.
 * - D6 ingest responses are per-item: a 200 is never all-or-nothing.
 */
class Client
{
    public const DOMAIN_CATALOG = 'catalog';
    public const DOMAIN_CUSTOMERS = 'customers';
    public const DOMAIN_ORDERS = 'orders';
    public const DOMAIN_BROWSE = 'browse';

    public const DOMAIN_WRAPPERS = [
        self::DOMAIN_CATALOG => 'products',
        self::DOMAIN_CUSTOMERS => 'customers',
        self::DOMAIN_ORDERS => 'orders',
        self::DOMAIN_BROWSE => 'events',
    ];

    public const DOMAIN_BATCH_LIMITS = [
        self::DOMAIN_CATALOG => 100,
        self::DOMAIN_CUSTOMERS => 100,
        self::DOMAIN_ORDERS => 50,
        self::DOMAIN_BROWSE => 100,
    ];

    public const DEFAULT_SETUP_BASE_URL = 'https://intelligence.smaily.com';
    public const COMPATIBLE_ENGINE_MAJOR = 1;

    private const RETRY_DELAYS_SECONDS = [1, 2, 4, 8, 16];
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClientFactory $httpClientFactory,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly Logger $logger,
        private readonly SleeperInterface $sleeper
    ) {
    }

    /**
     * Exchange a one-time setup token (or full setup URL) for tenant
     * credentials. The caller persists the response via Settings.
     *
     * @return array<string, mixed>
     */
    public function setupExchange(string $setupInput): array
    {
        [$baseUrl, $token] = $this->parseSetupInput($setupInput);
        if ($token === '') {
            throw new EngineRequestException('Setup token is empty or unrecognized', 400);
        }

        return $this->request('POST', $baseUrl . '/api/setup/exchange', [
            'setup_token' => $token,
            'plugin_info' => $this->pluginInfo(),
        ], false);
    }

    /**
     * Health/tenant check.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->request('GET', $this->endpoint('ingest_ping'), null);
    }

    /**
     * Send one ingest batch. Returns the D6 response
     * {ok, processed, deduplicated, errors[]}.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function ingest(string $domain, array $items): array
    {
        $wrapper = self::DOMAIN_WRAPPERS[$domain] ?? null;
        if ($wrapper === null) {
            throw new EngineRequestException(sprintf('Unknown ingest domain "%s"', $domain), 400);
        }

        return $this->request(
            'POST',
            $this->endpoint('ingest_' . $domain),
            [$wrapper => array_values($items)]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function identityMerge(array $payload): array
    {
        return $this->request('POST', $this->endpoint('identity_merge'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function customerExport(string $email): array
    {
        return $this->request('GET', $this->customerEndpoint('customer_export', $email), null);
    }

    /**
     * Idempotent erase: a 404 means the customer is already gone.
     *
     * @return array<string, mixed>
     */
    public function customerDelete(string $email): array
    {
        try {
            return $this->request('DELETE', $this->customerEndpoint('customer_delete', $email), [
                'confirm' => true,
                'reason' => 'user_request',
            ]);
        } catch (EngineRequestException $exception) {
            if ($exception->getHttpStatus() === 404) {
                return ['ok' => true, 'already_deleted' => true];
            }
            throw $exception;
        }
    }

    /**
     * Profiling opt-out/opt-in (contract §10). The opt-in reversal carries
     * no opted_out_at; the reason vocabulary is §10's (user_preference),
     * not §9's delete reasons.
     *
     * @return array<string, mixed>
     */
    public function customerOptOut(string $email, bool $optOut, string $reason, string $timestamp): array
    {
        $body = [
            'opt_out' => $optOut,
            'reason' => $reason,
        ];
        if ($optOut) {
            $body['opted_out_at'] = $timestamp;
        }

        return $this->request('POST', $this->customerEndpoint('customer_opt_out', $email), $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function automationsCatalog(): array
    {
        return $this->request('GET', $this->endpoint('automations_catalog', '/api/v1/automations/catalog'), null);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutomationsConfig(): array
    {
        return $this->request('GET', $this->endpoint('automations_config', '/api/v1/automations/config'), null);
    }

    /**
     * All-or-nothing validation: a 422 means nothing was saved.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function putAutomationsConfig(array $rows): array
    {
        return $this->request(
            'PUT',
            $this->endpoint('automations_config', '/api/v1/automations/config'),
            ['configs' => array_values($rows)]
        );
    }

    /**
     * Resolve an endpoint from the stored map, with an optional fallback
     * path for keys added after the tenant's exchange (contract §1 map age).
     */
    private function endpoint(string $key, ?string $fallbackPath = null): string
    {
        $url = $this->settings->getEndpoint($key);
        if ($url !== null) {
            return $url;
        }

        $baseUrl = rtrim($this->settings->getEngineBaseUrl(), '/');
        if ($fallbackPath !== null && $baseUrl !== '') {
            return $baseUrl . $fallbackPath;
        }

        throw new EngineRequestException(sprintf('Engine endpoint "%s" is not available', $key), 400);
    }

    /**
     * Customer endpoints carry a literal {email} placeholder — substitute
     * with string replace, never printf-style (contract §1).
     */
    private function customerEndpoint(string $key, string $email): string
    {
        return str_replace('{email}', rawurlencode(strtolower(trim($email))), $this->endpoint($key));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $body, bool $authenticated = true): array
    {
        $options = [
            RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
            RequestOptions::HEADERS => [
                'User-Agent' => ModuleInfo::USER_AGENT,
                'Accept' => 'application/json',
            ],
        ];
        if ($authenticated) {
            $apiKey = $this->settings->getApiKey();
            if ($apiKey === '') {
                throw new EngineRequestException('Campaign Intelligence is not connected', 401);
            }
            $options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        $httpClient = $this->httpClientFactory->create();
        $attempt = 0;

        // Retry loop per contract: backoff 1/2/4/8/16s on 429 and 5xx.
        while (true) {
            try {
                $response = $httpClient->request($method, $url, $options);
                $this->checkEngineVersion($response->getHeaderLine('X-Engine-Version'));

                return $this->decode((string)$response->getBody());
            } catch (BadResponseException $exception) {
                $status = $exception->getResponse()->getStatusCode();
                $errorBody = $this->decodeSafely((string)$exception->getResponse()->getBody());

                if ($status !== 429 && $status < 500) {
                    throw new EngineRequestException(
                        sprintf(
                            'Engine request failed with HTTP %d: %s',
                            $status,
                            (string)($errorBody['message'] ?? $errorBody['error'] ?? 'unknown error')
                        ),
                        $status,
                        $errorBody
                    );
                }

                if ($attempt >= count(self::RETRY_DELAYS_SECONDS)) {
                    throw new EngineTransportException(
                        sprintf('Engine request failed with HTTP %d after retries', $status),
                        $status,
                        $exception
                    );
                }

                $delay = self::RETRY_DELAYS_SECONDS[$attempt];
                if ($status === 429 && isset($errorBody['retry_after_seconds'])) {
                    $delay = max($delay, (int)$errorBody['retry_after_seconds']);
                }
                $this->sleeper->sleep($delay);
                $attempt++;
            } catch (GuzzleException $exception) {
                if ($attempt >= count(self::RETRY_DELAYS_SECONDS)) {
                    throw new EngineTransportException(
                        'Engine request failed: ' . $exception->getMessage(),
                        0,
                        $exception
                    );
                }
                $this->sleeper->sleep(self::RETRY_DELAYS_SECONDS[$attempt]);
                $attempt++;
            }
        }
    }

    /**
     * Graceful degradation on version mismatch: warn, never refuse to
     * operate (data loss beats a compatibility notice).
     */
    private function checkEngineVersion(string $version): void
    {
        if ($version === '') {
            return;
        }
        $major = (int)strtok($version, '.');
        if ($major !== self::COMPATIBLE_ENGINE_MAJOR) {
            $this->logger->info('Engine version outside supported range', [
                'engine_version' => $version,
                'supported_major' => self::COMPATIBLE_ENGINE_MAJOR,
            ]);
        }
    }

    /**
     * @return array{string, string} base URL, token
     */
    private function parseSetupInput(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [self::DEFAULT_SETUP_BASE_URL, ''];
        }

        if (str_starts_with($input, 'http://') || str_starts_with($input, 'https://')) {
            $parts = parse_url($input);
            $host = (string)($parts['host'] ?? '');
            $path = (string)($parts['path'] ?? '');
            $segments = array_values(array_filter(explode('/', $path)));
            $token = $segments !== [] ? end($segments) : '';

            // Always https — the one-time token must never travel plaintext.
            return [$host !== '' ? 'https://' . $host : self::DEFAULT_SETUP_BASE_URL, $token];
        }

        return [self::DEFAULT_SETUP_BASE_URL, $input];
    }

    /**
     * @return array<string, mixed>
     */
    private function pluginInfo(): array
    {
        $magentoVersion = (string)$this->productMetadata->getVersion();

        return [
            'name' => 'smaily-connect-magento',
            'version' => ModuleInfo::VERSION,
            'platform' => 'magento',
            'platform_version' => $magentoVersion,
            'ecommerce_platform' => 'magento',
            'ecommerce_platform_version' => $magentoVersion,
            'site_url' => $this->siteUrl(),
        ];
    }

    private function siteUrl(): string
    {
        try {
            return (string)$this->storeManager->getDefaultStoreView()?->getBaseUrl();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new EngineTransportException('Engine returned a malformed response body');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSafely(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
