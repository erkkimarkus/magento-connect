<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Logger;

use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\LogVerbosity;

/**
 * Verbosity-gated logger writing to var/log/smaily_connect.log.
 *
 * Errors are always logged; info and debug respect the configured verbosity
 * so busy stores are not flooded (legacy extension issue #113).
 */
class Logger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        if (in_array($this->config->getLogVerbosity(), [LogVerbosity::INFO, LogVerbosity::DEBUG], true)) {
            $this->logger->info($message, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->config->getLogVerbosity() === LogVerbosity::DEBUG) {
            $this->logger->debug($message, $context);
        }
    }
}
