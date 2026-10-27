<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\User;

use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Models\SmsOptout;

class WaitlistController extends Controller
{
    private function ownerRestaurant(int $storeId): Store
    {
        $store = Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->whereIn('id', dashboard_store_ids()) // BL-036: a staff session is bound to its own store
            ->firstOrFail();

        return $store;
    }

    public function index(Request $request, int $storeId)
    {
        $store = $this->ownerRestaurant($storeId);
        $active = WaitlistEntry::forRestaurant($storeId)->active()->get();
        $history = WaitlistEntry::forRestaurant($storeId)
            ->whereIn('status', ['seated', 'left'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $avgTurnMinutes = (int) (store_options($storeId, 'waitlist_avg_turn', 20));

        // google-partner D6: Reserve-with-Google card. Dark — the card only shows
        // once the owner wired platform RwG creds (F0); the per-store toggle lives
        // inside it.
        $rwgConfigured = (bool) config('services.reserve_with_google.enabled');
        $rwgEnabled = (bool) store_options($storeId, 'rwg_waitlist_enabled');
        $rwgMaxParty = (int) store_options($storeId, 'waitlist_max_party', 12);

        return view('owner.tables.waitlist', compact(
            'store', 'active', 'history', 'avgTurnMinutes',
            'rwgConfigured', 'rwgEnabled', 'rwgMaxParty'
        ));
    }

    /**
     * google-partner D6: persist the per-store Reserve-with-Google waitlist
     * settings. Lane-owned save (SPEC §9 prefers this over the shared
     * StoreController option whitelist). store_options are STRING '1'/'0'.
     */
    public function saveRwgSettings(Request $request, int $storeId): RedirectResponse
    {
        $this->ownerRestaurant($storeId); // owner-scope guard (firstOrFail → 404)

        $request->validate([
            'waitlist_max_party' => 'nullable|integer|min:1|max:99',
        ]);

        StoreSettings::set((int) $storeId, 'rwg_waitlist_enabled', $request->boolean('rwg_waitlist_enabled') ? '1' : '0');

        if ($request->filled('waitlist_max_party')) {
            StoreSettings::set((int) $storeId, 'waitlist_max_party', (string) (int) $request->input('waitlist_max_party'));
        }

        log_activity('waitlist.rwg_settings', null, [], $storeId);

        return back()->with('success', ___('Reserve with Google settings saved.'));
    }

    public function add(Request $request, int $storeId)
    {
        $store = $this->ownerRestaurant($storeId);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'party_size' => 'required|integer|min:1|max:99',
        ]);

        $avgTurn = (int) (store_options($storeId, 'waitlist_avg_turn', 20));
        $position = WaitlistEntry::forRestaurant($storeId)->where('status', 'waiting')->count() + 1;
        $quoted = $position * $avgTurn;

        WaitlistEntry::create([
            'store_id' => $storeId,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'party_size' => $validated['party_size'],
            'status' => 'waiting',
            'quoted_wait' => $quoted,
        ]);

        log_activity('waitlist.add', null, ['name' => $validated['name'], 'party_size' => $validated['party_size']], $storeId);

        return back()->with('success', ___('Party added to waitlist.'));
    }

