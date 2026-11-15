<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Services\GiftCardService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Keepsuit\Liquid\Drop;

/**
 * What a storefront needs to SELL a gift card.
 *
 * The platform already had the whole flow — `GiftCardService::initiate()`
 * makes the card and the transaction, the gateway takes the money, and the
 * payment confirmation funds and activates the card. What it lacked was a way
 * for a storefront to SHOW the gift cards on offer and take the purchase.
 * This drop is that way: the denominations the merchant configured, the
 * installed payment methods, the currency, and where to post the purchase.
 *
 * The REDEEM side is the runtime's own business (the `features.gift_cards`
 * flag draws the code field in the cart). This drop is the BUY side.
 */
class GiftCardsDrop extends Drop
{
    public function __construct(protected Store $store) {}

    /** Does this store sell gift cards at all? */
    public function enabled(): bool
    {
        return GiftCardService::enabled($this->store->id);
    }

    /**
     * The denominations the merchant offers.
     *
     * @return list<float>
     */
    public function presets(): array
    {
        return array_map(fn ($p) => (float) $p, GiftCardService::presets($this->store->id));
    }

    /** The smallest amount a card may carry. */
    public function min(): float
    {
        return (float) GiftCardService::minAmount($this->store->id);
    }

    /** The largest amount a card may carry. */
    public function max(): float
    {
        return (float) GiftCardService::maxAmount($this->store->id);
    }

    /** The currency the cards are denominated in. */
    public function currency(): string
    {
        return (string) GiftCardService::currencyFor($this->store->id);
    }

    /**
     * The store's installed customer payment methods, as [['id' => 'stripe', 'title' => 'Card'], …].
     *
     * @return list<array{id:string,title:string}>
     */
    public function gateways(): array
    {
        return app(CustomerGateways::class)->forStore($this->store->id)
            ->map(fn ($g) => [
                'id' => (string) $g->id,
                'title' => (string) ($g->payment_title ?: $g->payment_folder),
            ])
            ->values()
            ->all();
    }

    /** Where the purchase is posted. */
    public function url(): string
    {
        return route('store.giftcards.purchase', $this->store->id);
    }

    /** The CSRF token the form needs. */
    public function csrf(): string
    {
        // csrf_token() is null when there is no session (a CLI render, a test);
        // the form needs a string either way.
        return (string) csrf_token();
    }
}
