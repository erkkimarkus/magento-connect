<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Automations;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;

/**
 * Persists engine automation configuration (contract §13). Every row carries
 * all eight keys — no server-side defaults; validation is all-or-nothing.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::automations';

    public function __construct(
        Context $context,
        private readonly Client $client
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect|Json
    {
        $errors = [];
        $saved = $this->save($errors);

        // Embedded config-page block saves via AJAX; plain form posts get a
        // redirect back with flash messages.
        $request = $this->getRequest();
        if ($request instanceof HttpRequest && $request->isXmlHttpRequest()) {
            /** @var Json $json */
            $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);

            return $json->setData(['saved' => $saved, 'errors' => $errors]);
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setRefererOrBaseUrl();
    }

    /**
     * @param string[] $errors collected error strings (also flashed)
     */
    private function save(array &$errors): bool
    {
        $triggers = (array)$this->getRequest()->getParam('triggers', []);
        $rows = [];
        foreach ($triggers as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $workflowId = trim((string)($data['workflow_id'] ?? ''));
            $dailyCap = trim((string)($data['daily_cap'] ?? ''));
            $testEmails = array_values(array_filter(array_map(
                'trim',
                explode(',', (string)($data['test_emails'] ?? ''))
            )));

            // Preserve a per_language row (saved from another platform on the
            // same tenant) as long as the merchant did not change the
            // workflow here — a save must never silently wipe language maps.
            $originalMode = (string)($data['language_mode'] ?? 'single');
            $originalMap = json_decode((string)($data['original_map'] ?? ''), true);
            $originalMap = is_array($originalMap) ? $originalMap : [];
            if ($originalMode === 'per_language'
                && $workflowId === (string)($originalMap['fallback'] ?? '')
            ) {
                $languageMode = 'per_language';
                $map = $originalMap;
            } else {
                $languageMode = 'single';
                $map = $workflowId !== '' ? ['id' => $workflowId] : [];
            }

            $rows[] = [
                'trigger_key' => (string)$key,
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

        if (!$rows) {
            $errors[] = (string)__('Nothing to save.');

            return false;
        }

        try {
            $this->client->putAutomationsConfig($rows);
            $this->messageManager->addSuccessMessage(
                (string)__('%1 automation trigger(s) saved.', count($rows))
            );

            return true;
        } catch (EngineRequestException $exception) {
            foreach ((array)($exception->getErrorBody()['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $errors[] = sprintf(
                        '%s / %s: %s',
                        (string)($error['trigger_key'] ?? 'row'),
                        (string)($error['field'] ?? ''),
                        (string)($error['message'] ?? 'invalid')
                    );
                }
            }
            $errors[] = (string)__('Nothing was saved: %1', $exception->getMessage());
        } catch (EngineException $exception) {
            $errors[] = (string)__('Saving failed: %1', $exception->getMessage());
        }

        foreach ($errors as $message) {
            $this->messageManager->addErrorMessage($message);
        }

        return false;
    }
}
