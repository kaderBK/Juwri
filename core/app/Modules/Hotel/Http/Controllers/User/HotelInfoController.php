<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Http\Controllers\User;

use App\Models\KitchenStation;
use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\ServiceRequest;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Hotel\Services\Hotel\HotelRoomToken;
use Juwri\Modules\Hotel\Services\Hotel\HotelService;

/**
 * FEATURES-RUN-4 T1/H9 — Guest info hub owner editor + hotel settings.
 *
 * Owner edits the guest-facing info (Wi-Fi, house rules, breakfast hours,
 * reception contact) and the department-routing map. Everything is stored in
 * the `hotel_settings` JSON post-option and read back via
 * {@see HotelService::settings()}. The public guest view lives on
 * {@see \Juwri\Modules\Hotel\Http\Controllers\HotelGuestController::info()}.
 */
class HotelInfoController extends Controller
{
    private function resolveStore(Request $request): Store
    {
        $ids = dashboard_store_ids();
        $requested = $request->integer('store_id') ?: null;

        if ($requested && ! in_array($requested, $ids, true)) {
            abort(403);
        }

        $id = $requested ?: (dashboard_current_store()->id ?? ($ids[0] ?? null));
        if (! $id) {
            abort(404);
        }

        $store = Store::findOrFail($id);
        // Settings/info hub is the master-toggle entry point, so it gates on plan
        // AVAILABILITY (not the on/off toggle) — an owner whose plan includes hotel
        // must be able to reach it to switch the module on (H11).
        abort_unless(HotelService::available($store->id), 404);

        return $store;
    }

    public function edit(Request $request)
    {
        $store = $this->resolveStore($request);
        $hotelSettings = HotelService::settings($store->id);

        // HP-B5: include active stations PLUS any inactive station still referenced by
        // the routing map, so the editor surfaces a stale mapping ("… (inactive)")
        // instead of silently showing "Unassigned" while requests keep routing to it.
        $map = $hotelSettings['departments'] ?? [];
        $mappedIds = array_values(array_filter(array_map('intval', is_array($map) ? $map : [])));

        $departments = KitchenStation::where('store_id', $store->id)
            ->where(function ($q) use ($mappedIds) {
                $q->where('active', true);
                if ($mappedIds) {
                    $q->orWhereIn('id', $mappedIds);
                }
            })
            ->orderBy('position')
            ->get();

        $types = ServiceRequest::TYPES;
        $hotelOn = HotelService::enabled($store->id);

        // HP-B6 — tray charge, token-binding flag, PMS credential + mode-off warning.
        $trayCharge = trim((string) store_options($store->id, 'hotel_tray_charge'));
        $tokenRequired = HotelRoomToken::enabled((int) $store->id); // J57: on unless the owner turned it off

        $pmsCred = Credential::where('store_id', $store->id)->where('feature', 'pms')->first();
        $pmsSecrets = $pmsCred ? ($pmsCred->secrets ?? []) : [];
        $pms = [
            'provider' => $pmsCred?->provider ?: 'null',
            'base_url' => $pmsSecrets['base_url'] ?? '',
            'service_id' => $pmsSecrets['service_id'] ?? '',
            // Secrets are never re-echoed in clear — only whether they are set.
            'has_client_token' => ! empty($pmsSecrets['client_token'] ?? null),
            'has_access_token' => ! empty($pmsSecrets['access_token'] ?? null),
        ];
        $pmsProviders = ['null' => ___('None (local folio only)'), 'manual' => ___('Manual (staff enter guests)'), 'mews' => ___('Mews')];

        // Mode-off warning payload (reused from the toggle guard).
        $openFolios = self::openFolioStays($store->id);
        $statementCurrency = HotelFolioService::currencyFor($store->id).' ';

        // HP-B7 — alert-channel toggles (contract keys read by lane C's dispatch).
        // Defaults: notif ON, whatsapp/push OFF. store_options() returns false for an
        // unset key, so notif passes an explicit '1' default; whatsapp/push default off.
        $alerts = [
            'notif' => filter_var(store_options($store->id, 'hotel_alerts_notif', '1'), FILTER_VALIDATE_BOOLEAN),
            'whatsapp' => filter_var(store_options($store->id, 'hotel_alerts_whatsapp'), FILTER_VALIDATE_BOOLEAN),
            'push' => filter_var(store_options($store->id, 'hotel_alerts_push'), FILTER_VALIDATE_BOOLEAN),
        ];

        // HPMS-B8 — booking-engine settings (contract keys read by lanes A/C at
        // their defaults). store_options() returns false for an unset key, so each
        // read passes the SPEC §5 default explicitly.
        $booking = [
            'online' => (string) (store_options($store->id, 'hotel_online_booking', 'off') ?: 'off'),
            'noshow_grace' => (string) store_options($store->id, 'hotel_noshow_grace_hours', '18'),
            'noshow_fee' => (string) store_options($store->id, 'hotel_noshow_fee', '0'),
            // The live, owner-wired option key is `waitlist_avg_turn` (shared with the
            // general settings form + WaitlistController::estimatedWait); do NOT split
            // it into a second `_minutes` key or the two forms fight over the value.
            'waitlist_turn' => (string) store_options($store->id, 'waitlist_avg_turn', '20'),
        ];

        return view('owner.hotel.info', compact(
            'store', 'hotelSettings', 'departments', 'types', 'hotelOn',
            'trayCharge', 'tokenRequired', 'pms', 'pmsProviders', 'openFolios', 'statementCurrency', 'alerts', 'booking'
        ));
    }

