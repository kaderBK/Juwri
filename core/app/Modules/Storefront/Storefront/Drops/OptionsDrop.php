<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * P2-2: the `options` global — the owner's storefront configuration.
 *
 * Source is the dynamic stdClass from `store_options($id)` (JSON-decoded
 * StoreOption rows). We expose a CLOSED, curated getter set rather than
 * passing the object through, so a new/unknown option row can never silently
 * leak to a theme. Anything not listed here is simply unreachable.
 */
class OptionsDrop extends Drop
{
    public function __construct(protected object $options) {}

    /** Not exposed (protected) — read a raw option with a default. */
    protected function opt(string $key, mixed $default = null): mixed
    {
        return $this->options->{$key} ?? $default;
    }

    // Currency
    public function currency_sign(): ?string
    {
        return $this->opt('currency_sign');
    }

    public function currency_code(): ?string
    {
        return $this->opt('currency_code');
    }

    /** 'left' | 'right' — symbol position. */
    public function currency_pos(): ?string
    {
        return $this->opt('currency_pos');
    }

    public function enable_multi_currency(): bool
    {
        return (bool) $this->opt('enable_multi_currency');
    }

    // Ordering / charges
    public function min_order_value(): float
    {
        return (float) $this->opt('min_order_value', 0);
    }

    public function delivery_charge(): float
    {
        return (float) $this->opt('restaurant_delivery_charge', 0);
    }

    public function tax_charge(): float
    {
        return (float) $this->opt('restaurant_taxx_charge', 0);
    }

    public function tax_label(): ?string
    {
        return $this->opt('restaurant_tax_label');
    }

    public function whatsapp_number(): ?string
    {
        return $this->opt('whatsapp_number');
    }

    // Layout / presentation
    public function menu_sections(): ?string
    {
        return $this->opt('menu_sections');
    }

    /** 'guest' | 'required' etc. — checkout identity mode. */
    public function customer_auth_mode(): ?string
    {
        return $this->opt('customer_auth_mode');
    }

    // Feature toggles (booleans the theme keys UI off)
    public function allow_tips(): bool
    {
        return (bool) $this->opt('allow_tips');
    }

    public function allow_coupons(): bool
    {
        return (bool) $this->opt('allow_coupons');
    }

    public function allow_call_waiter(): bool
    {
        return (bool) $this->opt('allow_call_waiter');
    }

    public function allow_order_scheduling(): bool
    {
        return (bool) $this->opt('allow_order_scheduling');
    }

    public function allow_multi_branch_switch(): bool
    {
        return (bool) $this->opt('allow_multi_branch_switch');
    }

    /** Default ON when never set — same rule as the Blade themes. */
    public function allow_dietary_filters(): bool
    {
        $v = $this->opt('allow_dietary_filters');

        return $v === null || $v == 1;
    }

    /**
     * The owner's manual open/closed switch: only an explicit '0' means closed.
     * (It used to read an unset key as closed, so every store that never
     * touched the switch showed "Closed" on the Liquid themes.)
     */
    public function open_close_store(): bool
    {
        $v = $this->opt('open_close_store');

        return $v === null || (string) $v !== '0';
    }
}
