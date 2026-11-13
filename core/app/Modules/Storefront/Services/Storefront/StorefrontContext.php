<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Models\WaitlistEntry;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Customers\Models\CustomerToken;

/**
 * P9: the ONE place the storefront themes read their per-request engagement
 * context (loyalty program, the signed-in diner's live waitlist/reservation, a
 * minted customer API token). Previously each of the 17 keep-list themes ran
 * these queries inline in its `index.blade.php` @php header — the same reads,
 * duplicated per theme, outside the controller's eager-load set.
 *
 * Reads are memoized per request and bound to the current app instance (mirrors
 * {@see StoreOption::scopeMemo()}), so a fresh test/app boot starts
 * clean and a repeated read in a partial is free rather than a second query.
 * Obtain it in a blade with the {@see storefront()} helper.
 */
class StorefrontContext
{
    /** @var array<string,mixed> app-bound per-request memo */
    protected static array $memo = [];

    protected static ?int $memoApp = null;

    protected static function scope(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$memo = [];
        }
    }

    /** The store's active loyalty program (or null). */
    public function loyaltyProgram(Store $store): ?LoyaltyProgram
    {
        self::scope();
        $key = 'loyalty:'.$store->id;
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = LoyaltyProgram::where('store_id', $store->id)
                ->where('active', true)->first();
        }

        return self::$memo[$key];
    }

    /** The signed-in diner's live "waiting" waitlist entry for this store (or null). */
    public function activeWaitlist(Store $store, ?Customer $customer): ?WaitlistEntry
    {
        if (! $customer || ! $customer->phone) {
            return null;
        }
        self::scope();
        $key = 'waitlist:'.$store->id.':'.$customer->id;
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = WaitlistEntry::where('store_id', $store->id)
                ->where('phone', $customer->phone)
                ->where('status', 'waiting')->latest()->first();
        }

        return self::$memo[$key];
    }

    /** The signed-in diner's next pending/confirmed reservation for this store (or null). */
    public function upcomingReservation(Store $store, ?Customer $customer): ?Reservation
    {
        if (! $customer || ! $customer->phone) {
            return null;
        }
        self::scope();
        $key = 'reservation:'.$store->id.':'.$customer->id;
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = Reservation::where('store_id', $store->id)
                ->where('phone', $customer->phone)
                ->whereIn('status', ['pending', 'confirmed'])
                ->where('reserved_at', '>=', now(store_tz($store->id))) // wall-clock: store's own now
                ->orderBy('reserved_at')->first();
        }

        return self::$memo[$key];
    }

    /**
     * A raw customer API token for the signed-in session diner, minted once and
     * session-cached so the theme JS can call the CustomerToken-guarded routes.
     * Returns null for a guest. `$source` names the issuing theme on the token.
     */
    public function customerApiToken(?Customer $customer, string $source): ?string
    {
        if (! $customer) {
            return null;
        }

        $sessKey = 'cv_customer_api_token_'.$customer->id;
        $cached = session($sessKey);
        if ($cached) {
            $tok = CustomerToken::where('token', hash('sha256', $cached))->first();
            if (! $tok || $tok->isExpired()) {
                $cached = null;
            }
        }
        if (! $cached) {
            $cached = CustomerToken::issue($customer, $source.'-storefront', 30);
            session([$sessKey => $cached]);
        }

        return $cached;
    }

    /**
     * The branch on a hotel-guest's QR/room, scoped to the store (or null). Used
     * by the shared hotel-guest room-service partial to align hours + capacity.
     */
    public function hotelBranch(?int $branchId, Store $store): ?Branch
    {
        if (! $branchId || $branchId <= 0) {
            return null;
        }
        self::scope();
        $key = 'branch:'.$store->id.':'.$branchId;
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = Branch::where('id', $branchId)
                ->where('store_id', $store->id)->first();
        }

        return self::$memo[$key];
    }
}
