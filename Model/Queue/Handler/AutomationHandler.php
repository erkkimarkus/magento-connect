<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Delivers queued automation.trigger events via POST /api/autoresponder.php.
 *
 * Event payload shape:
 * {trigger_type, store_id, website_id, language, address: {email, ...fields}}.
 *
 * Events are dispatched one by one (never batched) so a partial failure
 * cannot re-trigger an automation for an address that already received it.
 * A missing workflow mapping is a terminal skip, not a failure — retrying
 * cannot make a mapping appear (matches the Woo AutomationRouter contract).
 */
class AutomationHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly SmailyClientProvider $clientProvider,
        private readonly Router $router,
        private readonly Mode $mode,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $id = (int)$event->getId();
            $payload = $this->eventQueue->decodePayload($event);

            $trigger = (string)($payload['trigger_type'] ?? '');
            $address = $payload['address'] ?? null;
            if ($trigger === '' || !is_array($address) || empty($address['email'])) {
                $results[$id] = 'Malformed automation payload';
                continue;
            }

            $websiteId = (int)($payload['website_id'] ?? 0);
            $workflowId = $this->router->resolveWorkflowId(
                $trigger,
                $websiteId,
                (string)($payload['language'] ?? '')
            );
            if ($workflowId <= 0) {
                // Terminal skip: no workflow mapped for this trigger/language.
                $this->logger->debug('Automation skipped, no workflow mapped', [
                    'trigger' => $trigger,
                    'website_id' => $websiteId,
                ]);
                $results[$id] = true;
                continue;
            }

            try {
                $storeId = (int)($payload['store_id'] ?? 0);
                $this->clientProvider->forStore($storeId ?: null)->post(SmailyClient::ENDPOINT_AUTORESPONDER, [
                    'autoresponder' => $workflowId,
                    'addresses' => [$address],
                    'force_opt_in' => $this->mode->automationForceOptIn($websiteId),
                ]);
                $results[$id] = true;
            } catch (SmailyClientException $exception) {
                $results[$id] = $exception->getMessage();
            }
        }

        return $results;
    }
}
