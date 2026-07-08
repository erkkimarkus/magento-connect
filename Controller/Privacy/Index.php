<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Privacy;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Customer account page for the shopper personalization (profiling) choice.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Page|Redirect
    {
        if (!$this->customerSession->isLoggedIn()) {
            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('customer/account/login');
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set((string)__('Personalization'));

        return $page;
    }
}
