<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

/**
 * Prepares stored queue payloads/responses for display in the admin log
 * drill-down. Two rules, applied recursively:
 *
 * - values under secret-looking keys (password, token, api key, ...) are
 *   never shown — replaced with "[redacted]";
 * - email addresses anywhere in string values are masked to their first
 *   characters ("e***@g***.com"), enough to recognize a contact without
 *   exposing the address.
 *
 * Non-JSON input (a raw error body, an HTML response) gets the same email
 * masking as plain text.
 */
class PayloadRedactor
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_PATTERN =
        '/pass|secret|token|api[_-]?key|authorization|signature|credential/i';

    private const EMAIL_PATTERN =
        '/[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    /**
     * Redact a stored payload/response string for display. JSON is walked
     * recursively and re-encoded pretty-printed; anything else is treated
     * as plain text.
     */
    public function redact(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->redactDecoded($decoded) : $this->maskEmails($raw);
    }

    /**
     * The same for a payload the caller has already decoded — the Details
     * drawer decodes the stored payload once and hands it straight over.
     *
     * @param array<int|string, mixed> $data
     */
    public function redactDecoded(array $data): string
    {
        $encoded = json_encode(
            $this->redactArray($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? self::REDACTED : $encoded;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
                continue;
            }
            $result[$key] = is_string($value) ? $this->maskEmails($value) : $value;
        }

        return $result;
    }

    /**
     * Mask every email address in a string: first character of the local
     * part and of the domain are kept, the top-level domain stays readable.
     */
    private function maskEmails(string $text): string
    {
        return (string)preg_replace_callback(
            self::EMAIL_PATTERN,
            static function (array $match): string {
                [$local, $domain] = explode('@', $match[0], 2);
                $labels = explode('.', $domain);
                $tld = array_pop($labels);

                return mb_substr($local, 0, 1) . '***@'
                    . mb_substr((string)($labels[0] ?? ''), 0, 1) . '***.' . $tld;
            },
            $text
        );
    }
}
