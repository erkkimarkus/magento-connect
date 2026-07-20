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
use Smaily\Connect\Model\Config\Source\LogVerbosity;
use Smaily\Connect\Model\LogVerbositySaver;

/**
 * POST {verbosity: error|info|debug} -> {saved: bool, error?: string}
 *
 * Saves `smaily_connect/logging/verbosity` at default scope only — this
 * field has no website/store-view meaning (installation-wide), matching its
 * native system.xml declaration before the native surface was hidden. This
 * is the Log page's own control (docs/ADMIN_UI_TARGET_SPEC.md §2.4/§4.2),
 * gated on the Log page's own ACL resource rather than Settings' since that
 * is where the control lives.
 */
class SaveVerbosity extends AbstractJsonAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    private const ALLOWED = [LogVerbosity::ERROR, LogVerbosity::INFO, LogVerbosity::DEBUG];

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly LogVerbositySaver $verbositySaver
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $verbosity = (string)($this->requestBody()['verbosity'] ?? '');
        if (!in_array($verbosity, self::ALLOWED, true)) {
            return $this->jsonResponse([
                'saved' => false,
                'error' => (string)__('Invalid log verbosity value.'),
            ]);
        }

        $this->verbositySaver->save($verbosity);

        return $this->jsonResponse(['saved' => true]);
    }
}
