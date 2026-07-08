<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Drains the engine ingest queue, one batch per domain per run.
 *
 * D6 responses are per-item: errors[].index maps back onto the batch rows,
 * so a 200 never marks the whole batch sent blindly (contract scar #5).
 * Transport failures (429 after retries, 5xx, network) reschedule the batch
 * with backoff; per-item validation errors are terminal for that row.
 */
class FlushIngestQueue
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IngestQueue $queue,
        private readonly Client $client,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        $this->queue->requeueStale();

        foreach (array_keys(Client::DOMAIN_WRAPPERS) as $domain) {
            $this->flushDomain($domain);
        }
    }

    private function flushDomain(string $domain): void
    {
        $events = $this->queue->claimBatch($domain, Client::DOMAIN_BATCH_LIMITS[$domain]);
        if (!$events) {
            return;
        }

        $items = [];
        foreach ($events as $index => $event) {
            $items[$index] = $this->queue->decodePayload($event);
        }

        try {
            $response = $this->client->ingest($domain, $items);
        } catch (EngineTransportException $exception) {
            foreach ($events as $event) {
                $this->queue->markFailed($event, $exception->getMessage());
            }
            $this->logger->info('Ingest batch rescheduled', [
                'domain' => $domain,
                'count' => count($events),
                'error' => $exception->getMessage(),
            ]);

            return;
        } catch (EngineRequestException $exception) {
            // Whole-batch 4xx: the request shape is wrong; retrying the same
            // rows cannot succeed.
            foreach ($events as $event) {
                $this->queue->markFailed($event, $exception->getMessage(), true);
            }

            return;
        }

        $this->applyD6Response($domain, $events, $response);
    }

    /**
     * @param IngestEvent[] $events indexed 0..n-1 in send order
     * @param array<string, mixed> $response
     */
    private function applyD6Response(string $domain, array $events, array $response): void
    {
        $errorsByIndex = [];
        foreach ((array)($response['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['index'])) {
                $errorsByIndex[(int)$error['index']] = sprintf(
                    '%s: %s',
                    (string)($error['field'] ?? 'item'),
                    (string)($error['message'] ?? 'rejected by engine')
                );
            }
        }

        foreach ($events as $index => $event) {
            if (isset($errorsByIndex[$index])) {
                // Per-item validation error: terminal, the data won't improve
                // by resending the identical payload.
                $this->queue->markFailed($event, $errorsByIndex[$index], true);
            } else {
                $this->queue->markSent($event);
            }
        }

        if ($errorsByIndex) {
            $this->logger->info('Ingest batch had per-item errors', [
                'domain' => $domain,
                'errors' => count($errorsByIndex),
                'total' => count($events),
            ]);
        }

        // D6 invariant: processed + deduplicated + errors == total sent.
        $accounted = (int)($response['processed'] ?? 0)
            + (int)($response['deduplicated'] ?? 0)
            + count($errorsByIndex);
        if ($accounted !== count($events)) {
            $this->logger->info('D6 count invariant mismatch', [
                'domain' => $domain,
                'sent' => count($events),
                'accounted' => $accounted,
            ]);
        }
    }
}
