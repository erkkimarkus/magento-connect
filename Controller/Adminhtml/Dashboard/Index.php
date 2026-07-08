<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Backend\Model\View\Result\Page;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::connect';

    /**
     * @inheritDoc
     */
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Smaily_Connect::dashboard');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Getting Started'));

        return $page;
    }
}
