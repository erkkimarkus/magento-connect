<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\PayloadAnonymizer;

/**
 * The matcher/anonymiser behind the Art. 17 erasure (PRO-2452): what counts
 * as this contact's row, and what a row carries afterwards.
 */
class PayloadAnonymizerTest extends TestCase
{
    private PayloadAnonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->anonymizer = new PayloadAnonymizer();
    }

    public function testMatchesAnAddressNestedAnywhereInThePayload(): void
    {
        $payload = (string)json_encode([
            'store_id' => 1,
            'contact' => ['email' => 'erase-test-1@example.test', 'name' => 'Test Erasure'],
        ]);

        self::assertTrue($this->anonymizer->matches($payload, 'erase-test-1@example.test'));
        self::assertFalse($this->anonymizer->matches($payload, 'erase-test-2@example.test'));
    }

    public function testMatchingIsCaseInsensitiveOnBothSides(): void
    {
        $payload = (string)json_encode(['contact' => ['email' => 'Erase-Test-1@Example.Test']]);

        self::assertTrue($this->anonymizer->matches($payload, 'ERASE-TEST-1@example.test'));
    }

    /**
     * The sibling's open PRO-2448: json_encode escapes non-ASCII by default,
     * so the address reaches the column with its umlauts as backslash-u
     * sequences and a raw substring search finds nothing. Decoding does.
     */
    public function testMatchesAnAddressJsonEscapedByNonAsciiCharacters(): void
    {
        $email = 'mõni@näide.test';
        $payload = (string)json_encode(['contact' => ['email' => $email, 'name' => 'Tõnu Käär']]);

        self::assertStringNotContainsString($email, $payload, 'The address is escaped in the stored bytes');
        self::assertTrue($this->anonymizer->matches($payload, $email));
    }

    public function testMatchesAnAddressQuotedInsideALongerValue(): void
    {
        $response = (string)json_encode(['message' => 'Rejected recipient erase-test-1@example.test (bounced)']);

        self::assertTrue($this->anonymizer->matches($response, 'erase-test-1@example.test'));
    }

    public function testFallsBackToAPlainTextSearchForNonJsonBlobs(): void
    {
        $error = 'HTTP 422: erase-test-1@example.test is not a valid contact';

        self::assertTrue($this->anonymizer->matches($error, 'erase-test-1@example.test'));
        self::assertFalse($this->anonymizer->matches($error, 'erase-test-2@example.test'));
    }

    public function testEmptyInputNeverMatches(): void
    {
        self::assertFalse($this->anonymizer->matches(null, 'erase-test-1@example.test'));
        self::assertFalse($this->anonymizer->matches('', 'erase-test-1@example.test'));
        self::assertFalse($this->anonymizer->matches('{"email":"erase-test-1@example.test"}', '  '));
    }

    public function testAnonymizingKeepsTheKeysAndTheStructureAndValidJson(): void
    {
        $payload = (string)json_encode([
            'store_id' => 1,
            'contact' => ['email' => 'erase-test-1@example.test', 'name' => 'Test Erasure'],
            'items' => [['sku' => 'TEST-1', 'qty' => 2]],
        ]);

        $anonymized = (string)$this->anonymizer->anonymize($payload);
        $decoded = json_decode($anonymized, true);

        self::assertSame([
            'store_id' => '[erased]',
            'contact' => ['email' => '[erased]', 'name' => '[erased]'],
            'items' => [['sku' => '[erased]', 'qty' => '[erased]']],
        ], $decoded);
    }

    public function testAnAnonymizedPayloadNoLongerMatchesTheContact(): void
    {
        $payload = (string)json_encode(['contact' => ['email' => 'mõni@näide.test']]);
        $anonymized = (string)$this->anonymizer->anonymize($payload);

        self::assertFalse($this->anonymizer->matches($anonymized, 'mõni@näide.test'));
    }

    public function testAnUndecodableBlobIsReplacedWholesale(): void
    {
        self::assertSame(
            PayloadAnonymizer::ERASED_PLACEHOLDER,
            $this->anonymizer->anonymize('<html>erase-test-1@example.test</html>')
        );
    }

    public function testNullAndEmptyBlobsAreLeftAlone(): void
    {
        self::assertNull($this->anonymizer->anonymize(null));
        self::assertSame('', $this->anonymizer->anonymize(''));
    }
}
