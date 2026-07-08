<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

class FlushIngestQueueTest extends TestCase
{
    private IngestQueue&MockObject $queue;
    private Client&MockObject $client;
    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(IngestQueue::class);
        $this->client = $this->createMock(Client::class);
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->queue->method('decodePayload')->willReturn(['sku' => 'X']);
    }

    public function testD6ErrorsMapBackOntoBatchRowsByIndex(): void
    {
        $first = $this->createEvent(11);
        $second = $this->createEvent(12);
        $third = $this->createEvent(13);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$first, $second, $third]]);

        $this->client->method('ingest')->willReturn([
            'ok' => true,
            'processed' => 2,
            'deduplicated' => 0,
            'errors' => [
                ['index' => 1, 'field' => 'price', 'message' => 'must be a number', 'sku' => 'X'],
            ],
        ]);

        $sent = [];
        $this->queue->method('markSent')->willReturnCallback(
            static function (IngestEvent $event) use (&$sent): void {
                $sent[] = (int)$event->getId();
            }
        );
        $failed = [];
        $this->queue->method('markFailed')->willReturnCallback(
            static function (IngestEvent $event, string $error, bool $terminal = false) use (&$failed): void {
                $failed[(int)$event->getId()] = [$error, $terminal];
            }
        );

        $this->createCron()->execute();

        self::assertSame([11, 13], $sent);
        self::assertArrayHasKey(12, $failed);
        self::assertSame('price: must be a number', $failed[12][0]);
        self::assertTrue($failed[12][1], 'Per-item validation errors are terminal');
    }

    public function testTransportFailureReschedulesBatchNonTerminally(): void
    {
        $event = $this->createEvent(5);
        $this->stubClaims([Client::DOMAIN_CATALOG => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineTransportException('engine down', 503));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'engine down');
        $this->queue->expects(self::never())->method('markSent');

        $this->createCron()->execute();
    }

    public function testWholeBatchRequestErrorIsTerminal(): void
    {
        $event = $this->createEvent(6);
        $this->stubClaims([Client::DOMAIN_BROWSE => [$event]]);
        $this->client->method('ingest')
            ->willThrowException(new EngineRequestException('bad wrapper', 400));

        $this->queue->expects(self::once())->method('markFailed')
            ->with($event, 'bad wrapper', true);

        $this->createCron()->execute();
    }

    public function testDisconnectedEngineSkipsAllWork(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $this->queue->expects(self::never())->method('claimBatch');

        (new FlushIngestQueue($settings, $this->queue, $this->client, $this->createMock(Logger::class)))
            ->execute();
    }

    /**
     * @param array<string, IngestEvent[]> $byDomain
     */
    private function stubClaims(array $byDomain): void
    {
        $this->queue->method('claimBatch')->willReturnCallback(
            static fn (string $domain): array => $byDomain[$domain] ?? []
        );
    }

    private function createCron(): FlushIngestQueue
    {
        return new FlushIngestQueue(
            $this->settings,
            $this->queue,
            $this->client,
            $this->createMock(Logger::class)
        );
    }

    private function createEvent(int $id): IngestEvent&MockObject
    {
        $event = $this->createMock(IngestEvent::class);
        $event->method('getId')->willReturn($id);
        $event->method('getDomain')->willReturn('catalog');

        return $event;
    }
}
