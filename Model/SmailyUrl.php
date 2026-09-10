<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * The Smaily host for one account subdomain, built in exactly one place so
 * the API client's base URI and the admin's account link cannot drift apart.
 */
class SmailyUrl
{
    /**
     * https://{subdomain}.sendsmaily.net — no trailing slash; callers that
     * need one (a Guzzle base URI) add it.
     */
    public static function forSubdomain(string $subdomain): string
    {
        return 'https://' . $subdomain . '.sendsmaily.net';
    }
}
