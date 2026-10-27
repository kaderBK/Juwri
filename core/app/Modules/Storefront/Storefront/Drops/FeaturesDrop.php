<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * Theme Studio B0.6: the `features` global — which storefront features this
 * store actually offers, computed with the SAME options + plan gates the Blade
 * storefront uses (StorefrontExtras::features), so a Liquid theme never shows
 * a feature the plan or the owner switched off. Booleans only.
 */
class FeaturesDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    /** Table reservations are on. */
    public function reservations(): bool
    {
        return (bool) ($this->data['reservations'] ?? false);
    }

    /** The walk-in waitlist is on. */
    public function waitlist(): bool
    {
        return (bool) ($this->data['waitlist'] ?? false);
    }

    /** A loyalty program is active and the plan allows it. */
    public function loyalty(): bool
    {
        return (bool) ($this->data['loyalty'] ?? false);
    }

    /** Gift cards are on. */
    public function gift_cards(): bool
    {
        return (bool) ($this->data['gift_cards'] ?? false);
    }

    /** Catering requests are on. */
    public function catering(): bool
    {
        return (bool) ($this->data['catering'] ?? false);
    }

    /** Meal plans are on. */
    public function meal_plans(): bool
    {
        return (bool) ($this->data['meal_plans'] ?? false);
    }

    /** Curbside pickup is on. */
    public function curbside(): bool
    {
        return (bool) ($this->data['curbside'] ?? false);
    }

    /** Diners can switch display currency. */
    public function multi_currency(): bool
    {
        return (bool) ($this->data['multi_currency'] ?? false);
    }

    /** Dietary filter chips are on (default on). */
    public function dietary_filters(): bool
    {
        return (bool) ($this->data['dietary_filters'] ?? false);
    }

    /** Call-waiter is on and the diner is at a table. */
    public function call_waiter(): bool
    {
        return (bool) ($this->data['call_waiter'] ?? false);
    }

    /** Coupons can be applied at checkout. */
    public function coupons(): bool
    {
        return (bool) ($this->data['coupons'] ?? false);
    }

    /** Tips are on. */
    public function tips(): bool
    {
        return (bool) ($this->data['tips'] ?? false);
    }

    /** Orders can be scheduled. */
    public function scheduling(): bool
    {
        return (bool) ($this->data['scheduling'] ?? false);
    }

    /** Store credit wallet is available. */
    public function customer_wallet(): bool
    {
        return (bool) ($this->data['customer_wallet'] ?? false);
    }

    /** Public store reviews are shown. */
    public function store_reviews(): bool
    {
        return (bool) ($this->data['store_reviews'] ?? false);
    }

    /** A Google review link is set. */
    public function google_review(): bool
    {
        return (bool) ($this->data['google_review'] ?? false);
    }

    /** Vertical capability: products with variants (retail/cosmetic/clothing/carparts/pharmacy). */
    public function variant_goods(): bool
    {
        return (bool) ($this->data['variant_goods'] ?? false);
    }

    /** Vertical capability: items carry expiry dates. */
    public function expiry(): bool
    {
        return (bool) ($this->data['expiry'] ?? false);
    }

    /** Vertical capability: age-restricted items. */
    public function age_gate(): bool
    {
        return (bool) ($this->data['age_gate'] ?? false);
    }

    /** Vertical capability: vehicle fitment search. */
    public function fitment(): bool
    {
        return (bool) ($this->data['fitment'] ?? false);
    }

    /** Vertical capability: bookable services. */
    public function service(): bool
    {
        return (bool) ($this->data['service'] ?? false);
    }
}
