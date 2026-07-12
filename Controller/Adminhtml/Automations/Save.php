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
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
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
        private readonly Client $client,
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly ConfigRowNormalizer $normalizer
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
        // The currently loadable Smaily workflow ids — a saved id absent here
        // was not offered in the dropdown, so the normalizer must not treat an
        // empty single-mode post as a deliberate clear (PRO-1268).
        $availableWorkflowIds = $this->availableWorkflowIds();

        $triggers = (array)$this->getRequest()->getParam('triggers', []);
        $rows = [];
        foreach ($triggers as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            $rows[] = $this->normalizer->normalize((string)$key, $data, $availableWorkflowIds);
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

    /**
     * Workflow ids the Smaily API can currently list, as strings. An empty
     * array (credentials missing or the listing failed) means "unknown" — the
     * normalizer then keeps every saved binding rather than dropping ids it
     * cannot confirm are gone.
     *
     * @return array<int, string>
     */
    private function availableWorkflowIds(): array
    {
        try {
            $workflows = $this->smailyClientProvider->forStore(null)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }

        return array_map(static fn (array $workflow): string => (string)$workflow['id'], $workflows);
    }
}
