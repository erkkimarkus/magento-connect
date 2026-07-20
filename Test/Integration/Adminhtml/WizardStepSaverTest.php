<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * WizardStepSaver's website-scoped writes (RFC_MULTI_WEBSITE.md §1) against
 * a real core_config_data table: the target website's row is a real,
 * distinct scoped row, and a pre-existing default-scope value (an
 * un-migrated single-website install, or a value another website falls
 * back to) is left untouched rather than moved or deleted.
 */
class WizardStepSaverTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 7;

    private WizardStepSaver $saver;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);

        $this->saver = new WizardStepSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            new SubdomainNormalizer(),
            $this->createMock(AccountResolver::class),
            $this->objectManager->get(Config::class),
            new WebsiteContext($storeManager),
            $storeManager,
            $this->createMock(MappingSaver::class),
            $this->createMock(SmailyClientProvider::class),
            new ConfigRowNormalizer()
        );
    }

    public function testConnectCredentialsLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'password' => 'plain-secret',
        ]);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
        self::assertSame('demo', $rows[0]['value']);

        $passwordRow = $this->configRows(Config::XML_PATH_PASSWORD)[0];
        self::assertSame('websites', $passwordRow['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$passwordRow['scope_id']);
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        self::assertSame('plain-secret', $encryptor->decrypt($passwordRow['value']));
    }

    /**
     * The read/write asymmetry this phase closes (PRO-1274, RFC §1): an
     * existing default-scope value (an un-migrated single-website install,
     * or another website's fallback) is never touched by a save — only the
     * target website gains its own explicit row.
     */
    public function testPreExistingDefaultScopeValueIsLeftUntouchedAsFallback(): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => Config::XML_PATH_SUBDOMAIN,
            'value' => 'legacy-default',
        ]);

        $this->saver->save('connect', ['subdomain' => 'new-demo', 'username' => 'api-user']);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(2, $rows, 'The default-scope row must survive alongside the new website row');

        $byScope = [];
        foreach ($rows as $row) {
            $byScope[$row['scope']] = $row['value'];
        }
        self::assertSame('legacy-default', $byScope['default']);
        self::assertSame('new-demo', $byScope['websites']);
    }

    public function testSubscriberTogglesLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('subscribers', ['sync_enabled' => true]);

        $rows = $this->configRows(Config::XML_PATH_SYNC_ENABLED);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
        self::assertSame('1', $rows[0]['value']);
    }

    public function testAutomationTogglesLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('automations', ['welcome_enabled' => true]);

        $rows = $this->configRows(Config::XML_PATH_WELCOME_ENABLED);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
    }

    public function testSavingTwiceUpdatesTheSameWebsiteRowInsteadOfDuplicating(): void
    {
        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user']);
        $this->saver->save('connect', ['subdomain' => 'demo-two', 'username' => 'api-user']);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(1, $rows);
        self::assertSame('demo-two', $rows[0]['value']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configRows(string $path): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data')->where('path = ?', $path)
        );
    }
}
