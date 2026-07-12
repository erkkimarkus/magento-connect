<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\PayloadRedactor;

class PayloadRedactorTest extends TestCase
{
    private PayloadRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new PayloadRedactor();
    }

    public function testEmptyAndNullInputYieldEmptyString(): void
    {
        self::assertSame('', $this->redactor->redact(null));
        self::assertSame('', $this->redactor->redact(''));
        self::assertSame('', $this->redactor->redact('   '));
    }

    public function testMasksEmailsInJsonValues(): void
    {
        $result = $this->redactor->redact(json_encode([
            'email' => 'erkki.markus@gmail.com',
            'nested' => ['contact' => 'jane.doe@example.co.uk'],
        ]));

        self::assertStringContainsString('e***@g***.com', $result);
        self::assertStringContainsString('j***@e***.uk', $result);
        self::assertStringNotContainsString('erkki.markus@gmail.com', $result);
        self::assertStringNotContainsString('jane.doe@example.co.uk', $result);
    }

    public function testRedactsSecretLookingKeysRecursively(): void
    {
        $result = $this->redactor->redact(json_encode([
            'password' => 'hunter2',
            'api_key' => 'sk_live_123',
            'apiKey' => 'sk_live_456',
            'auth' => ['token' => 'abc', 'authorization' => 'Bearer xyz'],
            'safe' => 'kept',
        ]));

        self::assertStringNotContainsString('hunter2', $result);
        self::assertStringNotContainsString('sk_live_123', $result);
        self::assertStringNotContainsString('sk_live_456', $result);
        self::assertStringNotContainsString('Bearer xyz', $result);
        self::assertStringContainsString('[redacted]', $result);
        self::assertStringContainsString('kept', $result);
    }

    public function testNonJsonInputGetsPlainTextEmailMasking(): void
    {
        $result = $this->redactor->redact('HTTP 500 while syncing customer john@shop.example.org to Smaily');

        self::assertSame('HTTP 500 while syncing customer j***@s***.org to Smaily', $result);
    }

    public function testNonSecretScalarsSurviveUntouched(): void
    {
        $result = $this->redactor->redact(json_encode([
            'quantity' => 3,
            'price' => 12.5,
            'active' => true,
            'note' => null,
        ]));

        $decoded = json_decode($result, true);
        self::assertSame(3, $decoded['quantity']);
        self::assertSame(12.5, $decoded['price']);
        self::assertTrue($decoded['active']);
        self::assertNull($decoded['note']);
    }

    public function testJsonOutputIsPrettyPrinted(): void
    {
        $result = $this->redactor->redact('{"a":{"b":1}}');

        self::assertStringContainsString("\n", $result);
        self::assertSame(['a' => ['b' => 1]], json_decode($result, true));
    }
}
