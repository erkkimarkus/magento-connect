<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ModuleInfo;

/**
 * Smaily marketing API client.
 *
 * Wire facts (shared with the WooCommerce and Shopify plugins):
 * - Base URL https://{subdomain}.sendsmaily.net/api/{endpoint}.php
 * - HTTP Basic authentication
 * - Success envelope: HTTP 200 with {"code": 101}; 203 invalid data,
 *   206 email not found. List endpoints return plain arrays.
 *
 * Instances are scope-bound (credentials are fixed at construction); use
 * SmailyClientProvider to obtain a client for a store view.
 */
class SmailyClient
{
    public const ENDPOINT_CONTACT = 'contact';
    public const ENDPOINT_AUTORESPONDER = 'autoresponder';
    public const ENDPOINT_HISTORY = 'history';

    private const TIMEOUT_SECONDS = 30;
    private const CONNECT_TIMEOUT_SECONDS = 10;

    private ?HttpClient $httpClient = null;

    public function __construct(
        private readonly HttpClientFactory $httpClientFactory,
        private readonly Logger $logger,
        private readonly string $subdomain,
        private readonly string $username,
        private readonly string $password
    ) {
    }

    /**
     * Perform a GET request against an API endpoint.
     *
     * @param array<string, mixed> $query
     * @return array<int|string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, [RequestOptions::QUERY => $query]);
    }

    /**
     * Perform a POST request with a JSON payload against an API endpoint.
     *
     * @param array<int|string, mixed> $payload
     * @return array<int|string, mixed>
     * @throws ApiException on a non-101 response envelope
     */
    public function post(string $endpoint, array $payload): array
    {
        return $this->request('POST', $endpoint, [RequestOptions::JSON => $payload]);
    }

    /**
     * List active automation workflows.
     *
     * Uses GET autoresponder.php?status=ACTIVE (not the legacy workflows.php).
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function getAutomationWorkflows(): array
    {
        $workflows = [];
        foreach ($this->get(self::ENDPOINT_AUTORESPONDER, ['status' => 'ACTIVE']) as $workflow) {
            if (is_array($workflow) && isset($workflow['id'])) {
                $workflows[] = [
                    'id' => (int)$workflow['id'],
                    'title' => (string)($workflow['title'] ?? $workflow['name'] ?? $workflow['id']),
                ];
            }
        }

        return $workflows;
    }

    /**
     * Validate credentials with a lightweight API call.
     *
     * @throws AuthenticationException when credentials are rejected
     * @throws TransportException on network failure
     */
    public function validateCredentials(): void
    {
        $this->get(self::ENDPOINT_AUTORESPONDER, ['status' => 'ACTIVE']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $endpoint, array $options): array
    {
        $uri = sprintf('api/%s.php', $endpoint);
        $this->logger->debug('Smaily API request', [
            'method' => $method,
            'endpoint' => $uri,
            'options' => $this->redact($options),
        ]);

        try {
            $response = $this->getHttpClient()->request($method, $uri, $options);
        } catch (BadResponseException $exception) {
            $status = $exception->getResponse()->getStatusCode();
            $this->logger->error('Smaily API HTTP error', [
                'method' => $method,
                'endpoint' => $uri,
                'status' => $status,
            ]);
            if (in_array($status, [401, 403], true)) {
                throw new AuthenticationException('Smaily API credentials were rejected', $status, $exception);
            }
            throw new TransportException(
                sprintf('Smaily API request failed with HTTP %d', $status),
                $status,
                $exception
            );
        } catch (GuzzleException $exception) {
            $this->logger->error('Smaily API transport error', [
                'method' => $method,
                'endpoint' => $uri,
                'error' => $exception->getMessage(),
            ]);
            throw new TransportException('Smaily API request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TransportException('Smaily API returned a malformed response body');
        }

        // Summarized on purpose: full bodies would put contact PII in logs.
        $this->logger->debug('Smaily API response', [
            'endpoint' => $uri,
            'code' => $decoded['code'] ?? null,
            'rows' => array_is_list($decoded) ? count($decoded) : 1,
        ]);

        if (isset($decoded['code']) && (int)$decoded['code'] !== ApiException::CODE_SUCCESS) {
            throw new ApiException(
                sprintf(
                    'Smaily API returned code %d: %s',
                    (int)$decoded['code'],
                    (string)($decoded['message'] ?? 'unknown error')
                ),
                (int)$decoded['code'],
                $decoded
            );
        }

        return $decoded;
    }

    private function getHttpClient(): HttpClient
    {
        if ($this->httpClient === null) {
            $this->httpClient = $this->httpClientFactory->create([
                'base_uri' => sprintf('https://%s.sendsmaily.net/', $this->subdomain),
                RequestOptions::AUTH => [$this->username, $this->password],
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                RequestOptions::HEADERS => [
                    'User-Agent' => ModuleInfo::USER_AGENT,
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Strip credentials and summarize PII-bearing payloads for debug logs.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function redact(array $options): array
    {
        unset($options[RequestOptions::AUTH]);

        if (isset($options[RequestOptions::JSON]) && is_array($options[RequestOptions::JSON])) {
            $body = $options[RequestOptions::JSON];
            $options[RequestOptions::JSON] = [
                'items' => array_is_list($body) ? count($body) : 1,
                'keys' => array_slice(array_keys(array_is_list($body) ? ($body[0] ?? []) : $body), 0, 20),
            ];
        }
        if (isset($options[RequestOptions::QUERY]['email'])) {
            $options[RequestOptions::QUERY]['email'] = '[redacted]';
        }

        return $options;
    }
}
