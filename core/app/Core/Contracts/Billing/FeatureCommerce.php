<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Editions\Community\NoFeatureCommerce;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * P1-13 extension point: selling features (upsell copy, buy paths, activation checklists, dormancy stamps). Billing
 * (ee) binds its catalog; Community sells nothing ({@see NoFeatureCommerce}).
 */
interface FeatureCommerce
{
    /** Can this key be bought (so a locked page links to a buy path)? */
    public function sellable(string $key): bool;

    /**
     * Every key the catalog sells.
     *
     * @return array<int, string>
     */
    public function sellableKeys(): array;

    /** SKU of the active add-on that sells this key on its own (à la carte), or null. */
    public function addonSkuFor(string $key): ?string;

    /** Is this app listing sold as a bundle (bought through add-on checkout, never installed directly)? */
    public function bundlesApp(int $appId): bool;

    /** Stamp "the owner used this feature now" (dormancy measurement). */
    public function touch(?User $owner, string $key): void;

    /**
     * Activation progress of one bought feature.
     *
     * @return array<string, mixed>
     */
    public function activationProgress(string $key, ?Store $store, ?User $owner = null): array;

    /**
     * Bought features whose activation is not finished.
     *
     * @return array<int|string, mixed>
     */
    public function activationOutstanding(?User $owner, ?Store $store): array;

    /**
     * P1-13 13g: the upsell of one key (label, blurb, buy channels), or null when nothing sells it.
     *
     * @return array<string, mixed>|null
     */
    public function upsell(string $key): ?array;

    /**
     * P1-13 13g: the quantity upsell for a plan limit the owner has reached (key + channels), or null.
     *
     * @return array<string, mixed>|null
     */
    public function limitOffer(string $type, ?User $owner): ?array;

    /** P1-13 13g: has the owner dismissed this spotlight surface of a key? */
    public function spotlightDismissed(int $userId, string $key, string $surface): bool;

    /** P1-13 13g: has the owner already seen this spotlight surface of a key? */
    public function spotlightSeen(int $userId, string $key, string $surface): bool;

    /**
     * P1-13 13g: store one spotlight event (user_id, feature_key, surface, action, meta); null when nothing records it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordSpotlight(array $attributes): ?Model;
}