    /**
     * FP6 #32: tell a waiting party their table is ready. Reuses SmsService (the same
     * send choke-point as order/OTP SMS) so it honours the merchant's SMS credentials
     * and — because a table-ready ping is an alert the guest can decline — respects
     * opt-outs (marketing=true → an opted-out number is skipped, not sent). With no
     * live SMS credentials the send is safely simulated (logged), so this works in
     * dev/test; wiring a real provider key is the DEFERRED live step.
     */
    public function notify(Request $request, int $storeId, WaitlistEntry $entry)
    {
        $store = $this->ownerRestaurant($storeId);
        abort_if((int) $entry->store_id !== $storeId, 403);
        abort_if(! in_array($entry->status, ['waiting']), 422);

        $optedOut = ! empty($entry->phone) && SmsOptout::isOptedOut($entry->phone, $storeId);

        // A store walk-in that opted out of texts can't be reached — leave them
        // 'waiting' (so the host can retry after calling their name) instead of
        // falsely marking them notified.
        //
        // google-partner D4: a Google-sourced entry is bound by RwG's mandatory
        // SERVICE_READY contract — state is authoritative, SMS is best-effort. So
        // for source='google' we still flip the status (and the model observer
        // pushes the SERVICE_READY RTU) even when the SMS is suppressed; otherwise
        // an opted-out Google party would hang in WAITING forever.
        if ($optedOut && (string) $entry->source !== 'google') {
            return back()->with('error', ___(':name opted out of texts — please call them by name.', ['name' => $entry->name]));
        }

        $sent = false;
        if (! empty($entry->phone) && ! $optedOut) {
            $message = ___(':name, your table at :place is ready — please come to the host stand.', [
                'name' => $entry->name,
                'place' => $store->title,
            ]);
            // C8: surface the live estimated wait on the ping when there is still one
            // to quote (position × avg-turn). Computed BEFORE the status flips to
            // 'notified' so the party's own row still counts toward its position.
            $avgTurn = (int) (store_options($storeId, 'waitlist_avg_turn', 20));
            $estimate = $entry->estimatedWait($avgTurn);
            if ($estimate > 0) {
                $message .= ' '.___('Estimated wait: ~:mins min.', ['mins' => $estimate]);
            }
            $sent = app(Sms::class)->send($entry->phone, $message, $storeId, 'waitlist', true);
        }

        $entry->update(['status' => 'notified', 'notified_at' => now()]);
        log_activity('waitlist.notify', $entry, ['phone' => $entry->phone, 'sms' => $sent], $storeId);

        return back()->with('success', ___(':name has been notified.', ['name' => $entry->name]));
    }

    public function seat(Request $request, int $storeId, WaitlistEntry $entry)
    {
        $this->ownerRestaurant($storeId);
        abort_if((int) $entry->store_id !== $storeId, 403);
        // Only a party still in the queue can be seated. Without this guard a
        // direct POST re-flips a terminal entry (seated/left → seated), writing a
        // false waitlist.seat activity row (notify() already guards this way).
        abort_if(! in_array($entry->status, ['waiting', 'notified']), 422);

        $entry->update(['status' => 'seated']);
        log_activity('waitlist.seat', $entry, [], $storeId);

        return back()->with('success', ___(':name has been seated.', ['name' => $entry->name]));
    }

    public function leave(Request $request, int $storeId, WaitlistEntry $entry)
    {
        $this->ownerRestaurant($storeId);
        abort_if((int) $entry->store_id !== $storeId, 403);
        // Same guard: a seated/left party is terminal — a direct POST must not
        // flip it back to 'left'.
        abort_if(! in_array($entry->status, ['waiting', 'notified']), 422);

        $entry->update(['status' => 'left']);
        log_activity('waitlist.left', $entry, [], $storeId);

        return back()->with('success', ___(':name marked as left.', ['name' => $entry->name]));
    }

    // Public join endpoint (for aurora QR landing page)
    public function publicJoin(Request $request, string $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();

        // Only stores that opted in accept join-ins; the themes gate their waitlist sheet
        // on the same option, so a store with it off has no form pointing here.
        abort_unless(store_options($store->id, 'enable_waitlist'), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'party_size' => 'required|integer|min:1|max:99',
        ]);

        $avgTurn = (int) (store_options($store->id, 'waitlist_avg_turn', 20));
        $position = WaitlistEntry::forRestaurant($store->id)->where('status', 'waiting')->count() + 1;

        WaitlistEntry::create([
            'store_id' => $store->id,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'party_size' => $validated['party_size'],
            'status' => 'waiting',
            'quoted_wait' => $position * $avgTurn,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['position' => $position, 'wait_minutes' => $position * $avgTurn]);
        }

        return back()->with('waitlist_joined', [
            'position' => $position,
            'wait_minutes' => $position * $avgTurn,
        ]);
    }
}
