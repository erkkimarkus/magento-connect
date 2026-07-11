<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;

class ClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @var int[] */
    private array $sleeps = [];

    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('getApiKey')->willReturn('sk_test_key');
    }

    public function testIngestSendsWrapperAndBearerAuth(): void
    {
        $this->settings->method('getEndpoint')->with('ingest_orders')
            ->willReturn('https://engine.example/api/v1/ingest/orders');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"processed":1,"deduplicated":0,"errors":[]}'),
        ]);

        $response = $client->ingest(Client::DOMAIN_ORDERS, [['external_order_id' => '100000001']]);

        self::assertTrue($response['ok']);
        $request = $this->history[0]['request'];
        self::assertSame('Bearer sk_test_key', $request->getHeaderLine('Authorization'));
        self::assertStringContainsString('"orders":[{"external_order_id":"100000001"}]', (string)$request->getBody());
    }

    public function testRateLimitRetriesWithRetryAfterFromBody(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $client = $this->createClient([
            new Response(429, [], '{"error":"rate_limit_exceeded","retry_after_seconds":7}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $response = $client->ping();

        self::assertTrue($response['ok']);
        self::assertSame([7], $this->sleeps);
        self::assertCount(2, $this->history);
    }

    public function testServerErrorsRetryThenThrowTransportException(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $responses = array_fill(0, 6, new Response(503, [], '{"error":"unavailable"}'));
        $client = $this->createClient($responses);

        $this->expectException(EngineTransportException::class);
        try {
            $client->ping();
        } finally {
            self::assertSame([1, 2, 4, 8, 16], $this->sleeps);
        }
    }

    public function testClientErrorNeverRetries(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/catalog');
        $client = $this->createClient([
            new Response(400, [], '{"error":"validation_failed","message":"bad wrapper"}'),
        ]);

        try {
            $client->ingest(Client::DOMAIN_CATALOG, []);
            self::fail('Expected EngineRequestException');
        } catch (EngineRequestException $exception) {
            self::assertSame(400, $exception->getHttpStatus());
            self::assertSame('validation_failed', $exception->getErrorBody()['error']);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testCatalogRemoveSendsProductIdsWrapperToMappedEndpoint(): void
    {
        $this->settings->method('getEndpoint')->with('ingest_catalog_remove')
            ->willReturn('https://engine.example/api/v1/ingest/catalog/remove');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"removed_products":1,"rows_tombstoned":2,"not_found":[]}'),
        ]);

        $response = $client->catalogRemove(['7620134', '7620135']);

        self::assertSame(1, $response['removed_products']);
        $request = $this->history[0]['request'];
        self::assertSame('https://engine.example/api/v1/ingest/catalog/remove', (string)$request->getUri());
        self::assertSame('Bearer sk_test_key', $request->getHeaderLine('Authorization'));
        self::assertSame('{"product_ids":["7620134","7620135"]}', (string)$request->getBody());
    }

    public function testCatalogRemoveFallsBackToHardcodedPathWhenMapLacksTheKey(): void
    {
        // Tenants exchanged before contract v1.4.0 have no
        // ingest_catalog_remove in their endpoints map (§1 "map age") — the
        // hardcoded §3b path is the load-bearing fallback (mirrors Woo).
        $this->settings->method('getEndpoint')->willReturn(null);
        $this->settings->method('getEngineBaseUrl')->willReturn('https://engine.example/');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"removed_products":0,"rows_tombstoned":0,"not_found":["9"]}'),
        ]);

        $response = $client->catalogRemove(['9']);

        self::assertSame(['9'], $response['not_found']);
        self::assertSame(
            'https://engine.example/api/v1/ingest/catalog/remove',
            (string)$this->history[0]['request']->getUri()
        );
    }

    public function testCustomerDeleteSubstitutesEmailPlaceholderAndTreats404AsSuccess(): void
    {
        $this->settings->method('getEndpoint')->with('customer_delete')
            ->willReturn('https://engine.example/api/v1/customer/{email}');
        $client = $this->createClient([new Response(404, [], '{"error":"not_found"}')]);

        $response = $client->customerDelete('Kati Käbi@example.com');

        self::assertTrue($response['already_deleted']);
        $path = (string)$this->history[0]['request']->getUri();
        self::assertStringNotContainsString('{email}', $path);
        self::assertStringContainsString('kati%20k%C3%A4bi%40example.com', $path);
    }

    public function testSetupExchangeParsesFullUrlInput(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        $response = $client->setupExchange('https://engine.example/setup/tok_abc123');

        self::assertSame('t1', $response['tenant_id']);
        $request = $this->history[0]['request'];
        self::assertSame('https://engine.example/api/setup/exchange', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('tok_abc123', $body['setup_token']);
        self::assertSame('magento', $body['plugin_info']['platform']);
        // Setup exchange is the only unauthenticated endpoint.
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    /**
     * @param array<int, Response> $responses
     */
    private function createClient(array $responses): Client
    {
        $this->history = [];
        $this->sleeps = [];

        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack) {
                $config['handler'] = $handlerStack;
                return new HttpClient($config);
            }
        );

        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.8');

        return new Client(
            $this->settings,
            $factory,
            $productMetadata,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Logger::class),
            $sleeper
        );
    }
}
