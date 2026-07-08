<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Backfill;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;

/**
 * Starts a backfill job from the admin grid page.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::backfill';

    public function __construct(
        Context $context,
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $jobType = (string)$this->getRequest()->getParam('type');
        $target = Job::TYPE_TARGETS[$jobType] ?? null;

        if ($target === null) {
            $this->messageManager->addErrorMessage((string)__('Unknown import type.'));
        } else {
            $websiteIds = $target === Job::TARGET_ENGINE
                ? [0]
                : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());

            foreach ($websiteIds as $websiteId) {
                try {
                    $this->jobManager->start($jobType, $target, $websiteId);
                    $this->messageManager->addSuccessMessage(
                        (string)__('Import "%1" started. The cron advances it every minute.', $jobType)
                    );
                } catch (\RuntimeException $exception) {
                    $this->messageManager->addNoticeMessage($exception->getMessage());
                }
            }
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/backfill');
    }
}
