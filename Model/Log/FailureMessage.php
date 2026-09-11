<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

/**
 * What a failed row says to the merchant (PRO-2454).
 *
 * A terminal refusal is stored as `permanent_http_<code>: <server message>`
 * (Model\Queue\RetryPolicy) — the classification is ours, the sentence after
 * it is Smaily's own. The merchant is shown the server's sentence, redacted
 * exactly like the payload beside it; the classification stays for the
 * Details drawer, where the technical detail belongs. A retryable failure
 * carries no prefix and is shown as it is.
 */
class FailureMessage
{
    /** What RetryPolicy prepends to a refusal it parked on the spot. */
    private const CLASS_PATTERN = '/^(permanent_http_\d+):\s*/';

    public function __construct(
        private readonly PayloadRedactor $redactor
    ) {
    }

    /**
     * The merchant-facing wording of a stored `last_error`: the server's own
     * message where there is one, with PII masked.
     */
    public function forDisplay(?string $lastError): string
    {
        $error = trim((string)$lastError);
        if ($error === '') {
            return '';
        }

        return $this->redactor->redact(
            (string)preg_replace(self::CLASS_PATTERN, '', $error)
        );
    }

    /**
     * The internal failure class of a stored `last_error`, or '' when the
     * failure was not classified as permanent.
     */
    public function failureClass(?string $lastError): string
    {
        preg_match(self::CLASS_PATTERN, trim((string)$lastError), $matches);

        return $matches[1] ?? '';
    }
}
