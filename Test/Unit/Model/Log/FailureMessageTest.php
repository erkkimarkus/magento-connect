<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;

class FailureMessageTest extends TestCase
{
    private FailureMessage $failureMessage;

    protected function setUp(): void
    {
        $this->failureMessage = new FailureMessage(new PayloadRedactor());
    }

    public function testShowsTheServerMessageWithoutTheInternalPrefix(): void
    {
        self::assertSame(
            'Invalid credentials for subdomain demo.',
            $this->failureMessage->forDisplay(
                'permanent_http_401: Invalid credentials for subdomain demo.'
            )
        );
    }

    public function testKeepsTheInternalClassForTheDrawer(): void
    {
        self::assertSame(
            'permanent_http_401',
            $this->failureMessage->failureClass('permanent_http_401: Invalid credentials.')
        );
    }

    public function testRetryableFailuresKeepTheirWording(): void
    {
        self::assertSame(
            'Connection timed out after 30s',
            $this->failureMessage->forDisplay('Connection timed out after 30s')
        );
        self::assertSame('', $this->failureMessage->failureClass('Connection timed out after 30s'));
    }

    public function testMasksContactsQuotedByTheServer(): void
    {
        self::assertSame(
            'Address j***@e***.com is not valid',
            $this->failureMessage->forDisplay(
                'permanent_http_400: Address jane.doe@example.com is not valid'
            )
        );
    }

    public function testEmptyErrorStaysEmpty(): void
    {
        self::assertSame('', $this->failureMessage->forDisplay(null));
        self::assertSame('', $this->failureMessage->forDisplay('   '));
        self::assertSame('', $this->failureMessage->failureClass(null));
    }
}
