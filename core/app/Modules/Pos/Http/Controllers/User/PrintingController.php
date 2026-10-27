<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\User;

use App\Models\KitchenStation;
use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\BridgeUrl;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Juwri\Modules\Pos\Services\Printing\PrintSettings;
use Juwri\Modules\Pos\Services\Printing\RawBtLink;

/**
 * PARITY-RUN T1-1: owner-facing Settings → Printing.
 *
 * CRUD over PrinterProfile plus the store-level print options and the desktop
 * bridge pairing token. Tenant scoping is the shared ResolvesCurrentStore
 * guard; the superadmin kill-switch is the `feature:print_routing` middleware on
 * the route group (legal here — this is a dashboard-only route no anonymous
 * customer can reach).
 */
class PrintingController extends Controller
{
    use ResolvesCurrentStore;

    /** Store-level toggles that are not per-printer. */
    private const FLAGS = ['print_rtl', 'print_logo', 'print_open_drawer'];

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);

        $profiles = PrinterProfile::where('store_id', $store->id)
            ->orderBy('position')->orderBy('id')->get();

        // D3: per-profile last-ack — the most recent job's status/time keyed by
        // profile, so the fleet screen shows each printer's live health at a glance.
        $lastByProfile = [];
        foreach ($profiles as $prof) {
            $lastByProfile[$prof->id] = PrintJob::where('profile_id', $prof->id)
                ->orderByDesc('id')->first();
        }

        // D3: filterable + paginated print-job history.
        $jobStatus = (string) $request->query('job_status', '');
        $jobProfile = (int) $request->query('job_profile', 0);
        $jobs = PrintJob::where('store_id', $store->id)
            ->when(in_array($jobStatus, PrintJob::STATUSES, true), fn ($q) => $q->where('status', $jobStatus))
            ->when($jobProfile > 0, fn ($q) => $q->where('profile_id', $jobProfile))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('owner.kitchen.printing', [
            'rid' => $store->id,
            'store' => $store,
            'opts' => store_options($store->id),
            'profiles' => $profiles,
            'lastByProfile' => $lastByProfile,
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->get(['id', 'name']),
            'stations' => KitchenStation::where('store_id', $store->id)
                ->orderBy('position')->get(['id', 'name']),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            'pairingToken' => PrintSettings::pairingToken($store->id),
            'rawbtUrl' => RawBtLink::installUrl(),
            'transports' => PrinterProfile::TRANSPORTS,
            'events' => PrinterProfile::EVENTS,
            'recentJobs' => $jobs,
            'jobStatus' => $jobStatus,
            'jobProfile' => $jobProfile,
        ]);
    }

    public function store(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $data = $this->validated($request, $store);

        $data['store_id'] = $store->id;
        $data['position'] = (int) PrinterProfile::where('store_id', $store->id)->max('position') + 1;
        PrinterProfile::create($data);

        return back()->with('success', ___('Printer saved.'));
    }

    public function update(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $profile = $this->ownProfile($id, (int) $store->id);
        $data = $this->validated($request, $store);
        // D3: a printer may never fall back to itself.
        if ((int) ($data['fallback_profile_id'] ?? 0) === (int) $profile->id) {
            $data['fallback_profile_id'] = null;
        }
        $profile->update($data);

        return back()->with('success', ___('Printer saved.'));
    }

    public function destroy(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $this->ownProfile($id, (int) $store->id)->delete();

        return back()->with('success', ___('Printer removed.'));
    }

    /** Queue a test page for one profile so the owner can prove the printer works. */
    public function test(Request $request, int $id)
    {
        $store = $this->currentRestaurant($request);
        $profile = $this->ownProfile($id, (int) $store->id);
        $job = PrintDispatcher::testPrint($profile);

        if ($request->expectsJson()) {
            return response()->json(['ok' => (bool) $job, 'job_id' => $job?->id]);
        }

        return back()->with('success', $job
            ? ___('Test print queued — open the register to send it to the printer.')
            : ___('Could not queue the test print.'));
    }

    /** Store-level print options (RTL, logo, drawer kick, RTL font path). */
    public function options(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $request->validate(['print_rtl_font' => ['nullable', 'string', 'max:190']]);

        foreach (self::FLAGS as $flag) {
            StoreOption::updateStoreOption($store->id, $flag, $request->boolean($flag) ? '1' : '0');
        }
        StoreOption::updateStoreOption($store->id, 'print_rtl_font', (string) $request->input('print_rtl_font', ''));

        return back()->with('success', ___('Printing settings saved.'));
    }

    /**
     * Generate/rotate the desktop bridge pairing token. Rotating instantly
     * unpairs every already-paired shell — the merchant's only revoke.
     */
    public function rotateToken(Request $request)
    {
        $store = $this->currentRestaurant($request);
        PrintSettings::rotatePairingToken((int) $store->id);

        return back()->with('success', ___('Pairing token generated. Paste it into the desktop app.'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ownProfile(int $id, int $storeId): PrinterProfile
    {
        return PrinterProfile::where('id', $id)->where('store_id', $storeId)->firstOrFail();
    }

    /** Validate a profile payload; branch + stations are re-checked against the store. */
    private function validated(Request $request, Store $store): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            // `sunmi` is the pre-existing kiosk lane and is configured on the
            // Kiosk page — it is never creatable from here.
            'transport' => ['required', 'in:browser,webserial,webusb,rawbt,bridge'],
            'target' => ['nullable', 'string', 'max:190'],
            // P6-298: http(s) origin only (no userinfo/path/CSP tokens); loopback/LAN are fine for
            // the browser transport, link-local/metadata never.
            'bridge_url' => ['nullable', 'string', 'max:190', function (string $attr, mixed $value, \Closure $fail): void {
                if (BridgeUrl::normalize((string) $value) === null) {
                    $fail(___('Enter the bridge address as http(s)://host:port, for example http://127.0.0.1:8543.'));
                }
            }],
            'width_mm' => ['required', 'integer', 'in:80,57'],
            'branch_id' => ['nullable', 'integer'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', PrinterProfile::EVENTS)],
            'station_ids' => ['nullable', 'array'],
            'station_ids.*' => ['integer'],
            'copies' => ['required', 'integer', 'min:1', 'max:5'],
            'auto_print' => ['nullable'],
            'active' => ['nullable'],
            'fallback_profile_id' => ['nullable', 'integer'], // D3
        ]);

        $branchId = (int) ($data['branch_id'] ?? 0);
        if ($branchId && ! Branch::where('store_id', $store->id)->where('id', $branchId)->exists()) {
            $branchId = 0; // never let a client bind a profile to someone else's branch
        }

        // D3: a fallback printer must be another OWN profile (self-reference is
        // caught at update() where the row id is known; a bad id is dropped here).
        $fallbackId = (int) ($data['fallback_profile_id'] ?? 0);
        if ($fallbackId && ! PrinterProfile::where('store_id', $store->id)->where('id', $fallbackId)->exists()) {
            $fallbackId = 0;
        }

        $stationIds = array_values(array_unique(array_map('intval', (array) ($data['station_ids'] ?? []))));
        if ($stationIds) {
            $stationIds = KitchenStation::where('store_id', $store->id)
                ->whereIn('id', $stationIds)->pluck('id')->map('intval')->all();
        }

        return [
            'name' => $data['name'],
            'transport' => $data['transport'],
            'target' => $data['target'] ?? null,
            'bridge_url' => $data['transport'] === 'bridge'
                                ? (BridgeUrl::normalize($data['bridge_url'] ?? null) ?? PrinterProfile::DEFAULT_BRIDGE_URL)
                                : null,
            'width_mm' => (int) $data['width_mm'],
            'branch_id' => $branchId ?: null,
            'events' => array_values(array_unique((array) ($data['events'] ?? []))),
            'station_ids' => $stationIds,
            'copies' => (int) $data['copies'],
            'auto_print' => $request->boolean('auto_print'),
            'active' => $request->boolean('active'),
            'fallback_profile_id' => $fallbackId ?: null,
        ];
    }
}
