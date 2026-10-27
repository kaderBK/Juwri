<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Shared base for the platform-e2e owner-dashboard tests revived by PR #34:
 * PlatformE2eB10BioBuilderTest / PlatformE2eB11HospitalityTest /
 * PlatformE2eB12StaffPermissionsTest (this namespace) and
 * Platform\B5OrdersHubTest / Platform\B6PosKdsStaffPwaTest
 * (tests/Feature/Platform).
 *
 * A recon audit found two bits hand-repeated, byte-for-byte or near enough,
 * across all six classes — each a re-rot risk: fix the drift once and the next
 * revived test would still carry the stale copy.
 *
 *  1. `withoutMiddleware([Installed::class, PreventRequestForgery::class])`.
 *     phpunit.xml pins APP_INSTALLED=false so ViewServiceProvider never boots
 *     its composers and — more importantly here — the `installed` middleware
 *     guarding the whole owner-dashboard route group 302-redirects every
 *     request to /install before any controller runs; PreventRequestForgery then
 *     419s any state-changing POST a synthetic test request never carries a
 *     token for. Every revived test needs both bypassed. A class that needs
 *     MORE middleware disabled (B5 also strips EnsureStaffDashboardPermission
 *     + SyncDashboardStore; B12 deliberately does NOT strip
 *     EnsureStaffDashboardPermission — it is the gate under test) adds those
 *     itself, on top of this base, from its own setUp().
 *
 *  2. `ownerWithStore()` — a fresh owner + one 'restaurant' store, stamped
 *     with the `store_template` store_options row (never a `posts`
 *     column) so any owner-dashboard view keyed off active_theme()/the
 *     store's template resolves a real theme. B10 and B11 carried
 *     byte-identical copies of this; B10 additionally grants a `biolink`
 *     entitlement on top (see its own override, which calls
 *     parent::ownerWithStore() first).
 *
 *     B12's staff-permissions fixture is NOT a copy of this one and is
 *     deliberately NOT routed through it: it needs a real Plan (for
 *     staff_limit/group_id) and a bare Post::create() with no
 *     store_type/template at all, so it keeps its own distinctly-named
 *     `ownerWithPlanStore()` helper.
 */
abstract class PlatformE2eTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * A fresh owner + one store. Defaults reproduce the original B10/B11
     * fixture exactly: a 'restaurant' store templated 'crave' via the
     * `store_template` store_options row.
     *
     * Pass a non-restaurant $storeType (e.g. 'retail', 'laundry') to build a
     * multi-vertical fixture — `store_template` only matters to the
     * restaurant theme stack, so pass $template = null alongside it to skip
     * stamping a meaningless option on a non-restaurant store.
     *
     * @return array{0: User, 1: Store}
     */
    protected function ownerWithStore(string $storeType = 'restaurant', ?string $template = 'crave'): array
    {
        $owner = User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        // Template is a store_options row, not a posts column; there is no
        // posts.status/type any more (store_type replaced type).
        $store = Store::factory()->create([
            'user_id' => $owner->id,
            'store_type' => $storeType,
        ]);

        if ($template !== null) {
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
        }

        return [$owner, $store];
    }
}
