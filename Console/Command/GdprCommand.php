<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:gdpr <export|erase> <email> [--force]
 *
 * GDPR tooling for Campaign Intelligence data: Art. 15 export (returns the
 * engine's stored record) and Art. 17 erasure (CASCADE delete engine-side,
 * idempotent — repeating the erase is safe). Smaily marketing-contact
 * deletion is done in the Smaily UI; Magento's own customer data is handled
 * by Magento's native tooling.
 */
class GdprCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:gdpr')
            ->setDescription('Export or erase Campaign Intelligence data for a customer email')
            ->addArgument('action', InputArgument::REQUIRED, 'export or erase')
            ->addArgument('email', InputArgument::REQUIRED, 'Customer email address')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Confirm erasure');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->settings->isConnected()) {
            $output->writeln('<comment>Campaign Intelligence is not connected.</comment>');

            return Command::FAILURE;
        }

        $action = (string)$input->getArgument('action');
        $email = strtolower(trim((string)$input->getArgument('email')));

        try {
            if ($action === 'export') {
                $output->writeln(
                    (string)json_encode($this->client->customerExport($email), JSON_PRETTY_PRINT)
                );

                return Command::SUCCESS;
            }

            if ($action === 'erase') {
                if (!$input->getOption('force')) {
                    $output->writeln('<comment>Add --force to confirm the irreversible erasure.</comment>');

                    return Command::FAILURE;
                }
                $result = $this->client->customerDelete($email);
                $output->writeln(sprintf(
                    '<info>Erased engine data for %s.%s</info>',
                    $email,
                    !empty($result['already_deleted']) ? ' (was already deleted)' : ''
                ));

                return Command::SUCCESS;
            }
        } catch (EngineException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln('<error>Unknown action; use export or erase.</error>');

        return Command::FAILURE;
    }
}
