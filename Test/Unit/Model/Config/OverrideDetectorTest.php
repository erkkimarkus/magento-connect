<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Config;

use Magento\Config\Model\ResourceModel\Config\Data\Collection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\ModuleConfigPaths;
use Smaily\Connect\Model\Config\OverrideDetector;

class OverrideDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../Support/Stub/ConfigDataCollectionFactory.php';
    }

    /**
     * No non-default rows -> nothing is reported as shadowed.
     */
    public function testNoOverridesWhenCollectionEmpty(): void
    {
        $detector = new OverrideDetector(
            $this->collectionFactory([]),
            $this->createMock(StoreManagerInterface::class),
            new ModuleConfigPaths()
        );

        self::assertSame([], $detector->detect());
    }

    /**
     * A single website-scope row shadows the default the Settings page edits.
     */
    public function testSingleWebsiteOverrideIsReported(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getName')->willReturn('Main Website');
        $storeManager->method('getWebsite')->with(1)->willReturn($website);

        $rows = [
            $this->row(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 1),
        ];

        $detector = new OverrideDetector(
            $this->collectionFactory($rows),
            $storeManager,
            new ModuleConfigPaths()
        );

        $result = $detector->detect();

        self::assertArrayHasKey(Config::XML_PATH_SUBDOMAIN, $result);
        self::assertCount(1, $result[Config::XML_PATH_SUBDOMAIN]);
        self::assertSame(ScopeInterface::SCOPE_WEBSITES, $result[Config::XML_PATH_SUBDOMAIN][0]['scope']);
        self::assertSame(1, $result[Config::XML_PATH_SUBDOMAIN][0]['scopeId']);
        self::assertSame('Main Website', $result[Config::XML_PATH_SUBDOMAIN][0]['label']);
    }

    /**
     * The same path shadowed at two scopes (website + store view) lists both;
     * a global (scope_id 0) row is not a specific-scope shadow and is skipped.
     */
    public function testMultipleScopesAndGlobalRowSkipped(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getName')->willReturn('Main Website');
        $storeManager->method('getWebsite')->willReturn($website);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('German');
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager->method('getStore')->with(2)->willReturn($store);

        $rows = [
            $this->row(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 1),
            $this->row(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_STORES, 2),
            // Global mapping-style row (website 0) — not a specific override.
            $this->row(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 0),
        ];

        $detector = new OverrideDetector(
            $this->collectionFactory($rows),
            $storeManager,
            new ModuleConfigPaths()
        );

        $result = $detector->detect();

        self::assertCount(2, $result[Config::XML_PATH_SUBDOMAIN]);
        $labels = array_column($result[Config::XML_PATH_SUBDOMAIN], 'label');
        self::assertContains('Main Website', $labels);
        self::assertContains('Main Website / German', $labels);
    }

    private function row(string $path, string $scope, int $scopeId): DataObject
    {
        return new DataObject(['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId]);
    }

    /**
     * @param DataObject[] $rows
     */
    private function collectionFactory(array $rows): CollectionFactory
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }
}
