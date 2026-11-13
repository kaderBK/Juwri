<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Models\Customer;
use App\Services\CustomerWalletService;
use Illuminate\Contracts\Auth\Authenticatable;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: the logged-in customer, nil for a guest. SECURITY-CRITICAL surface:
 * a theme may greet the diner by name, prefill checkout, and show their own
 * store-credit balance — nothing more. Exposes ONLY name + phone + store_credit
 * (the authed customer's own account-bound balance, D3) — never id, email,
 * password, tokens, or any other account attribute. The model is held protected
 * and never passed through.
 */
class CustomerDrop extends Drop
{
    public function __construct(protected Authenticatable $customer) {}

    public function name(): ?string
    {
        return $this->customer->name ?? null;
    }

    public function phone(): ?string
    {
        return $this->customer->phone ?? null;
    }

    /**
     * Coupons & Wallets P7-5a: the authed customer's own store-credit balance
     * (a float). Account-bound (D3) — this is the logged-in diner viewing their
     * own balance, so it is safe to render. `{% if customer.store_credit > 0 %}`
     * gates the pay-with-credit affordance.
     */
    public function store_credit(): float
    {
        if (! $this->customer instanceof Customer) {
            return 0.0;
        }

        return round(CustomerWalletService::balanceFor($this->customer), 2);
    }

    /** The same balance formatted with the restaurant's currency sign. */
    public function store_credit_formatted(): string
    {
        if (! $this->customer instanceof Customer) {
            return '';
        }

        return store_money_format($this->store_credit(), $this->customer->store_id);
    }
}
