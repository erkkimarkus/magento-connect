<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Config;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\ModuleConfigPaths;
use Smaily\Connect\Model\Config\OverrideClearer;

class OverrideClearerTest extends TestCase
{
    /**
     * A path outside the module's allowlist is refused: nothing is deleted and
     * no cache is flushed — the affordance can never touch unrelated config.
     */
    public function testRejectsNonModulePath(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('delete');
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects(self::never())->method('cleanType');

        $clearer = new OverrideClearer($writer, $cache, new ModuleConfigPaths());

        $result = $clearer->clear('web/secure/base_url', ScopeInterface::SCOPE_WEBSITES, 1);

        self::assertFalse($result['cleared']);
    }

    /**
     * A module path at a website scope deletes exactly that path+scope+id row
     * and flushes the config cache.
     */
    public function testDeletesOnlyTheRequestedModulePathAndScope(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())
            ->method('delete')
            ->with(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 2);
        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects(self::once())
            ->method('cleanType')
            ->with(ConfigCache::TYPE_IDENTIFIER);

        $clearer = new OverrideClearer($writer, $cache, new ModuleConfigPaths());

        $result = $clearer->clear(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 2);

        self::assertTrue($result['cleared']);
        self::assertSame(Config::XML_PATH_SUBDOMAIN, $result['path']);
        self::assertSame(ScopeInterface::SCOPE_WEBSITES, $result['scope']);
        self::assertSame(2, $result['scopeId']);
    }

    /**
     * A store-view scope is accepted too (the mode-A credential case).
     */
    public function testDeletesStoreViewScope(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())
            ->method('delete')
            ->with(Config::XML_PATH_PASSWORD, ScopeInterface::SCOPE_STORES, 3);
        $cache = $this->createMock(TypeListInterface::class);

        $clearer = new OverrideClearer($writer, $cache, new ModuleConfigPaths());

        $result = $clearer->clear(Config::XML_PATH_PASSWORD, ScopeInterface::SCOPE_STORES, 3);

        self::assertTrue($result['cleared']);
    }

    /**
     * The default scope (the scope the Settings page owns) is never a delete
     * target — only more-specific overrides can be cleared.
     */
    public function testRejectsDefaultScope(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('delete');

        $clearer = new OverrideClearer(
            $writer,
            $this->createMock(TypeListInterface::class),
            new ModuleConfigPaths()
        );

        $result = $clearer->clear(Config::XML_PATH_SUBDOMAIN, 'default', 0);

        self::assertFalse($result['cleared']);
    }

    /**
     * A non-positive scope id is refused before any delete.
     */
    public function testRejectsInvalidScopeId(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('delete');

        $clearer = new OverrideClearer(
            $writer,
            $this->createMock(TypeListInterface::class),
            new ModuleConfigPaths()
        );

        $result = $clearer->clear(Config::XML_PATH_SUBDOMAIN, ScopeInterface::SCOPE_WEBSITES, 0);

        self::assertFalse($result['cleared']);
    }
}
