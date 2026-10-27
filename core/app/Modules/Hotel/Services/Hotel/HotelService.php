<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use Juwri\Core\Models\Store;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Ordering\Services\MealPlanService;

/**
 * FEATURES-RUN-4 T1 (hotel): per-restaurant hotel-module config. Following the
 * same pattern as {@see MealPlanService::enabled()} / inventory_mode / kds_mode,
 * the flags live in `store_options` (see Helper::store_options):
 *   - `hotel_mode`        0/1 master enable (D6 — also plan-gated in H11)
 *   - `hotel_tray_charge` amount or "%"-suffixed percent auto-added at checkout (H8)
 *   - `hotel_settings`    JSON: wifi, house_rules, breakfast window/cutoff, departments map
 *
 * Money movement / order wiring is later rows (H2, H6, H8); this is the read layer.
 */
class HotelService
{
    /**
     * Is the hotel module live for this restaurant? H11 gates on BOTH layers:
     *   1. Owner toggle — the `hotel_mode` post-option the owner flips in Hotel
     *      settings (and the superadmin global kill-switch `admin_allow_hotel`).
     *   2. Superadmin per-plan feature flag — the restaurant's plan must grant the
     *      `hotel` entitlement ({@see \App\Services\FeatureRegistry} 'hotel'), the
     *      same plan-features pattern as ai_phone_agent / kiosk / etc.
     * Both must be true; otherwise all hotel nav/UI/routes hide or 403.
     */
    public static function enabled($storeId): bool
    {
        $rid = is_object($storeId) ? ($storeId->id ?? null) : $storeId;
        if (! $rid) {
            return false;
        }

        // 1. Owner master toggle.
        $v = store_options($rid, 'hotel_mode');
        if (is_bool($v)) {
            $ownerOn = $v;
        } else {
            $v = strtolower(trim((string) $v));
            $ownerOn = in_array($v, ['1', 'yes', 'true', 'on'], true);
        }
        if (! $ownerOn) {
            return false;
        }

        // 2. Superadmin per-plan feature flag (kill-switch + plan entitlement).
        return static::planAllows((int) $rid);
    }

    /**
     * H11 — is the hotel module AVAILABLE to this restaurant (plan grants it),
     * regardless of the owner's on/off toggle? Drives the "Hotel settings" entry
     * point so an owner whose plan includes hotel can find the master toggle even
     * while hotel_mode is still off. The full module (rooms/stays/requests/info)
     * uses {@see enabled()} instead (available AND toggled on).
     */
    public static function available($storeId): bool
    {
        $rid = is_object($storeId) ? ($storeId->id ?? null) : $storeId;
        if (! $rid) {
            return false;
        }

        return static::planAllows((int) $rid);
    }

    /**
     * Wind-down: is there still hotel money/state to drain even though the module
     * is no longer {@see enabled()} (owner turned hotel_mode off, or the plan lost
     * the `hotel` entitlement) mid-stay? True while any stay is still checked in.
     *
     * Guards the DRAIN actions only — view stays/statement, settle, check-out — so
     * an owner can always close out and refund existing folios after a downgrade;
     * it never re-opens check-in, manual charges or new room-service. Returns false
     * for a store that never ran hotel (no open stays), which then 403s as before.
     *
     * @param  \Juwri\Core\Models\Store|int  $storeId
     */
    public static function canWindDown($storeId): bool
    {
        $rid = is_object($storeId) ? ($storeId->id ?? null) : $storeId;
        if (! $rid) {
            return false;
        }

        return HotelStay::where('store_id', (int) $rid)
            ->where('status', 'checked_in')
            ->exists();
    }

    /**
     * Whether the restaurant's owner plan grants the `hotel` feature (H11). Uses
     * the shared plan_feature() gate (global kill-switch → plan entitlement). Resolves
     * the owner from the Post so it works in guest/unauthenticated contexts too.
     */
    protected static function planAllows(int $storeId): bool
    {
        try {
            $owner = Store::find($storeId)?->user;
            if (! $owner) {
                return false;
            }

            return plan_feature('hotel', $owner);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tray / service charge applied to room-service orders (H8). Stored as a
     * bare amount ("5") or a percent ("10%"). Returns ['type'=>'amount'|'percent','value'=>float].
     */
    public static function trayCharge($storeId): array
    {
        $raw = trim((string) store_options($storeId, 'hotel_tray_charge'));
        if ($raw === '') {
            return ['type' => 'amount', 'value' => 0.0];
        }
        if (str_ends_with($raw, '%')) {
            return ['type' => 'percent', 'value' => (float) rtrim($raw, '% ')];
        }

        return ['type' => 'amount', 'value' => (float) $raw];
    }

    /**
     * The `hotel_settings` JSON as an array (wifi, house_rules, breakfast,
     * departments map). Always returns an array — never null.
     */
    public static function settings($storeId): array
    {
        $raw = store_options($storeId, 'hotel_settings');

        // A JSON string still needs decoding; an object/array from getStoreOption
        // (which json_decode()s WITHOUT assoc) is only shallow — nested keys like
        // `wifi` / `departments` stay stdClass. Normalise to a fully-associative
        // array so callers can safely use `$settings['wifi']['name']`.
        if (is_string($raw)) {
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($raw) || is_array($raw)) {
            $decoded = json_decode(json_encode($raw), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