    public function save(Request $request)
    {
        $store = $this->resolveStore($request);

        $data = $request->validate([
            'wifi_name' => ['nullable', 'string', 'max:120'],
            'wifi_password' => ['nullable', 'string', 'max:120'],
            'house_rules' => ['nullable', 'string', 'max:5000'],
            'breakfast_hours' => ['nullable', 'string', 'max:120'],
            'breakfast_cutoff' => ['nullable', 'string', 'max:20'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_whatsapp' => ['nullable', 'string', 'max:40'],
            'contact_note' => ['nullable', 'string', 'max:255'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['nullable', 'integer'],
            // HP-B6 — tray charge: a flat amount ("5") or a percent ("10%" / "12.5 %").
            'hotel_tray_charge' => ['nullable', 'string', 'max:20', 'regex:/^\s*\d+(\.\d+)?\s*%?\s*$/'],
            'token_required' => ['nullable', 'boolean'],
            'pms_provider' => ['nullable', 'in:null,manual,mews'],
            // P6-68: guest data + PMS tokens are POSTed here — https, public host only.
            'pms_base_url' => ['nullable', 'string', 'max:255', 'url:https', function (string $attr, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && ! SsrfGuard::isSafePublicUrl($value)) {
                    $fail(___('The PMS URL must be a public https address.'));
                }
            }],
            'pms_service_id' => ['nullable', 'string', 'max:120'],
            'pms_client_token' => ['nullable', 'string', 'max:255'],
            'pms_access_token' => ['nullable', 'string', 'max:255'],
            'alerts_notif' => ['nullable', 'boolean'],
            'alerts_whatsapp' => ['nullable', 'boolean'],
            'alerts_push' => ['nullable', 'boolean'],
            // HPMS-B8 — booking-engine settings (SPEC §5 keys/defaults).
            'hotel_online_booking' => ['nullable', 'in:off,request,instant'],
            'hotel_noshow_grace_hours' => ['nullable', 'integer', 'min:0', 'max:240'],
            'hotel_noshow_fee' => ['nullable', 'numeric', 'min:0'],
            'waitlist_avg_turn_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        // Preserve any keys we don't manage here (e.g. future breakfast slots).
        $settings = HotelService::settings($store->id);

        $settings['wifi'] = [
            'name' => $data['wifi_name'] ?? '',
            'password' => $data['wifi_password'] ?? '',
        ];
        $settings['house_rules'] = $data['house_rules'] ?? '';
        $settings['breakfast'] = array_merge($settings['breakfast'] ?? [], [
            'hours' => $data['breakfast_hours'] ?? '',
            'cutoff' => $data['breakfast_cutoff'] ?? '',
        ]);
        $settings['contact'] = [
            'phone' => $data['contact_phone'] ?? '',
            'whatsapp' => $data['contact_whatsapp'] ?? '',
            'note' => $data['contact_note'] ?? '',
        ];

        // Department routing map: type => kitchen_station id (validated for ownership).
        $map = [];
        foreach (($data['departments'] ?? []) as $type => $stationId) {
            if (! $stationId) {
                continue;
            }
            $ok = KitchenStation::where('id', $stationId)
                ->where('store_id', $store->id)
                ->value('id');
            if ($ok) {
                $map[$type] = (int) $ok;
            }
        }
        $settings['departments'] = $map;

        StoreOption::updateStoreOption($store->id, 'hotel_settings', json_encode($settings));

        // HP-B6 — tray charge (normalise: strip inner spaces) + token-binding flag.
        StoreOption::updateStoreOption($store->id, 'hotel_tray_charge', str_replace(' ', '', trim((string) ($data['hotel_tray_charge'] ?? ''))));
        StoreOption::updateStoreOption($store->id, 'hotel_charge_room_token_required', $request->boolean('token_required') ? '1' : '0');

        // HP-B7 — alert channels (lane C reads these keys to dispatch requests).
        StoreOption::updateStoreOption($store->id, 'hotel_alerts_notif', $request->boolean('alerts_notif') ? '1' : '0');
        StoreOption::updateStoreOption($store->id, 'hotel_alerts_whatsapp', $request->boolean('alerts_whatsapp') ? '1' : '0');
        StoreOption::updateStoreOption($store->id, 'hotel_alerts_push', $request->boolean('alerts_push') ? '1' : '0');

        // HPMS-B8 — booking-engine settings (lanes A/C read these keys at their §5
        // defaults). Stored as strings; absent submits fall back to the default.
        StoreOption::updateStoreOption($store->id, 'hotel_online_booking', $data['hotel_online_booking'] ?? 'off');
        StoreOption::updateStoreOption($store->id, 'hotel_noshow_grace_hours', (string) ($data['hotel_noshow_grace_hours'] ?? '18'));
        StoreOption::updateStoreOption($store->id, 'hotel_noshow_fee', (string) ($data['hotel_noshow_fee'] ?? '0'));
        StoreOption::updateStoreOption($store->id, 'waitlist_avg_turn', (string) ($data['waitlist_avg_turn_minutes'] ?? '20'));

        $this->savePmsCredential($store->id, $data);

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    /**
     * HP-B6 — persist the PMS picker into the feature='pms' Credential row that
     * {@see \Juwri\Modules\Hotel\Services\Hotel\Pms\PmsManager} reads. Secret token fields left
     * blank on submit keep their stored value (the form only shows "set"/"not set",
     * never the secret), so re-saving other fields never wipes the keys.
     *
     * @param  array<string, mixed>  $data
     */
    private function savePmsCredential(int $storeId, array $data): void
    {
        $provider = $data['pms_provider'] ?? 'null';

        $cred = Credential::where('store_id', $storeId)->where('feature', 'pms')->first();
        $secrets = $cred ? ($cred->secrets ?? []) : [];

        $secrets['base_url'] = trim((string) ($data['pms_base_url'] ?? ''));
        $secrets['service_id'] = trim((string) ($data['pms_service_id'] ?? ''));

        // Only overwrite a secret when a new non-empty value is submitted.
        if (! empty(trim((string) ($data['pms_client_token'] ?? '')))) {
            $secrets['client_token'] = trim((string) $data['pms_client_token']);
        }
        if (! empty(trim((string) ($data['pms_access_token'] ?? '')))) {
            $secrets['access_token'] = trim((string) $data['pms_access_token']);
        }

        Credential::updateOrCreate(
            ['store_id' => $storeId, 'feature' => 'pms'],
            ['provider' => $provider, 'secrets' => $secrets, 'active' => true]
        );
    }

    /** Toggle the master hotel_mode flag (H11 gating entry point). */
    public function toggleMode(Request $request)
    {
        $ids = dashboard_store_ids();
        $id = $request->integer('store_id') ?: (dashboard_current_store()->id ?? ($ids[0] ?? null));
        if (! $id || ! in_array((int) $id, $ids, true)) {
            abort(403);
        }

        $turningOn = $request->boolean('hotel_mode');

        // HP-B1/B6 wind-down guard: turning the module OFF makes every folio route
        // 403 (HotelFolioController::hotelRestaurant re-checks HotelService::enabled),
        // so any guest still checked in with an outstanding balance would be stranded —
        // the room can't be settled or checked out until the module is re-enabled.
        // Refuse unless the owner explicitly confirms (the settings form ships the
        // active-stay count + a `confirm` flag; see info.blade).
        if (! $turningOn && ! $request->boolean('confirm')) {
            $openStays = $this->openFolioStays((int) $id);
            if ($openStays['count'] > 0) {
                app_alert_error(___('There are guests checked in with an open folio balance. Settle or check them out first, or confirm to turn the hotel module off anyway.'));

                return back();
            }
        }

        StoreOption::updateStoreOption($id, 'hotel_mode', $turningOn ? '1' : '0');

        app_alert_success(___('Saved Successfully'));

        return back();
    }

    /**
     * HP-B1/B6 — checked-in stays that still carry a non-zero folio balance for
     * this restaurant, plus the outstanding total. Surfaced on the settings page
     * (mode-off warning) and used by the toggle guard.
     *
     * @return array{count:int,total:float}
     */
    public static function openFolioStays(int $storeId): array
    {
        $count = 0;
        $total = 0.0;

        $stays = HotelStay::where('store_id', $storeId)
            ->where('status', 'checked_in')
            ->get();

        foreach ($stays as $stay) {
            $balance = HotelFolioService::balance($stay);
            if ($balance > 0.001) {
                $count++;
                $total += $balance;
            }
        }

        return ['count' => $count, 'total' => $total];
    }
}
