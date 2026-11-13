<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Fulfillment;

use App\Services\Verticals\FulfillmentRegistry;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Support\PlanGate;

/**
 * rev/multi-vertical B1 — which fulfillment modes a store has switched ON.
 *
 * `FulfillmentRegistry->modesFor($store->store_type)` is the CEILING (what the
 * vertical allows at all); this service persists the store owner's SUBSET of
 * that ceiling (what they currently offer), via the frozen StoreSettingsService
 * write-seam (a plain post_option — never a fork of that class). A legacy
 * restaurant never calls this: its modes are on-table/takeaway/delivery/
 * room_service, toggled by the existing `restaurant_*_order` options, untouched.
 */
class FulfillmentSettingsService
{
    public const OPTION_KEY = 'fulfillment_enabled_modes';

    public function __construct(
        private FulfillmentRegistry $registry,
        private StoreSettingsService $storeSettings,
    ) {}

    /**
     * The modes this vertical could ever offer (registry ∩ vertical), in
     * declaration order.
     *
     * @return list<string>
     */
    public function availableModes(Store $store): array
    {
        return $this->registry->modesFor($store->store_type ?? null);
    }

    /**
     * The modes currently switched ON for this store, filtered to what the
     * vertical still allows (so a vertical change or a registry shrink never
     * leaves a stale mode enabled). Defaults to ALL available modes when the
     * owner has never touched this setting, so a freshly onboarded store is
     * immediately sellable.
     *
     * @return list<string>
     */
    public function enabledModes(Store $store): array
    {
        $available = $this->availableModes($store);
        $raw = store_options($store->id, self::OPTION_KEY);

        // StoreOption::getStoreOption() auto-json_decodes a JSON-looking value (a
        // stored ["ship","pickup_slot"] comes back as a PHP array already), a
        // missing option comes back false, and any other legacy shape is
        // ignored — all three fall back to "everything the vertical allows".
        $decoded = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        if (! is_array($decoded)) {
            return $available;
        }

        return array_values(array_intersect($available, $decoded));
    }

    /**
     * Pricing follow-ups (PRICING.md §3): the modes customers may order with right now — the switched-on modes less
     * `delivery` when the store's plan withholds delivery. The owner's saved choice is kept (enabledModes()), so an
     * upgrade brings delivery straight back.
     *
     * @return list<string>
     */
    public function offeredModes(Store $store): array
    {
        $modes = $this->enabledModes($store);
        if (in_array('delivery', $modes, true) && ! PlanGate::storeAllows($store, PlanGate::DELIVERY)) {
            $modes = array_values(array_diff($modes, ['delivery']));
        }

        return $modes;
    }

    public function isEnabled(Store $store, string $mode): bool
    {
        return in_array($mode, $this->offeredModes($store), true);
    }

    /**
     * Persist the owner's chosen subset. Silently drops anything outside the
     * vertical's ceiling (a tampered form field can never smuggle in a mode
     * the store isn't allowed) and returns the set that was actually saved.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public function update(Store $store, array $requested): array
    {
        $available = $this->availableModes($store);
        $filtered = array_values(array_intersect($available, $requested));

        $this->storeSettings->applyOptions($store, [
            self::OPTION_KEY => json_encode($filtered),
        ]);

        return $filtered;
    }
}
