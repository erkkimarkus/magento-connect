<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

class ResendGuardTest extends TestCase
{
    private ResendGuard $guard;
    private AdapterInterface&MockObject $connection;
    private QueueRowLoader&MockObject $rowLoader;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quote')->willReturnCallback(
            static fn ($value): string => "'" . $value . "'"
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->rowLoader = $this->createMock(QueueRowLoader::class);

        $this->guard = new ResendGuard($resourceConnection, new Json(), $this->rowLoader);
    }

    public function testWithdrawnRowIsRefusedFromTheStoredMarker(): void
    {
        $reason = $this->guard->refusalReason(Collection::SOURCE_SMAILY, 7, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_SENT,
            'last_response' => EventQueue::CANCELLED_RESPONSE,
        ]);

        self::assertSame(ResendGuard::REASON_WITHDRAWN, $reason);
    }

    public function testWithdrawnRowIsRefusedFromTheDerivedGridStatus(): void
    {
        $reason = $this->guard->refusalReason(Collection::SOURCE_SMAILY, 7, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Collection::STATUS_WITHDRAWN,
        ]);

        self::assertSame(ResendGuard::REASON_WITHDRAWN, $reason);
    }

    public function testErasedRowIsRefusedInBothQueues(): void
    {
        foreach ([Collection::SOURCE_SMAILY, Collection::SOURCE_INTELLIGENCE] as $source) {
            $reason = $this->guard->refusalReason($source, 3, [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => Erasure::PLACEHOLDER,
                'status' => Event::STATUS_FAILED,
            ]);

            self::assertSame(ResendGuard::REASON_ERASED, $reason, $source);
        }
    }

    public function testContactSyncAndIngestRowsAreSafe(): void
    {
        $this->connection->expects(self::never())->method('fetchPairs');

        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_SMAILY, 3, [
            'type' => EventType::CONTACT_SYNC,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_FAILED,
        ]));
        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_INTELLIGENCE, 4, [
            'type' => 'catalog',
            'entity_id' => 'SKU-1',
            'status' => Event::STATUS_FAILED,
        ]));
    }

    public function testAutomationRowIsRefusedWhenALaterRowOfTheSameTriggerWasSent(): void
    {
        $this->connection->method('fetchPairs')->willReturn([
            5 => json_encode(['trigger_type' => 'welcome']),
            9 => json_encode(['trigger_type' => 'welcome']),
        ]);

        self::assertSame(ResendGuard::REASON_SUPERSEDED, $this->guard->refusalReason(
            Collection::SOURCE_SMAILY,
            5,
            [
                'type' => EventType::AUTOMATION_TRIGGER,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ]
        ));
    }

    public function testAutomationRowIsSafeWhenTheLaterRowIsAnotherTrigger(): void
    {
        $this->connection->method('fetchPairs')->willReturn([
            5 => json_encode(['trigger_type' => 'welcome']),
            9 => json_encode(['trigger_type' => 'abandoned_cart']),
        ]);

        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_SMAILY, 5, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_FAILED,
        ]));
    }

    public function testMassRetryLearnsWhichSelectedRowsToSkip(): void
    {
        $this->rowLoader->method('loadFailed')->willReturn([
            3 => [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => Erasure::PLACEHOLDER,
                'status' => Event::STATUS_FAILED,
            ],
            4 => [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ],
        ]);

        self::assertSame(
            [3 => ResendGuard::REASON_ERASED],
            $this->guard->refusalReasons(Collection::SOURCE_SMAILY, [3, 4])
        );
    }

    public function testEveryReasonHasItsOwnSentence(): void
    {
        $messages = [
            (string)$this->guard->message(ResendGuard::REASON_WITHDRAWN),
            (string)$this->guard->message(ResendGuard::REASON_SUPERSEDED),
            (string)$this->guard->message(ResendGuard::REASON_ERASED),
        ];

        self::assertCount(3, array_unique($messages));
        self::assertStringContainsString('withdrawn', $messages[0]);
        self::assertStringContainsString('twice', $messages[1]);
        self::assertStringContainsString('erased', $messages[2]);
    }
}
