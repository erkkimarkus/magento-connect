<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\TransportException;

/**
 * The one place that decides what happens to a Smaily queue row after a
 * failed send — the cross-platform classification the sibling plugins ship
 * (verified against Woo's `RetryPolicy`, its PRO-1685):
 *
 *  - PERMANENT (4xx except 429): stop on the FIRST refusal. The row is parked
 *    as failed with a `permanent_http_<code>` reason, so a refusal retrying
 *    cannot change (revoked credentials, a deleted workflow, a rejected
 *    payload) reaches the merchant's failed count now instead of six hours
 *    and four pointless re-POSTs later.
 *  - TEMPORARY (429, 5xx, transport error with no status): retry on the
 *    existing ladder (1m, 5m, 15m, 1h, 6h, then failed), spaced by Smaily's
 *    own Retry-After when a 429 named one.
 *
 * Biased toward retrying, like the sibling: anything without a recognisable
 * permanent status is temporary, because mis-classifying a recoverable
 * failure drops genuine work. A Smaily error envelope on HTTP 200
 * (ApiException) carries no HTTP status and so stays temporary, exactly as
 * before. Either way, the Log's Retry action is the recovery path for any
 * failed row.
 */
class RetryPolicy
{
    public function __construct(
        private readonly EventQueue $eventQueue
    ) {
    }

    /**
     * Advance one row after a failed send: park it for good, or reschedule it.
     */
    public function apply(Event $event, SmailyClientException $exception): void
    {
        $status = $exception instanceof TransportException ? $exception->getHttpStatus() : 0;

        if ($this->isPermanent($status)) {
            $this->eventQueue->markPermanentlyFailed(
                $event,
                sprintf('permanent_http_%d: %s', $status, $exception->getMessage())
            );

            return;
        }

        $this->eventQueue->markFailed(
            $event,
            $exception->getMessage(),
            null,
            null,
            $exception instanceof TransportException ? $exception->getRetryAfter() : null
        );
    }

    /**
     * Can this failure ever succeed on a retry? 4xx bar 429 says no — the
     * request itself is the problem. A transport error carries no status and
     * is treated as temporary.
     */
    private function isPermanent(int $status): bool
    {
        return $status >= 400 && $status < 500 && $status !== 429;
    }
}
