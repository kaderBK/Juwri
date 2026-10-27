<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;

/**
 * Owner-facing BYOK credentials for 3rd-party courier dispatch (Uber Direct,
 * DoorDash Drive). Mirrors the Marn POS BYOK form: secrets live encrypted in the
 * Credential vault (feature='uber_direct'|'doordash', restaurant-scoped), secret
 * fields are never echoed back — a blank submit keeps the stored value.
 * Behind the same courier_dispatch gate as the dispatch screen.
 */
class CourierCredentialsController extends Controller
{
    use ResolvesCurrentStore;

    /**
     * Field map per provider: 'id' fields are plain identifiers (safe to redisplay),
     * 'secret' fields are write-only and masked on redisplay.
     */
    public const PROVIDERS = [
        'uber_direct' => [
            'label' => 'Uber Direct',
            'ids' => ['customer_id', 'client_id'],
            'secrets' => ['client_secret'],
        ],
        'doordash' => [
            'label' => 'DoorDash Drive',
            'ids' => ['developer_id', 'key_id'],
            'secrets' => ['signing_secret'],
        ],
    ];

    /** Static field labels — no dynamic ___() keys (they auto-register into every locale). */
    private const LABELS = [
        'customer_id' => 'Customer ID',
        'client_id' => 'Client ID',
        'client_secret' => 'Client Secret',
        'developer_id' => 'Developer ID',
        'key_id' => 'Key ID',
        'signing_secret' => 'Signing Secret',
    ];

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $providers = [];
        foreach (self::PROVIDERS as $key => $spec) {
            $row = $this->row($rid, $key);
            $secrets = $row->secrets ?? [];
            $providers[$key] = [
                'label' => $spec['label'],
                'ids' => collect($spec['ids'])->mapWithKeys(fn ($f) => [$f => ['label' => self::LABELS[$f], 'value' => (string) ($secrets[$f] ?? '')]])->all(),
                // Booleans only — the secret VALUES never reach the view.
                'has' => collect($spec['secrets'])->mapWithKeys(fn ($f) => [$f => ['label' => self::LABELS[$f], 'stored' => ! empty($secrets[$f])]])->all(),
                'connected' => (bool) ($row?->active && ! empty($secrets)),
            ];
        }

        return view('owner.dispatch.couriers', [
            'rid' => $rid,
            'providers' => $providers,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function save(Request $request, string $provider)
    {
        $rid = $this->currentRestaurantId($request);
        $spec = self::PROVIDERS[$provider] ?? abort(404);

        $rules = [];
        foreach ($spec['ids'] as $f) {
            $rules[$f] = ['required', 'string', 'max:191'];
        }
        foreach ($spec['secrets'] as $f) {
            $rules[$f] = ['nullable', 'string', 'max:191'];
        }
        $data = $request->validate($rules);

        $existing = $this->row($rid, $provider)->secrets ?? [];

        // A secret may only be left blank to keep one that is already stored; on
        // first connect every secret is required, or the vault row saves empty.
        foreach ($spec['secrets'] as $f) {
            if (($data[$f] ?? '') === '' && ($existing[$f] ?? '') === '') {
                return back()->withErrors([$f => ___('This field is required.')])->withInput();
            }
        }

        $secrets = [];
        foreach ($spec['ids'] as $f) {
            $secrets[$f] = $data[$f];
        }
        foreach ($spec['secrets'] as $f) {
            // Blank submit keeps the stored secret (it was never rendered to the browser).
            $secrets[$f] = ($data[$f] ?? '') !== '' ? $data[$f] : (string) ($existing[$f] ?? '');
        }

        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => $provider, 'provider' => $provider],
            ['secrets' => $secrets, 'active' => true]
        );

        app_alert_success($spec['label'].' '.___('credentials saved.'));

        return back();
    }

    public function disconnect(Request $request, string $provider)
    {
        $rid = $this->currentRestaurantId($request);
        $spec = self::PROVIDERS[$provider] ?? abort(404);

        Credential::where('store_id', $rid)->where('feature', $provider)->delete();

        app_alert_success($spec['label'].' '.___('disconnected.'));

        return back();
    }

    /** Restaurant-scoped credential row (never the platform-default fallback). */
    private function row(int $rid, string $provider): ?Credential
    {
        return Credential::where('store_id', $rid)->where('feature', $provider)->first();
    }
}
