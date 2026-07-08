<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Config;

/**
 * Persists the checkout newsletter opt-in choice for the current quote
 * (POST smaily/checkout/optin, called by the checkout checkbox component).
 */
class Optin implements HttpPostActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly StateManager $stateManager,
        private readonly JsonSerializer $serializer,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $body = [];
        $rawContent = (string)$this->request->getContent();
        if ($rawContent !== '') {
            try {
                $decoded = $this->serializer->unserialize($rawContent);
                $body = is_array($decoded) ? $decoded : [];
            } catch (\InvalidArgumentException) {
                $body = [];
            }
        }

        // The checkout component sends the form key in the JSON body; make it
        // visible to the standard validator.
        if (isset($body['form_key'])) {
            $this->request->setParam('form_key', (string)$body['form_key']);
        }
        if (!$this->formKeyValidator->validate($this->request) || !$this->config->isCheckoutOptinEnabled()) {
            return $result->setData(['success' => false]);
        }

        $quote = $this->checkoutSession->getQuote();
        $quoteId = (int)$quote->getId();
        if ($quoteId > 0) {
            $this->stateManager->setNewsletterOptin(
                $quoteId,
                (int)$quote->getStoreId(),
                $quote->getCustomerEmail() ? (string)$quote->getCustomerEmail() : null,
                (bool)($body['opted_in'] ?? false)
            );
        }

        return $result->setData(['success' => $quoteId > 0]);
    }
}
