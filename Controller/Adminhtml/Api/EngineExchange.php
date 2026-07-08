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
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * POST {setup_url} -> {connected: true, tenantName, engineVersion}
 *                   | {connected: false, message}
 */
class EngineExchange extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly Client $client,
        private readonly Settings $settings
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $setupUrl = trim((string)($this->requestBody()['setup_url'] ?? ''));
        if ($setupUrl === '') {
            return $this->jsonResponse([
                'connected' => false,
                'message' => (string)__('Paste the setup URL or token from Smaily first.'),
            ]);
        }

        try {
            $response = $this->client->setupExchange($setupUrl);
            $this->settings->storeExchange($response);

            return $this->jsonResponse([
                'connected' => true,
                'tenantName' => (string)($response['tenant_name'] ?? $response['tenant_id'] ?? ''),
                'engineVersion' => (string)($response['engine_version'] ?? ''),
            ]);
        } catch (EngineException $exception) {
            return $this->jsonResponse([
                'connected' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
