<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

/**
 * Matching and anonymisation of the blobs a queue row stores — the queued
 * payload, the payload as sent and the last API response — for an Art. 17
 * erasure (PRO-2452).
 *
 * **Matching decodes first.** A stored payload is JSON, and PHP's json_encode
 * escapes non-ASCII by default: the `õ` of `mõni@näide.test` reaches the
 * column as a `\u00f5` sequence, so a raw substring search on the address
 * silently misses the row (the sibling's open PRO-2448). Comparison
 * therefore happens on the DECODED values, where the address is itself
 * again. Only a blob that is not JSON at all (a raw error body, an HTML
 * response) is searched as plain text — there is no escaping there to
 * defeat it.
 *
 * **Anonymisation is an allowlist by construction.** Every scalar is
 * replaced with the placeholder; the keys and the structure survive, so the
 * Log drill-down still shows the shape of what went out. A payload field
 * added later cannot leak by simply not being on a denylist.
 */
class PayloadAnonymizer
{
    /**
     * What an erased field carries afterwards. A fixed non-address string,
     * so nothing downstream can read a recipient back out of a row the
     * subject asked us to forget.
     */
    public const ERASED_PLACEHOLDER = '[erased]';

    /**
     * Whether a stored blob mentions this contact.
     */
    public function matches(?string $stored, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || $stored === null || trim($stored) === '') {
            return false;
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return mb_stripos($stored, $email) !== false;
        }

        return $this->containsEmail($decoded, $email);
    }

    /**
     * Replace every value in a stored blob with the placeholder, keeping the
     * keys and the structure. A blob that is not decodable JSON is replaced
     * wholesale — it may be anything, so nothing in it can be assumed
     * impersonal.
     */
    public function anonymize(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return $stored;
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return self::ERASED_PLACEHOLDER;
        }

        $encoded = json_encode($this->redact($decoded), JSON_UNESCAPED_SLASHES);

        return $encoded === false ? self::ERASED_PLACEHOLDER : $encoded;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function containsEmail(array $data, string $email): bool
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                if ($this->containsEmail($value, $email)) {
                    return true;
                }
                continue;
            }
            if (is_string($value) && mb_stripos($value, $email) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function redact(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? $this->redact($value) : self::ERASED_PLACEHOLDER;
        }

        return $result;
    }
}
