<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:backfill:start contacts [--website=1]
 *
 * Queues a chunked historical import; the smaily_backfill_tick cron job
 * advances it. Progress: smaily:backfill:status.
 */
class BackfillStartCommand extends Command
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:backfill:start')
            ->setDescription('Start a Smaily historical import (backfill) job')
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Job type (contacts)',
                Job::TYPE_CONTACTS
            )
            ->addOption(
                'website',
                'w',
                InputOption::VALUE_REQUIRED,
                'Website ID (omit to start a job for every website)'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobType = (string)$input->getArgument('type');
        if ($jobType !== Job::TYPE_CONTACTS) {
            $output->writeln(sprintf('<error>Unknown job type "%s".</error>', $jobType));

            return Command::FAILURE;
        }

        $websiteOption = $input->getOption('website');
        $websiteIds = $websiteOption !== null
            ? [(int)$websiteOption]
            : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());

        $started = 0;
        foreach ($websiteIds as $websiteId) {
            try {
                $job = $this->jobManager->start($jobType, Job::TARGET_SMAILY, $websiteId);
                $output->writeln(sprintf(
                    '<info>Started %s backfill #%d for website %d.</info>',
                    $jobType,
                    (int)$job->getId(),
                    $websiteId
                ));
                $started++;
            } catch (\RuntimeException $exception) {
                $output->writeln(sprintf('<comment>%s</comment>', $exception->getMessage()));
            }
        }

        return $started > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
