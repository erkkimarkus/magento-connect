<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Controller\Adminhtml\Api\AbstractJsonAction;
use Smaily\Connect\Model\Config\OverrideClearer;

/**
 * POST {path, scope, scope_id} -> {cleared: bool, ...}
 *
 * The "Use Default" affordance on the Settings page: deletes one website /
 * store-view override so the default value the page edits takes effect. ACL
 * (Smaily_Connect::config) and the admin form key are enforced by the backend
 * framework (same URL form_key contract as the other Smaily AJAX endpoints);
 * the path allowlist and scope guard live in OverrideClearer (PRO-1274).
 */
class ClearOverride extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly OverrideClearer $clearer
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $path = (string)($body['path'] ?? '');
        $scope = (string)($body['scope'] ?? '');
        $scopeId = (int)($body['scope_id'] ?? 0);

        $result = $this->clearer->clear($path, $scope, $scopeId);

        return $this->jsonResponse($result, $result['cleared'] ? 200 : 400);
    }
}
