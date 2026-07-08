<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;

/**
 * POST {step: connect|subscribers|automations|intelligence|finish, data: {...}}
 * -> {saved: bool, errors: [{field, message}]}
 *
 * Persists one wizard step. All writes go to the same system config paths
 * the Stores > Configuration page edits — the wizard is an onboarding view
 * over the exact same single source of truth.
 */
class SaveStep extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly WizardStepSaver $stepSaver
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $step = (string)($body['step'] ?? '');
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        $errors = $this->stepSaver->save($step, $data);

        return $this->jsonResponse([
            'saved' => $errors === [],
            'errors' => $errors,
        ]);
    }
}
