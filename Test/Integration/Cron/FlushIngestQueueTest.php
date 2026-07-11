<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The engine ingest flush cron against a real queue table with the HTTP
 * client mocked: D6 per-item error mapping, transport vs request failures.
 */
class FlushIngestQueueTest extends IntegrationTestCase
{
    private IngestQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

    public function testD6ResponseMapsPerItemErrorsOntoBatchRows(): void
    {
        $this->queue->enqueue('catalog', ['sku' => 'OK-1'], null, null, 'fi-1');
        $this->queue->enqueue('catalog', ['sku' => 'BAD'], null, null, 'fi-2');
        $this->queue->enqueue('catalog', ['sku' => 'OK-2'], null, null, 'fi-3');

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with('catalog', self::callback(static fn (array $items): bool => count($items) === 3))
            ->willReturn([
                'processed' => 2,
                'deduplicated' => 0,
                'errors' => [['index' => 1, 'field' => 'price', 'message' => 'must be a number']],
            ]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-1']['status']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-3']['status']);
        self::assertSame(IngestEvent::STATUS_FAILED, $rows['fi-2']['status'], 'Per-item errors are terminal');
        self::assertSame('price: must be a number', $rows['fi-2']['last_error']);
        self::assertNull($rows['fi-2']['next_retry_at']);
    }

    public function testTransportFailureReschedulesTheBatchWithBackoff(): void
    {
        $this->queue->enqueue('orders', ['order' => []], null, null, 'fi-t1');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineTransportException('HTTP 503'));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(IngestQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testWholeBatchRequestErrorIsTerminal(): void
    {
        $this->queue->enqueue('customers', ['customer' => []], null, null, 'fi-r1');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineRequestException('HTTP 400: bad wrapper', 400));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status'], 'Malformed requests must not be retried');
        self::assertNull($row['next_retry_at']);
    }

    public function testDisconnectedEngineLeavesTheQueueUntouched(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'fi-idle');

        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(false);
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('ingest');

        $this->buildCron($settings, $client)->execute();

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
    }

    /**
     * @param Client&\PHPUnit\Framework\MockObject\MockObject $client
     */
    private function runCron(Client $client): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $this->buildCron($settings, $client)->execute();
    }

    private function buildCron(Settings $settings, Client $client): FlushIngestQueue
    {
        /** @var Logger $logger */
        $logger = $this->objectManager->get(Logger::class);

        return new FlushIngestQueue($settings, $this->queue, $client, $logger);
    }
}
