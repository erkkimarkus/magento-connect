<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Api\Queue;

use Smaily\Connect\Model\Queue\Event;

/**
 * Handles a batch of queued marketing events of a single event type.
 *
 * Implementations are registered in the Smaily\Connect\Model\Queue\HandlerPool
 * via di.xml, keyed by event type.
 */
interface EventHandlerInterface
{
    /**
     * Process a batch of events.
     *
     * @param Event[] $events all of the same event type
     * @return array<int, true|string> map of queue row ID to true on success,
     *         or an error message for a retryable failure
     */
    public function handle(array $events): array;
}
