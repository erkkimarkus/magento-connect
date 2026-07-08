<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Engine-run automations form data (contract §11/§12): the trigger catalog
 * is sector-filtered and dynamic — rendered from the live response, never
 * hardcoded. The engine's stored config is the source of truth; the plugin
 * keeps no local copy.
 */
class AutomationsForm implements ArgumentInterface
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    private ?string $error = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly SmailyClientProvider $smailyClientProvider
    ) {
    }

    public function isEngineConnected(): bool
    {
        return $this->settings->isConnected();
    }

    public function getLoadError(): ?string
    {
        $this->load();

        return $this->error;
    }

    /**
     * Catalog triggers merged with the stored config.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        $this->load();
        if ($this->catalog === null) {
            return [];
        }

        $configured = [];
        try {
            $config = $this->client->getAutomationsConfig();
            foreach ((array)($config['configs'] ?? []) as $row) {
                if (is_array($row) && isset($row['trigger_key'])) {
                    $configured[(string)$row['trigger_key']] = $row;
                }
            }
        } catch (EngineException) {
            // Fall through with defaults; the load error is already surfaced.
        }

        $rows = [];
        foreach ((array)($this->catalog['triggers'] ?? []) as $trigger) {
            if (!is_array($trigger) || empty($trigger['key'])) {
                continue;
            }
            $key = (string)$trigger['key'];
            $existing = $configured[$key] ?? [];
            $rows[] = [
                'key' => $key,
                'name' => (string)($trigger['name_en'] ?? $key),
                'description' => (string)($trigger['description_en'] ?? ''),
                'recipe' => (string)($trigger['recipe_en'] ?? $trigger['recipe_et'] ?? ''),
                // Fail-closed defaults per contract §13.
                'enabled' => (bool)($existing['enabled'] ?? false),
                'workflow_id' => (string)($existing['automation_map']['id'] ?? ''),
                'cooldown_days' => (int)($existing['cooldown_days'] ?? 7),
                'daily_cap' => $existing['daily_cap'] ?? null,
                'test_mode' => (bool)($existing['test_mode'] ?? true),
                'test_emails' => implode(', ', (array)($existing['test_emails'] ?? [])),
            ];
        }

        return $rows;
    }

    /**
     * Smaily workflows for the mapping dropdowns.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function getWorkflows(): array
    {
        try {
            return $this->smailyClientProvider->forStore(null)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }
    }

    private function load(): void
    {
        if ($this->catalog !== null || $this->error !== null || !$this->isEngineConnected()) {
            return;
        }

        try {
            $this->catalog = $this->client->automationsCatalog();
        } catch (EngineException $exception) {
            $this->error = $exception->getMessage();
        }
    }
}
