<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue\Handler;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * Delivers queued identity-merge events (POST identity/merge, contract §7).
 */
class IdentityMergeHandler implements EventHandlerInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly EventQueue $eventQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $results = [];
        // Connectedness cannot change under us mid-batch; a refusal can (a
        // 403 on one row stops the rest), so only that half is re-asked.
        $connected = $this->settings->isConnected();
        foreach ($events as $event) {
            $id = (int)$event->getId();
            $refused = $this->settings->isRefused();
            if (!$connected || $refused) {
                $results[$id] = $refused
                    ? 'Campaign Intelligence account is not active'
                    : 'Campaign Intelligence is not connected';
                continue;
            }

            $payload = $this->eventQueue->decodePayload($event);
            if (empty($payload['customer_email'])) {
                $results[$id] = 'Malformed identity merge payload';
                continue;
            }

            try {
                $this->client->identityMerge($payload);
                $results[$id] = true;
            } catch (EngineRequestException $exception) {
                // 4xx is terminal for this payload; report and stop retrying
                // by letting the row exhaust naturally with the same message.
                $results[$id] = $exception->getMessage();
            } catch (EngineException $exception) {
                $results[$id] = $exception->getMessage();
            }
        }

        return $results;
    }
}
