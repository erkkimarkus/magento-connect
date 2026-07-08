<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\BrowseEventValidator;

class BrowseEventValidatorTest extends TestCase
{
    private const UUID = '9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c';

    private BrowseEventValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BrowseEventValidator();
    }

    public function testValidProductViewPassesWithServerStampedFields(): void
    {
        $clean = $this->validator->sanitize([
            'event_id' => strtoupper(self::UUID),
            'session_id' => 'sess-123',
            'event_type' => 'product_view',
            'sku' => 'ABC-1',
            'smaily_ctx' => 'ctx-9',
            'source' => 'spoofed-source',
            'event_ts' => '1999-01-01T00:00:00Z',
            'unknown_key' => 'dropped',
        ]);

        self::assertNotNull($clean);
        self::assertSame(self::UUID, $clean['event_id']);
        self::assertSame('plugin_magento', $clean['source']);
        self::assertNotSame('1999-01-01T00:00:00Z', $clean['event_ts']);
        self::assertSame('ABC-1', $clean['sku']);
        self::assertSame('ctx-9', $clean['smaily_ctx']);
        self::assertArrayNotHasKey('unknown_key', $clean);
    }

    public function testInvalidUuidRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => 'not-a-uuid',
            'session_id' => 's',
            'event_type' => 'product_view',
        ]));
    }

    public function testUnknownEventTypeRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => self::UUID,
            'session_id' => 's',
            'event_type' => 'page_view',
        ]));
    }

    public function testMissingSessionRejected(): void
    {
        self::assertNull($this->validator->sanitize([
            'event_id' => self::UUID,
            'event_type' => 'search',
        ]));
    }

    public function testClientAssertedEmailIsRejectedAndDwellCoerced(): void
    {
        $clean = $this->validator->sanitize([
            'event_id' => self::UUID,
            'session_id' => 's1',
            'event_type' => 'search',
            'search_query' => 'kassitoit',
            'customer_email' => 'spoofed@example.com',
            'external_id' => '42',
            'dwell_seconds' => '12',
        ]);

        self::assertNotNull($clean);
        // Identity must never be client-asserted on the anonymous beacon.
        self::assertArrayNotHasKey('customer_email', $clean);
        self::assertSame('42', $clean['external_id']);
        self::assertSame(12, $clean['dwell_seconds']);
        self::assertSame('kassitoit', $clean['search_query']);
    }
}
