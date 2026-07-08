<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

/**
 * Store-event automation trigger types.
 */
class Trigger
{
    public const WELCOME = 'welcome';
    public const FIRST_ORDER = 'first_order';
    public const ABANDONED_CART = 'abandoned_cart';

    public const ALL = [
        self::WELCOME,
        self::FIRST_ORDER,
        self::ABANDONED_CART,
    ];
}
