<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Privacy;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Console\Command\GdprCommand;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Smaily\Connect\Model\Privacy\PayloadAnonymizer;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The real `smaily:gdpr` command against the real tables (PRO-2452): what a
 * data-subject erasure does to the two queues and the abandoned-cart side
 * table, and that a failing engine never costs the local half.
 *
 * Contacts are synthetic throughout.
 */
class GdprEraseTest extends IntegrationTestCase
{
    private const SUBJECT = 'erase-test-1@example.test';
    private const BYSTANDER = 'erase-test-2@example.test';
    private const NON_ASCII_SUBJECT = 'mõni@näide.test';
    private const ABANDONED_CART_TABLE = 'smaily_abandoned_cart';

    private EventQueue $eventQueue;
    private IngestQueue $ingestQueue;
    private StateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventQueue = $this->objectManager->create(EventQueue::class);
        $this->ingestQueue = $this->objectManager->create(IngestQueue::class);
        $this->stateManager = $this->objectManager->create(StateManager::class);
    }

    public function testErasureDeletesSendableRowsAndAnonymisesTheRest(): void
    {
        $this->seedContactSync(self::SUBJECT, 'sub-pending');
        $this->seedContactSync(self::SUBJECT, 'sub-sending');
        $this->seedContactSync(self::SUBJECT, 'sub-sent');
        $this->seedContactSync(self::BYSTANDER, 'other-pending');
        $this->markRow(EventResource::TABLE_NAME, 'sub-sending', ['status' => Event::STATUS_SENDING]);
        $this->markRow(EventResource::TABLE_NAME, 'sub-sent', [
            'status' => Event::STATUS_SENT,
            'sent_payload' => (string)json_encode([
                'email' => self::SUBJECT,
                'first_name' => 'Test',
                'last_name' => 'Erasure',
            ]),
            'last_response' => (string)json_encode(['message' => 'queued for ' . self::SUBJECT]),
        ]);

        $this->ingestQueue->enqueue('customers', ['email' => self::SUBJECT], '77', null, 'ing-pending');
        $this->ingestQueue->enqueue('orders', ['customer' => ['email' => self::SUBJECT]], '100000001', null, 'ing-sent');
        $this->markRow(IngestEventResource::TABLE_NAME, 'ing-sent', [
            'status' => IngestEvent::STATUS_FAILED,
            'last_error' => 'rejected recipient ' . self::SUBJECT,
        ]);

        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        $this->stateManager->markMailed(12, 1, self::BYSTANDER);

        $tester = $this->runCommand(['action' => 'erase', 'email' => self::SUBJECT, '--force' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('smaily_event_queue: 2 removed, 1 anonymised', $tester->getDisplay());
        self::assertStringContainsString('smaily_ingest_queue: 1 removed, 1 anonymised', $tester->getDisplay());
        self::assertStringContainsString('smaily_abandoned_cart: 1 removed, 0 anonymised', $tester->getDisplay());

        $events = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(['sub-sent', 'other-pending'], array_keys($events), 'Sendable rows are gone, the rest stays');

        $erased = $events['sub-sent'];
        self::assertSame(PayloadAnonymizer::ERASED_PLACEHOLDER, $erased['entity_id']);
        self::assertSame(EventType::CONTACT_SYNC, $erased['event_type'], 'The merchant keeps the record of the send');
        self::assertSame(Event::STATUS_SENT, $erased['status']);
        foreach (['payload', 'sent_payload', 'last_response'] as $column) {
            $blob = (string)$erased[$column];
            self::assertJson($blob, $column . ' stays valid JSON');
            self::assertStringNotContainsString(self::SUBJECT, $blob);
            self::assertStringNotContainsString('Erasure', $blob, 'The name goes with the address');
        }

        $ingest = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(['ing-sent'], array_keys($ingest));
        self::assertSame(PayloadAnonymizer::ERASED_PLACEHOLDER, $ingest['ing-sent']['entity_id']);
        self::assertSame(PayloadAnonymizer::ERASED_PLACEHOLDER, $ingest['ing-sent']['last_error']);
        self::assertStringNotContainsString(self::SUBJECT, (string)$ingest['ing-sent']['payload']);

        $carts = $this->fetchAll(self::ABANDONED_CART_TABLE);
        self::assertCount(1, $carts, 'Only the subject\'s cart row goes');
        self::assertSame('12', (string)$carts[0]['quote_id']);

        // Another contact's pending row is untouched, and a second run is a no-op.
        self::assertSame(Event::STATUS_PENDING, $events['other-pending']['status']);
        $repeat = $this->runCommand(['action' => 'erase', 'email' => self::SUBJECT, '--force' => true]);
        self::assertStringContainsString('smaily_event_queue: 0 removed, 0 anonymised', $repeat->getDisplay());
    }

    /**
     * PRO-2448's edge, closed here: the address is JSON-escaped in the
     * stored bytes, so only a decoding match finds the row.
     */
    public function testANonAsciiAddressIsStillMatchedAndAnonymised(): void
    {
        $this->eventQueue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['address' => ['email' => self::NON_ASCII_SUBJECT, 'first_name' => 'Tõnu']],
            null,
            0,
            'non-ascii'
        );
        $this->markRow(EventResource::TABLE_NAME, 'non-ascii', ['status' => Event::STATUS_SENT]);

        $stored = (string)$this->fetchAll(EventResource::TABLE_NAME)[0]['payload'];
        self::assertStringNotContainsString(self::NON_ASCII_SUBJECT, $stored, 'The address is escaped on disk');

        $tester = $this->runCommand([
            'action' => 'erase',
            'email' => self::NON_ASCII_SUBJECT,
            '--force' => true,
        ]);

        self::assertStringContainsString('smaily_event_queue: 0 removed, 1 anonymised', $tester->getDisplay());
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(PayloadAnonymizer::ERASED_PLACEHOLDER, $row['entity_id']);
        self::assertStringNotContainsString('Tõnu', (string)$row['payload']);
        self::assertSame(
            ['address' => ['email' => '[erased]', 'first_name' => '[erased]']],
            json_decode((string)$row['payload'], true)
        );
    }

    public function testAFailingEngineStillLeavesTheLocalHalfErased(): void
    {
        $this->seedContactSync(self::SUBJECT, 'engine-down');

        $client = $this->createMock(Client::class);
        $client->method('customerDelete')->willThrowException(new EngineTransportException('HTTP 503'));
        $tester = $this->runCommand(
            ['action' => 'erase', 'email' => self::SUBJECT, '--force' => true],
            $client
        );

        self::assertSame(1, $tester->getStatusCode(), 'The merchant must know to retry the engine part');
        self::assertStringContainsString('HTTP 503', $tester->getDisplay());
        self::assertStringContainsString('smaily_event_queue: 1 removed', $tester->getDisplay());
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME));
    }

    public function testExportListsTheSameRowsTheErasureWouldTake(): void
    {
        $this->seedContactSync(self::SUBJECT, 'exp-1');
        $this->ingestQueue->enqueue('customers', ['email' => self::SUBJECT], '77', null, 'exp-2');
        $this->stateManager->markMailed(11, 1, self::SUBJECT);
        $this->seedContactSync(self::BYSTANDER, 'exp-other');

        $client = $this->createMock(Client::class);
        $client->method('customerExport')->willReturn(['customer' => ['orders' => 3]]);
        $tester = $this->runCommand(['action' => 'export', 'email' => self::SUBJECT], $client);

        self::assertSame(0, $tester->getStatusCode());
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(['customer' => ['orders' => 3]], $decoded['engine']);
        self::assertSame(
            [['type' => EventType::CONTACT_SYNC, 'status' => Event::STATUS_PENDING, 'created_at' => $this->createdAt('exp-1')]],
            $decoded['local'][EventResource::TABLE_NAME],
            'One row, and not the other contact\'s'
        );
        self::assertCount(1, $decoded['local'][IngestEventResource::TABLE_NAME]);
        self::assertCount(1, $decoded['local'][self::ABANDONED_CART_TABLE]);
    }

    public function testAnAnonymisedRowIsNeverRevivedByRetry(): void
    {
        $this->seedContactSync(self::SUBJECT, 'retry-me');
        $this->markRow(EventResource::TABLE_NAME, 'retry-me', ['status' => Event::STATUS_FAILED]);
        $id = (int)$this->fetchAll(EventResource::TABLE_NAME)[0]['id'];

        $this->runCommand(['action' => 'erase', 'email' => self::SUBJECT, '--force' => true]);

        self::assertSame(0, $this->eventQueue->retry([$id]));
        self::assertSame(Event::STATUS_FAILED, $this->fetchRow(EventResource::TABLE_NAME, $id)['status']);
    }

    private function seedContactSync(string $email, string $uuid): void
    {
        $this->eventQueue->enqueue(
            EventType::CONTACT_SYNC,
            ['store_id' => 1, 'contact' => ['email' => $email, 'first_name' => 'Test', 'last_name' => 'Erasure']],
            $email,
            0,
            $uuid
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    private function markRow(string $table, string $uuid, array $values): void
    {
        $this->connection->update(
            $this->connection->getTableName($table),
            $values,
            ['event_uuid = ?' => $uuid]
        );
    }

    private function createdAt(string $uuid): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()
                ->from($this->connection->getTableName(EventResource::TABLE_NAME), ['created_at'])
                ->where('event_uuid = ?', $uuid)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(array $arguments, ?Client $client = null): CommandTester
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        if ($client === null) {
            $client = $this->createMock(Client::class);
            $client->method('customerDelete')->willReturn(['ok' => true]);
        }

        $command = new GdprCommand(
            $settings,
            $client,
            new LocalEraser(
                $this->objectManager->get(ResourceConnection::class),
                new PayloadAnonymizer()
            )
        );

        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }
}
