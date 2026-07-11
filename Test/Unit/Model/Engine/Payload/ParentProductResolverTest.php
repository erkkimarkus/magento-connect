<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;

class ParentProductResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    /** @var int how many times the super-link lookup ran */
    private int $lookups = 0;

    public function testConfigurableChildResolvesToItsParentEntityId(): void
    {
        $resolver = $this->createResolver('42');

        self::assertSame('42', $resolver->productIdOf(7));
        self::assertTrue($resolver->isConfigurableChild(7));
    }

    public function testStandaloneProductKeysItself(): void
    {
        $resolver = $this->createResolver(false);

        self::assertSame('9', $resolver->productIdOf(9));
        self::assertFalse($resolver->isConfigurableChild(9));
    }

    public function testResolutionIsMemoizedPerEntity(): void
    {
        $resolver = $this->createResolver('42');

        $resolver->productIdOf(7);
        $resolver->isConfigurableChild(7);
        $resolver->productIdOf(7);

        self::assertSame(1, $this->lookups, 'One DB lookup per entity per request');
    }

    public function testLookupFailureFallsBackToSelfKeying(): void
    {
        // Resolution must never block a payload — pre-PRO-1231 behavior.
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')
            ->willThrowException(new \RuntimeException('db gone'));
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $resolver = new ParentProductResolver($resourceConnection, $metadataPool);

        self::assertSame('7', $resolver->productIdOf(7));
        self::assertFalse($resolver->isConfigurableChild(7));
    }

    public function testNonPositiveIdsNeverHitTheDatabase(): void
    {
        $resolver = $this->createResolver('42');

        self::assertSame('0', $resolver->productIdOf(0));
        self::assertFalse($resolver->isConfigurableChild(0));
        self::assertSame(0, $this->lookups);
    }

    /**
     * @param string|false $fetchOneResult parent entity id or false (no row)
     */
    private function createResolver(string|false $fetchOneResult): ParentProductResolver
    {
        $this->lookups = 0;

        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturnCallback(
            function () use ($fetchOneResult) {
                $this->lookups++;

                return $fetchOneResult;
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new ParentProductResolver($resourceConnection, $metadataPool);
    }
}
