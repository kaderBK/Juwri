<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\User;

use App\Models\Driver;
use App\Models\StoreOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Security\OneTimeSecrets;

/**
 * Dashboard management for delivery drivers. Owners create drivers here and hand
 * each the raw app token (shown once) to sign into the driver app. Mirrors the
 * API-token page's create/reveal/rotate/revoke flow.
 */
class DriverController extends Controller
{
    public function index(Request $request, OneTimeSecrets $secrets)
    {
        // P4: a just-made driver token is shown once from the server-side store (never a session flash).
        $newDriverToken = $secrets->pull($this->secretKind(), (int) auth()->id(), $request->query('new_driver_token'));
        $drivers = $this->visibleDrivers()->orderByDesc('created_at')->get();
        $stores = Store::whereIn('id', dashboard_store_ids())->orderBy('title')->get(['id', 'title']);
        $selfClaim = $stores->mapWithKeys(fn ($r) => [$r->id => (bool) store_options($r->id, 'driver_self_claim', 0)]);

        return view('owner.dispatch.drivers', compact('drivers', 'stores', 'selfClaim', 'newDriverToken'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:160',
            'store_id' => 'nullable|integer',
        ]);

        // M1: a staff session may only add drivers of its own store (never an owner-wide one).
        if (is_staff_dashboard() && empty($data['store_id'])) {
            $data['store_id'] = dashboard_store_ids()[0] ?? abort(403);
        }
        $this->assertRestaurantOwned($data['store_id'] ?? null);

        $raw = Str::random(48);
        $driver = Driver::create(array_merge($data, [
            'owner_id' => auth()->id(),
            'token' => hash('sha256', $raw),
            'active' => true,
        ]));

        app(OneTimeSecrets::class)->put($this->secretKind(), (int) auth()->id(), (int) $driver->id, $raw);

        return redirect()->route('drivers.index', ['new_driver_token' => $driver->id])
            ->with('success', ___('Driver added. Copy the app token now — it will not be shown again.'));
    }

    /**
     * Per-store switch: may this store's drivers claim an unassigned delivery order from the driver app's Available tab?
     * Off unless the owner turns it on; the claim endpoint itself only ever offers the driver's own store.
     */
    public function selfClaim(Request $request): RedirectResponse
    {
        $data = $request->validate(['store_id' => 'required|integer']);
        $store = Store::where('user_id', auth()->id())->whereIn('id', dashboard_store_ids())->findOrFail($data['store_id']);
        StoreOption::updateStoreOption($store->id, 'driver_self_claim', $request->boolean('enabled') ? '1' : '0');

        return back()->with('success', ___('Driver self-claim saved.'));
    }

    public function rotateToken(int $id)
    {
        $driver = $this->visibleDrivers()->findOrFail($id);
        $raw = Str::random(48);
        $driver->update(['token' => hash('sha256', $raw)]);

        app(OneTimeSecrets::class)->put($this->secretKind(), (int) auth()->id(), (int) $driver->id, $raw);

        return redirect()->route('drivers.index', ['new_driver_token' => $driver->id])
            ->with('success', ___('New token generated for :name. The old token no longer works.', ['name' => $driver->name]));
    }

    public function toggle(int $id)
    {
        $driver = $this->visibleDrivers()->findOrFail($id);
        $driver->update(['active' => ! $driver->active]);

        return back()->with('success', $driver->active ? ___('Driver activated.') : ___('Driver deactivated.'));
    }

    public function destroy(int $id)
    {
        $this->visibleDrivers()->findOrFail($id)->delete();

        return back()->with('success', ___('Driver removed.'));
    }

    /**
     * AUDIT-tenancy M1: drivers the current dashboard session may see or act on. Owners see all their drivers;
     * a staff session (BL-036) sees only drivers of its own store, never owner-wide (store-less) drivers.
     *
     * @return Builder<Driver>
     */
    private function visibleDrivers(): Builder
    {
        $query = Driver::where('owner_id', auth()->id());

        if (is_staff_dashboard()) {
            $query->whereIn('store_id', dashboard_store_ids());
        }

        return $query;
    }

    /** One-time token slot: owner and staff sessions get separate slots, so staff never read a token the owner made. */
    private function secretKind(): string
    {
        return is_staff_dashboard() ? 'driver_token_staff_'.staff_session()?->id : 'driver_token';
    }

    private function assertRestaurantOwned($storeId): void
    {
        if ($storeId) {
            Store::where('user_id', auth()->id())->whereIn('id', dashboard_store_ids())->findOrFail($storeId);
        }
    }
}
