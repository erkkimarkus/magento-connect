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
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('smaily_connect/automations');

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

            $rows[] = [
                'trigger_key' => (string)$key,
                'enabled' => !empty($data['enabled']) && $workflowId !== '',
                // MVP scope: single-language mode; per-language maps come with
                // the multilingual admin later.
                'language_mode' => 'single',
                'automation_map' => $workflowId !== '' ? ['id' => $workflowId] : [],
                'cooldown_days' => max(1, min(365, (int)($data['cooldown_days'] ?? 7))),
                'daily_cap' => $dailyCap === '' ? null : max(1, (int)$dailyCap),
                'test_mode' => !empty($data['test_mode']),
                'test_emails' => array_slice($testEmails, 0, 50),
            ];
        }

        if (!$rows) {
            $this->messageManager->addNoticeMessage((string)__('Nothing to save.'));

            return $redirect;
        }

        try {
            $this->client->putAutomationsConfig($rows);
            $this->messageManager->addSuccessMessage(
                (string)__('%1 automation trigger(s) saved.', count($rows))
            );
        } catch (EngineRequestException $exception) {
            foreach ((array)($exception->getErrorBody()['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $this->messageManager->addErrorMessage(sprintf(
                        '%s / %s: %s',
                        (string)($error['trigger_key'] ?? 'row'),
                        (string)($error['field'] ?? ''),
                        (string)($error['message'] ?? 'invalid')
                    ));
                }
            }
            $this->messageManager->addErrorMessage(
                (string)__('Nothing was saved: %1', $exception->getMessage())
            );
        } catch (EngineException $exception) {
            $this->messageManager->addErrorMessage(
                (string)__('Saving failed: %1', $exception->getMessage())
            );
        }

        return $redirect;
    }
}
