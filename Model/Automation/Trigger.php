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

    /**
     * Trigger slug => the Smaily contact field recording when this automation
     * last ran for the contact. Cross-platform canon (Erkki 2026-08-04): the
     * Woo and Shopify plugins write the exact same names, so a merchant segment
     * or a "got this letter X days ago" rule transfers between platforms. The
     * names are merchant-visible and permanent — add one, never repurpose one.
     *
     * The value is that run's own clock as `Y-m-d H:i:s` in UTC (the shape of
     * the only other date+time on the Smaily contact wire, and lexicographically
     * ordered, which is what lets a Smaily segment compare it against a date),
     * written on every run: last-writer-wins, meaning "this automation ran, most
     * recently at T" — not how the contact entered the list.
     *
     * A trigger writes only its own field. A trigger that did not fire sends no
     * marker at all: absent leaves whatever Smaily already holds intact, while
     * '' would wipe it. `is_abandoned_cart` keeps its template meaning untouched
     * — the abandoned-cart marker rides alongside it in the same payload.
     *
     * @var array<string, string>
     */
    public const MARKER_FIELDS = [
        self::WELCOME => 'welcome_automation_at',
        self::FIRST_ORDER => 'first_order_automation_at',
        self::ABANDONED_CART => 'abandoned_cart_automation_at',
    ];

    /**
     * The contact field recording that a shopper the store sent an
     * abandoned-cart reminder to has since bought (PRO-2453, Woo PRO-1723).
     *
     * It is not a trigger marker — no automation runs — so it lives outside
     * MARKER_FIELDS: the merchant's Smaily workflow reads it as the EXIT
     * condition of the reminder series ("`abandoned_cart_purchased_at` is
     * later than `abandoned_cart_automation_at`"), which is why it carries
     * the same UTC `Y-m-d H:i:s` shape as the markers above and must sort
     * against them. Merchant-visible and permanent, exactly like them.
     */
    public const ABANDONED_CART_PURCHASED_FIELD = 'abandoned_cart_purchased_at';
}
