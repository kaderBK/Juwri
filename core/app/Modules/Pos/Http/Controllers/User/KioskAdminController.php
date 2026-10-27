<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Support\KioskPin;

/**
 * GAPS-RUN-2 B9-P3: owner kiosk config — enable, idle seconds, exit/setup PINs,
 * per-branch kiosk URL + setup QR. Tenant scoped; behind the kiosk kill-switch.
 */
class KioskAdminController extends Controller
{
    use ResolvesCurrentStore;

    // N8: kiosk_auto_print + kiosk_open_drawer gate Sunmi receipt printing.
    private const FLAGS = ['kiosk_enabled', 'kiosk_auto_print', 'kiosk_open_drawer', 'kiosk_loyalty']; // item 26: loyalty at the kiosk (off by default)

    private const VALUES = ['kiosk_idle_seconds', 'kiosk_printer_width']; // the two PINs: KioskPin (hash-only, P6-88)

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);

        return view('owner.kiosk.index', [
            'rid' => $store->id,
            // P6-88: the PINs are hashes now — the form shows only whether one is set, never the value.
            'opts' => array_diff_key((array) store_options($store->id), array_flip([...KioskPin::KEYS, ...KioskPin::RULE_KEYS])),
            'pinSet' => ['kiosk_exit_pin' => KioskPin::isSet((int) $store->id, 'kiosk_exit_pin'), 'kiosk_setup_pin' => KioskPin::isSet((int) $store->id, 'kiosk_setup_pin')],
            // P6-242: PINs set before the strength rules are flagged so the owner re-sets them.
            'pinWeak' => ['kiosk_exit_pin' => KioskPin::isWeak((int) $store->id, 'kiosk_exit_pin'), 'kiosk_setup_pin' => KioskPin::isWeak((int) $store->id, 'kiosk_setup_pin')],
            'kioskUrl' => route('publicView.kiosk', $store->slug),
            // PARITY-RUN T10: through the render service, wearing the store's design
            // (falls back to the plain code when the suite is off/unentitled).
            'kioskQr' => branded_qr_svg($store->id, route('publicView.kiosk', $store->slug)),
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->get(['id', 'name']),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function update(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $request->validate([
            'kiosk_idle_seconds' => ['nullable', 'integer', 'min:15', 'max:3600'],
            'kiosk_exit_pin' => ['nullable', 'string', 'max:12', function (string $attr, mixed $value, \Closure $fail): void {
                // P6-242: a short or guessable PIN opens the kiosk to the dashboard.
                $problem = KioskPin::problem((string) $value);
                if ($problem === 'format') {
                    $fail(___('The PIN must be :min to 12 digits.', ['min' => KioskPin::MIN_DIGITS]));
                } elseif ($problem === 'trivial') {
                    $fail(___('Choose a PIN that is harder to guess (no repeated or sequential digits).'));
                }
            }],
            'kiosk_setup_pin' => ['nullable', 'string', 'max:12', function (string $attr, mixed $value, \Closure $fail): void {
                // P6-242: a short or guessable PIN opens the kiosk to the dashboard.
                $problem = KioskPin::problem((string) $value);
                if ($problem === 'format') {
                    $fail(___('The PIN must be :min to 12 digits.', ['min' => KioskPin::MIN_DIGITS]));
                } elseif ($problem === 'trivial') {
                    $fail(___('Choose a PIN that is harder to guess (no repeated or sequential digits).'));
                }
            }],
            'kiosk_printer_width' => ['nullable', 'integer', 'in:32,48'],
        ]);

        foreach (self::FLAGS as $flag) {
            StoreOption::updateStoreOption($store->id, $flag, $request->boolean($flag) ? '1' : '0');
        }
        foreach (self::VALUES as $key) {
            StoreOption::updateStoreOption($store->id, $key, (string) $request->input($key, ''));
        }
        // A blank PIN field keeps the stored PIN (the form cannot echo a hash); the clear box removes it.
        foreach (KioskPin::KEYS as $key) {
            $pin = (string) $request->input($key, '');
            if ($request->boolean($key.'_clear')) {
                KioskPin::set((int) $store->id, $key, '');
            } elseif ($pin !== '') {
                KioskPin::set((int) $store->id, $key, $pin);
            }
        }

        return back()->with('success', ___('Kiosk settings saved.'));
    }
}
