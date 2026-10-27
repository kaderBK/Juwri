<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\Admin;

use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\RwgFeedDrop;
use Juwri\Modules\Reservations\Services\Rwg\RwgService;

/**
 * GOOGLE-PARTNER Lane C — superadmin Reserve-with-Google oversight (admin guard).
 *
 * Read-only status of the platform integration + a per-store enabled list
 * (reservations AND waitlist columns, the latter rendered from Lane D's post_option
 * so D adds no admin files), plus a per-store kill for reservations. Support tool.
 */
class ReserveGoogleController extends Controller
{
    public function index(Request $request): View
    {
        // Every store that opted into RwG reservations OR waitlist.
        $byStore = [];
        foreach (['rwg_reservations_enabled', 'rwg_waitlist_enabled'] as $optionName) {
            foreach (StoreSettings::valuesFor($optionName) as $storeId => $value) {
                if ($value === '1') {
                    $byStore[$storeId][$optionName] = true;
                }
            }
        }

        $rows = [];
        foreach (array_keys($byStore) as $storeId) {
            $store = Store::find($storeId);
            if (! $store) {
                continue;
            }
            $rows[] = [
                'id' => $storeId,
                'title' => $store->title,
                'reservations' => ! empty($byStore[$storeId]['rwg_reservations_enabled']),
                'waitlist' => ! empty($byStore[$storeId]['rwg_waitlist_enabled']),
                'covers' => (int) store_options($storeId, 'reservation_covers_per_slot', 0),
                'interval' => (int) store_options($storeId, 'reservation_slot_interval', 30),
            ];
        }

        // Feed artifact freshness (platform-wide).
        $feeds = [];
        $dir = RwgFeedBuilder::feedDir();
        $names = array_map('basename', glob($dir.'/*.json') ?: []);
        sort($names);
        foreach ($names !== [] ? $names : ['merchant_feed.json', 'service_feed.json', 'availability_feed.json'] as $name) {
            $path = $dir.'/'.$name;
            $feeds[$name] = is_file($path)
                ? ['at' => Carbon::createFromTimestamp(filemtime($path)), 'bytes' => filesize($path)]
                : null;
        }

        return view('admin.reserve-google.index', [
            'platform' => [
                'enabled' => RwgService::enabled(),
                'driver' => RwgService::driver(),
                'creds' => RwgService::credentialsConfigured(),
                'sftp' => RwgService::sftpConfigured(),
                'rtu' => RwgService::rtuEndpoint() !== '' && RwgService::serviceAccountJson() !== '',
            ],
            'rows' => $rows,
            'feeds' => $feeds,
            'lastDrop' => RwgFeedDrop::last(),
        ]);
    }

    /** Per-store kill: turn reservations off for one store. */
    public function kill(Request $request, int $id): RedirectResponse
    {
        if (! Store::where('id', $id)->exists()) {
            abort(404);
        }
        StoreOption::updateStoreOption($id, 'rwg_reservations_enabled', '0');

        return back()->with('success', ___('Reserve with Google reservations disabled for this store.'));
    }
}
