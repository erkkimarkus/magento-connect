<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

/**
 * Normalizes one posted engine-automation row (contract §13) into the shape
 * putAutomationsConfig expects. Pure and side-effect free so the binding-
 * preservation rules can be unit tested in isolation.
 *
 * Binding preservation — a save must NEVER silently wipe a workflow binding:
 *  - per_language: a map saved from another platform on the same tenant (it
 *    carries per-language ids the single select cannot represent) is kept as
 *    long as the merchant did not change the fallback workflow here.
 *  - single: a saved workflow id that is ABSENT from the freshly loaded Smaily
 *    workflow list (deleted in Smaily, or the list failed to load) was never
 *    offered in the dropdown, so an empty post is not a deliberate clear — the
 *    binding is kept rather than dropped (PRO-1268). An id that IS in the list
 *    but was cleared to "-- Not Selected --" is a real clear and is honored.
 */
class ConfigRowNormalizer
{
    /**
     * @param array<string, mixed> $data posted trigger fields
     * @param array<int, string> $availableWorkflowIds ids in the freshly
     *        loaded Smaily workflow list (string form); an empty list means the
     *        list could not be loaded — every saved id then counts as missing.
     * @return array<string, mixed>
     */
    public function normalize(string $triggerKey, array $data, array $availableWorkflowIds): array
    {
        $workflowId = trim((string)($data['workflow_id'] ?? ''));
        $dailyCap = trim((string)($data['daily_cap'] ?? ''));
        $testEmails = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)($data['test_emails'] ?? ''))
        )));

        $originalMode = (string)($data['language_mode'] ?? 'single');
        $decoded = json_decode((string)($data['original_map'] ?? ''), true);
        $originalMap = is_array($decoded) ? $decoded : [];
        $savedSingleId = isset($originalMap['id']) ? (string)$originalMap['id'] : '';

        if ($originalMode === 'per_language'
            && $workflowId === (string)($originalMap['fallback'] ?? '')
        ) {
            $languageMode = 'per_language';
            $map = $originalMap;
        } elseif ($workflowId === ''
            && $savedSingleId !== ''
            && !in_array($savedSingleId, $availableWorkflowIds, true)
        ) {
            // Missing-from-list preserve — see the class docblock.
            $languageMode = 'single';
            $map = ['id' => $savedSingleId];
        } else {
            $languageMode = 'single';
            $map = $workflowId !== '' ? ['id' => $workflowId] : [];
        }

        return [
            'trigger_key' => $triggerKey,
            'enabled' => !empty($data['enabled']) && $map !== [],
            'language_mode' => $languageMode,
            // An empty map must serialize as a JSON object, not [].
            'automation_map' => $map === [] ? new \stdClass() : $map,
            'cooldown_days' => max(1, min(365, (int)($data['cooldown_days'] ?? 7))),
            'daily_cap' => $dailyCap === '' ? null : max(1, min(100000, (int)$dailyCap)),
            'test_mode' => !empty($data['test_mode']),
            'test_emails' => array_slice($testEmails, 0, 50),
        ];
    }
}
