<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Services\Pharmacy;

use Illuminate\Database\Eloquent\Collection;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Support\MessageDiscretion;
use Juwri\Modules\Menu\Models\Menu;

/**
 * rev/multi-vertical Lane F2 — OTC-only (D3) batch/expiry housekeeping for a
 * pharmacy (or any future `expiry`-capable vertical) store.
 *
 * The "hide-when-expired" setting is a plain post_option written through the
 * FROZEN {@see StoreSettingsService} write-seam (never forked — mirrors
 * FulfillmentSettingsService's exact pattern from Lane B1), so it drifts
 * through the same audit/cache-bust path as every other store setting.
 *
 * Hiding itself reuses the EXISTING `active` flag the storefront query
 * (User\StoreController::publicView) already filters on — no change to that
 * shared, food-adjacent controller or to Lane C's frozen `shelf` theme is
 * needed for an expired item to disappear from the grid.
 */
class PharmacyComplianceService
{
    public const OPTION_HIDE_EXPIRED = 'pharmacy_hide_expired_items';

    /**
     * F3 — the storefront OTC disclaimer text. Configurable per store (owner
     * can override in the Compliance settings panel); falls back to a sane
     * default ("not medical advice", explicitly OTC/no-Rx) when unset.
     */
    public const OPTION_DISCLAIMER_TEXT = 'pharmacy_disclaimer_text';

    /**
     * F3 — whether the one-time, blocking OTC notice appears as a store-ENTRY
     * interstitial. Owner-toggleable; defaults ON (compliance-safe). Turning it
     * off only removes the blocking pop-up — the always-on inline shelf banner
     * (same `disclaimerText()`) remains as the compliance floor.
     */
    public const OPTION_SHOW_ENTRY_GATE = 'pharmacy_show_entry_gate';

    public function __construct(private StoreSettingsService $storeSettings) {}

    public function hideExpiredEnabled(Store $store): bool
    {
        return filter_var(store_options($store->id, self::OPTION_HIDE_EXPIRED, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function setHideExpiredEnabled(Store $store, bool $enabled): void
    {
        $this->storeSettings->applyOptions($store, [
            self::OPTION_HIDE_EXPIRED => $enabled ? '1' : '0',
        ]);
    }

    /** F3: the disclaimer text to render on the storefront (owner-configurable). */
    public function disclaimerText(Store $store): string
    {
        $custom = trim((string) store_options($store->id, self::OPTION_DISCLAIMER_TEXT, ''));

        return $custom !== '' ? $custom : ___('This store sells over-the-counter (OTC) products only. This information is not medical advice — read the label and consult a healthcare professional before use.');
    }

    public function setDisclaimerText(Store $store, ?string $text): void
    {
        $this->storeSettings->applyOptions($store, [
            self::OPTION_DISCLAIMER_TEXT => trim((string) $text),
        ]);
    }

    /**
     * F3: whether to show the blocking store-entry OTC interstitial. Defaults to
     * true (compliance-safe) when the owner has never set it.
     */
    public function entryGateEnabled(Store $store): bool
    {
        return filter_var(store_options($store->id, self::OPTION_SHOW_ENTRY_GATE, true), FILTER_VALIDATE_BOOLEAN);
    }

    /** P6-46: customer messages say "N items" instead of medicine names. Default ON for pharmacies. */
    public function discreetMessagesEnabled(Store $store): bool
    {
        return MessageDiscretion::enabled($store);
    }

    public function setDiscreetMessagesEnabled(Store $store, bool $enabled): void
    {
        $this->storeSettings->applyOptions($store, [
            MessageDiscretion::OPTION => $enabled ? '1' : '0',
        ]);
    }

    public function setEntryGateEnabled(Store $store, bool $enabled): void
    {
        $this->storeSettings->applyOptions($store, [
            self::OPTION_SHOW_ENTRY_GATE => $enabled ? '1' : '0',
        ]);
    }

    /**
     * Called after any write that could have changed `expiry_date`. If the
     * item is now expired AND the store opted into hiding, deactivate it
     * (removing it from `visibleOnStorefront()`+`active` storefront queries)
     * exactly like a manual unpublish would. Never auto-REACTIVATES an item
     * (an owner who manually deactivated something must not be silently
     * un-deactivated by a date change) — v1 scope, documented in COMPLETION.md.
     */
    public function applyHideIfExpired(Menu $item, Store $store): Menu
    {
        if ($item->is_expired && $this->hideExpiredEnabled($store) && (string) $item->active === '1') {
            $item->update(['active' => 0]);
        }

        return $item->fresh();
    }

    /**
     * Sweep every expired, still-active item for one opted-in store. Meant to
     * run from `pharmacy:sweep-expired` (owner-scheduled) so a store's catalog
     * empties out on its own overnight rather than only at the next manual
     * edit. Returns how many items were hidden.
     */
    public function sweepStore(Store $store): int
    {
        if (! $this->hideExpiredEnabled($store)) {
            return 0;
        }

        $items = Menu::where('store_id', $store->id)
            ->where('active', '1')
            ->expired()
            ->get();

        foreach ($items as $item) {
            $item->update(['active' => 0]);
        }

        return $items->count();
    }

    /** @return Collection<int, Menu> */
    public function expiredItems(Store $store): Collection
    {
        return Menu::where('store_id', $store->id)->expired()->orderBy('expiry_date')->get();
    }

    /** @return Collection<int, Menu> */
    public function expiringSoon(Store $store, int $days = 30): Collection
    {
        return Menu::where('store_id', $store->id)->expiringWithin($days)->orderBy('expiry_date')->get();
    }
}
