<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Config\Source\AbandonedFields;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * The wizard/Settings single-mode workflow selects (.smaily-w-workflow) share
 * the engine-automations preserve rule (PRO-1286): a saved workflow id missing
 * from the freshly loaded Smaily list is kept on an empty post instead of being
 * dropped, while a present id cleared to "-- Not Selected --" still clears.
 */
class WizardStepSaverTest extends TestCase
{
    /** @var WriterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $configWriter;

    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var SmailyClientProvider&\PHPUnit\Framework\MockObject\MockObject */
    private $clientProvider;

    /** @var array<int, array{path: string, value: mixed}> */
    private array $saved = [];

    private WizardStepSaver $saver;

    protected function setUp(): void
    {
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->clientProvider = $this->createMock(SmailyClientProvider::class);

        $this->saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function (string $path, $value = null): WriterInterface {
                $this->saved[] = ['path' => $path, 'value' => $value];

                return $this->configWriter;
            }
        );

        $this->saver = new WizardStepSaver(
            $this->configWriter,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(SubdomainNormalizer::class),
            $this->createMock(AccountResolver::class),
            $this->config,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(MappingSaver::class),
            $this->clientProvider,
            new ConfigRowNormalizer()
        );
    }

    /**
     * @param array<int, array{id: int, title: string}> $workflows
     */
    private function withWorkflows(array $workflows): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->method('getAutomationWorkflows')->willReturn($workflows);
        $this->clientProvider->method('forStore')->willReturn($client);
    }

    private function savedValue(string $path): ?string
    {
        $value = null;
        foreach ($this->saved as $row) {
            if ($row['path'] === $path) {
                $value = (string)$row['value'];
            }
        }

        return $value;
    }

    private function wasSaved(string $path): bool
    {
        foreach ($this->saved as $row) {
            if ($row['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    public function testMissingSavedWorkflowIsPreservedOnEmptyPost(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(123);
        $this->withWorkflows([['id' => 456, 'title' => 'Other']]);

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertFalse(
            $this->wasSaved(Config::XML_PATH_WELCOME_WORKFLOW),
            'A saved id absent from the live list must not be overwritten by an empty post'
        );
    }

    public function testMissingSavedWorkflowIsPreservedWhenListFailsToLoad(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(123);
        $this->clientProvider->method('forStore')
            ->willThrowException(new SmailyClientException('no credentials'));

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertFalse($this->wasSaved(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    public function testDeliberateClearOfPresentWorkflowIsHonored(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(456);
        $this->withWorkflows([['id' => 456, 'title' => 'Welcome']]);

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertSame('0', $this->savedValue(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    public function testNewSelectionIsStored(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(0);
        $this->withWorkflows([['id' => 456, 'title' => 'Welcome']]);

        $this->saver->save('automations', ['welcome_workflow' => '456']);

        self::assertSame('456', $this->savedValue(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    /**
     * PRO-1397: the Subscribers tab's orphan-field controls (include_guests,
     * automation_force_opt_in) reuse this pre-existing saveFlag() wiring —
     * confirm it actually persists both when the template starts sending them.
     */
    public function testSubscriberOrphanFlagsAreSaved(): void
    {
        $this->saver->save('subscribers', ['include_guests' => true, 'automation_force_opt_in' => false]);

        self::assertSame('1', $this->savedValue(Config::XML_PATH_INCLUDE_GUESTS));
        self::assertSame('0', $this->savedValue(Config::XML_PATH_AUTOMATION_FORCE_OPT_IN));
    }

    /**
     * The wizard doesn't render these controls (Settings-only, per target
     * spec §2.3.B) — collect.subscribers() omits the keys there rather than
     * posting false, so an absent key must leave the stored value untouched.
     */
    public function testSubscriberOrphanFlagsAreUntouchedWhenKeyAbsent(): void
    {
        $this->saver->save('subscribers', ['sync_enabled' => true]);

        self::assertFalse($this->wasSaved(Config::XML_PATH_INCLUDE_GUESTS));
        self::assertFalse($this->wasSaved(Config::XML_PATH_AUTOMATION_FORCE_OPT_IN));
    }

    /**
     * PRO-1401: the Automations tab's abandoned-cart product-field control
     * (automations/abandoned_fields, orphaned until now) posts a selection
     * that is stored as a CSV, filtered to the supported fields and kept in
     * their canonical order.
     */
    public function testAbandonedFieldsAreStoredFilteredToSupported(): void
    {
        $this->saver->save('automations', [
            'abandoned_fields' => ['sku', 'name', 'bogus', 'price'],
        ]);

        self::assertSame('name,sku,price', $this->savedValue(Config::XML_PATH_ABANDONED_FIELDS));
    }

    public function testAbandonedFieldsEmptySelectionClearsTheValue(): void
    {
        $this->saver->save('automations', ['abandoned_fields' => []]);

        self::assertSame('', $this->savedValue(Config::XML_PATH_ABANDONED_FIELDS));
    }

    /**
     * The wizard doesn't render this control (Settings-only) — an absent key
     * must leave the stored selection untouched.
     */
    public function testAbandonedFieldsUntouchedWhenKeyAbsent(): void
    {
        $this->saver->save('automations', ['welcome_enabled' => true]);

        self::assertFalse($this->wasSaved(Config::XML_PATH_ABANDONED_FIELDS));
    }

    public function testEveryAbandonedFieldPersistsInCanonicalOrder(): void
    {
        $this->saver->save('automations', [
            'abandoned_fields' => array_reverse(AbandonedFields::SUPPORTED_FIELDS),
        ]);

        self::assertSame(
            implode(',', AbandonedFields::SUPPORTED_FIELDS),
            $this->savedValue(Config::XML_PATH_ABANDONED_FIELDS)
        );
    }
}
