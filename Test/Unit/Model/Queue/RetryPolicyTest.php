<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    private EventQueue&MockObject $eventQueue;

    private Event&MockObject $event;

    private RetryPolicy $policy;

    protected function setUp(): void
    {
        $this->eventQueue = $this->createMock(EventQueue::class);
        $this->event = $this->createMock(Event::class);
        $this->policy = new RetryPolicy($this->eventQueue);
    }

    /**
     * @return array<string, array{0: SmailyClientException, 1: int}>
     */
    public static function permanentRefusals(): array
    {
        return [
            'revoked credentials' => [new AuthenticationException('Rejected', 401), 401],
            'plan blocked' => [new AuthenticationException('Rejected', 403), 403],
            'workflow gone' => [new TransportException('Not found', 404), 404],
            'rejected payload' => [new TransportException('Unprocessable', 422), 422],
        ];
    }

    /**
     * @dataProvider permanentRefusals
     */
    public function testAPermanentRefusalStopsOnTheFirstAttempt(
        SmailyClientException $exception,
        int $status
    ): void {
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with(
                $this->event,
                sprintf('permanent_http_%d: %s', $status, $exception->getMessage()),
                null,
                null,
                null,
                true
            );

        $this->policy->apply($this->event, $exception);
    }

    public function testASlowDownParksTheRowForExactlyTheRequestedTime(): void
    {
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($this->event, 'Slow down', null, null, 120, false);

        $this->policy->apply($this->event, new TransportException('Slow down', 429, null, 120));
    }

    public function testASlowDownWithoutAHeaderFallsBackToTheLadder(): void
    {
        // A null retry-after leaves EventQueue on its own backoff ladder.
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($this->event, 'Slow down', null, null, null, false);

        $this->policy->apply($this->event, new TransportException('Slow down', 429));
    }

    public function testServerErrorsKeepTheLadder(): void
    {
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($this->event, 'Bad gateway', null, null, null, false);

        $this->policy->apply($this->event, new TransportException('Bad gateway', 502));
    }

    public function testTransportErrorsWithoutAStatusKeepTheLadder(): void
    {
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($this->event, 'Connection timed out', null, null, null, false);

        $this->policy->apply($this->event, new TransportException('Connection timed out'));
    }

    public function testASmailyErrorEnvelopeStaysRetryable(): void
    {
        // HTTP 200 with a non-101 body: no HTTP status to classify on, so the
        // pre-existing retrying behaviour is kept rather than guessed at.
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($this->event, 'Invalid data', null, null, null, false);

        $this->policy->apply($this->event, new ApiException('Invalid data', ApiException::CODE_INVALID_DATA));
    }

    public function testAMissingCredentialFailureStaysRetryable(): void
    {
        $this->eventQueue->expects(self::once())->method('markFailed');

        $this->policy->apply($this->event, new SmailyClientException('Credentials are not configured'));
    }
}
