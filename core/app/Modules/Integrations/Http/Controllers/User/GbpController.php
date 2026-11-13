<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\StoreOption;
use App\Support\BioLinkOutbound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Integrations\Jobs\SyncGbpMenuJob;
use Juwri\Modules\Integrations\Services\Gbp\GbpClientFactory;
use Juwri\Modules\Integrations\Services\Gbp\GbpOAuth;
use Juwri\Modules\Integrations\Services\Gbp\GbpTokenService;
use Juwri\Modules\Integrations\Services\Gbp\PlaceActionLinkService;

/**
 * GAPS-RUN-2 B4-P1: owner "Google ordering link" card — connect a GBP location
 * (BYOK), set the preferred FOOD_ORDERING link, and read manual-fallback guidance.
 * Tenant scoped; behind the gbp_link kill-switch.
 */
class GbpController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private PlaceActionLinkService $links) {}

    public function index(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $creds = Credential::for('gbp', $store->id) ?? [];

        $oauthConnected = ! empty($creds['access_token']);
        $linked = ! empty($creds['location_id']);
        // State machine: disconnected → connected-no-location → linked.
        $state = $linked ? 'linked' : ($oauthConnected ? 'connected-no-location' : 'disconnected');

        // Location picker: only fetch when the owner is OAuth-connected but has not yet
        // linked a location. Fake driver returns seeded options; real driver makes the
        // live listAccounts/listLocations calls (guarded — failure yields an empty list).
        $pickerOptions = [];
        if ($state === 'connected-no-location') {
            $pickerOptions = $this->locationOptions($store->id);
        }

        return view('owner.gbp.index', [
            'rid' => $store->id,
            'state' => $state,
            'connected' => $linked, // back-compat: existing blade/tests read "connected" as "linked"
            'oauthConnected' => $oauthConnected,
            'locationId' => $creds['location_id'] ?? '',
            'accountId' => $creds['account_id'] ?? '',
            'pickerOptions' => $pickerOptions,
            'orderingUrl' => effective_public_url($store),
            // biolink-tpl lane-d (T2-4b): the owner may point the Google ordering
            // link at their bio page instead of the storefront. The Bio radio only
            // appears when the bio page is live; the stored choice falls back to the
            // storefront whenever the bio is dark (see orderingLinkFor()).
            'bioOrderingUrl' => BioLinkOutbound::liveUrl($store, 'social'),
            'orderingTarget' => (string) store_options($store->id, 'gbp_ordering_link_target', 'storefront'),
            'currentLink' => $this->links->currentLink($store->id),
            'menuSyncEnabled' => ! empty($creds['gbp_menu_sync_enabled']),
            'lastSyncedAt' => $creds['last_synced_at'] ?? null,
            'syncError' => $creds['sync_error'] ?? null,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    /**
     * Flatten the owner's GBP accounts→locations into picker options.
     * Each: ['account'=>'accounts/111', 'location'=>'accounts/111/locations/222', 'label'=>'Demo Location — Demo City'].
     *
     * @return array<int, array{account:string, location:string, label:string}>
     */
    private function locationOptions(int $storeId): array
    {
        try {
            $client = GbpClientFactory::make($storeId);
            $options = [];
            foreach ($client->listAccounts() as $account) {
                $accName = (string) ($account['name'] ?? '');
                if ($accName === '') {
                    continue;
                }
                foreach ($client->listLocations($accName) as $loc) {
                    $locName = (string) ($loc['name'] ?? '');
                    if ($locName === '') {
                        continue;
                    }
                    $title = (string) ($loc['title'] ?? $locName);
                    $city = (string) ($loc['storefrontAddress']['locality'] ?? '');
                    $options[] = [
                        'account' => $accName,
                        'location' => $locName,
                        'label' => $city !== '' ? "{$title} — {$city}" : $title,
                    ];
                }
            }

            return $options;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** A4: persist the owner's picked location (+account) into the gbp Credential. */
    public function selectLocation(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'account_id' => ['nullable', 'string', 'max:191'],
            'location_id' => ['required', 'string', 'max:191'],
        ]);

        $row = Credential::firstOrNew(['store_id' => $store->id, 'feature' => 'gbp']);
        $secrets = $row->secrets ?? [];
        $secrets['location_id'] = $data['location_id'];
        // Prefer an explicit account_id; else derive it from the location resource name
        // (GBP location names are 'accounts/{acc}/locations/{loc}').
        $account = $data['account_id'] ?? null;
        if (empty($account) && preg_match('#(accounts/[^/]+)/locations/#', $data['location_id'], $m)) {
            $account = $m[1];
        }
        if (! empty($account)) {
            $secrets['account_id'] = $account;
        }
        $row->provider = 'gbp';
        $row->active = true;
        $row->secrets = $secrets;
        $row->save();

        // A6 wires the auto-set of the preferred ordering link here (effective public URL).
        $this->autoSetOrderingLink($store);

        return back()->with('success', ___('Location linked. Your ordering link is set on Google.'));
    }

    /**
     * A6: best-effort set the preferred FOOD_ORDERING link to the store's EFFECTIVE
     * public URL (reseller/custom-domain aware) right after linking. Fail-soft — a link
     * error never blocks the location link itself.
     */
    private function autoSetOrderingLink(Store $store): void
    {
        try {
            $this->links->setOrderingLink($store->id, $this->orderingLinkFor($store));
        } catch (\Throwable $e) {
            // fail-soft: owner can retry via the "Set my ordering link" button.
        }
    }

    /**
     * biolink-tpl lane-d (T2-4b): the URL the Google ordering link should point at.
     * Honors the owner's stored Storefront/Bio choice, but a bio page that is not
     * live ALWAYS falls back to the effective storefront URL — a dark bio must never
     * publish a dead ordering link, so this is the regression guard for both the
     * auto-set (on location link) and manual "Set my ordering link" paths.
     */
    private function orderingLinkFor(Store $store): string
    {
        if (store_options($store->id, 'gbp_ordering_link_target', 'storefront') === 'bio') {
            $bio = BioLinkOutbound::liveUrl($store, 'social');
            if ($bio !== null) {
                return $bio;
            }
        }

        return effective_public_url($store);
    }

    public function connect(Request $request)
    {
        $store = $this->currentRestaurant($request);
        $data = $request->validate([
            'location_id' => ['required', 'string', 'max:191'],
            'access_token' => ['required', 'string', 'max:4096'],
            'refresh_token' => ['nullable', 'string', 'max:4096'],
        ]);

        Credential::updateOrCreate(
            ['store_id' => $store->id, 'feature' => 'gbp'],
            ['provider' => 'gbp', 'active' => true, 'secrets' => array_filter([
                'location_id' => $data['location_id'],
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
            ], fn ($v) => $v !== null && $v !== '')]
        );

        return back()->with('success', ___('Google location connected.'));
    }

    public function setLink(Request $request)
    {
        $store = $this->currentRestaurant($request);

        // biolink-tpl lane-d (T2-4b): persist the owner's Storefront/Bio choice
        // (default storefront) before resolving the link, so orderingLinkFor()
        // reads the fresh value. 'bio' is only honored while the bio page is live.
        $data = $request->validate([
            'ordering_target' => ['nullable', 'in:storefront,bio'],
        ]);
        if (array_key_exists('ordering_target', $data) && $data['ordering_target'] !== null) {
            StoreOption::updateStoreOption($store->id, 'gbp_ordering_link_target', $data['ordering_target']);
            StoreOption::flushOptionMemo();
        }

        try {
            // A6: EFFECTIVE public URL — reseller/custom-domain aware, not the raw slug route.
            $this->links->setOrderingLink($store->id, $this->orderingLinkFor($store));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', ___('Ordering link set as preferred on Google.'));
    }

    // ===== google-partner Lane A — OAuth connect (dark until services.gbp creds set) =====

    /**
     * Begin GBP OAuth: store a CSRF state + the target restaurant in the session and
     * redirect to Google's consent screen (business.manage, offline access). Separate
     * OAuth client from the Socialite login — see config('services.gbp').
     */
    public function oauthConnect(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $clientId = (string) config('services.gbp.client_id');
        if ($clientId === '') {
            // Dark: no OAuth app configured — fall back to the manual-paste card.
            return back()->withErrors(['gbp' => ___('Google connection is not available yet. Use the manual steps below.')]);
        }

        $state = GbpOAuth::newState();
        session(['gbp_oauth' => ['state' => $state, 'rid' => $store->id]]);

        return redirect()->away(GbpOAuth::authorizeUrl(
            $clientId,
            route('stores.gbp.oauth.callback'),
            $state
        ));
    }

    /**
     * OAuth callback: verify state (constant-time), exchange the code for tokens, store
     * them encrypted in Credential feature='gbp'. Location picking happens on the index
     * page after this (A4). No location is linked yet.
     */
    public function oauthCallback(Request $request): RedirectResponse
    {
        $ctx = session('gbp_oauth');
        if (! $ctx || ! GbpOAuth::stateMatches($ctx['state'] ?? null, $request->query('state'))) {
            abort(403, 'Invalid OAuth state');
        }
        session()->forget('gbp_oauth');

        $rid = (int) ($ctx['rid'] ?? 0);
        abort_unless(in_array($rid, $this->storeIds(), true), 403);

        if ($request->query('error') || ! $request->query('code')) {
            app_alert_error(___('Google authorization was cancelled.'));

            return redirect()->route('stores.gbp.index', ['store_id' => $rid]);
        }

        $token = GbpTokenService::exchange(
            (string) config('services.gbp.client_id'),
            (string) config('services.gbp.client_secret'),
            (string) $request->query('code'),
            route('stores.gbp.oauth.callback')
        );

        if (! $token) {
            app_alert_error(___('Google authorization failed. Please try again.'));

            return redirect()->route('stores.gbp.index', ['store_id' => $rid]);
        }

        GbpTokenService::store($rid, $token);

        app_alert_success(___('Google account connected. Now choose your location.'));

        return redirect()->route('stores.gbp.index', ['store_id' => $rid]);
    }

    /** A5: toggle nightly menu sync (stored in the gbp Credential secrets — no whitelist edit). */
    public function saveMenuSync(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $enabled = $request->boolean('gbp_menu_sync_enabled');

        $row = Credential::firstOrNew(['store_id' => $store->id, 'feature' => 'gbp']);
        if (! $row->exists && empty($row->secrets)) {
            return back()->withErrors(['gbp' => ___('Link a location first.')]);
        }
        $secrets = $row->secrets ?? [];
        $secrets['gbp_menu_sync_enabled'] = $enabled;
        $row->provider = 'gbp';
        $row->active = true;
        $row->secrets = $secrets;
        $row->save();

        return back()->with('success', $enabled ? ___('Menu sync enabled.') : ___('Menu sync disabled.'));
    }

    /** A5: queue an immediate menu sync (no-op behavior if not linked / disabled — job guards). */
    public function syncNow(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        $creds = Credential::for('gbp', $store->id) ?? [];
        if (empty($creds['location_id'])) {
            return back()->withErrors(['gbp' => ___('Link a location first.')]);
        }
        SyncGbpMenuJob::dispatch($store->id);

        return back()->with('success', ___('Menu sync queued.'));
    }

    /** Disconnect: remove the stored GBP credential (tokens + any linked location). */
    public function disconnect(Request $request): RedirectResponse
    {
        $store = $this->currentRestaurant($request);
        Credential::where('store_id', $store->id)->where('feature', 'gbp')->delete();

        app_alert_success(___('Google account disconnected.'));

        return redirect()->route('stores.gbp.index', ['store_id' => $store->id]);
    }
}
