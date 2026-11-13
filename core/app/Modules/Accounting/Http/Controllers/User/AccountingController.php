<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Models\ExternalInvoice;

/**
 * GAPS-RUN-2 B3-P2: owner accounting UI — pick provider, paste BYOK credentials,
 * see the sync log, retry, disconnect. The interactive OAuth connect flow needs a
 * platform developer app (DEFERRED); paste-creds works today. Tenant scoped; behind
 * the accounting_sync kill-switch.
 */
class AccountingController extends Controller
{
    use ResolvesCurrentStore;

    private const PROVIDERS = ['quickbooks', 'xero'];

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $cred = Credential::where('store_id', $rid)->where('feature', 'accounting')->first();
        $log = ExternalInvoice::where('store_id', $rid)->latest()->limit(50)->get();
        $failedCount = ExternalInvoice::where('store_id', $rid)->where('status', 'failed')->count();

        return view('owner.accounting.index', [
            'rid' => $rid,
            'providers' => self::PROVIDERS,
            'secrets' => $cred ? (array) $cred->secrets : [],
            'connected' => (bool) $cred,
            'log' => $log,
            'failedCount' => $failedCount,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function connect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'provider' => ['required', 'in:quickbooks,xero'],
            'base_url' => ['required', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'refresh_token' => ['nullable', 'string', 'max:4096'],
            'tenant_id' => ['nullable', 'string', 'max:191'], // Xero
            'token_url' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:191'],
            'client_secret' => ['nullable', 'string', 'max:191'],
        ]);

        // S7: the SyncInvoice job POSTs customer PII to base_url/token_url, so both
        // must be public HTTPS endpoints — reject private/loopback/metadata hosts
        // and any non-https scheme up front (mirrors the ApiToken webhook guard).
        foreach (['base_url' => $data['base_url'], 'token_url' => $data['token_url'] ?? null] as $field => $url) {
            if ($url === null || $url === '') {
                continue;
            }
            if (! str_starts_with($url, 'https://') || ! SsrfGuard::isSafePublicUrl($url)) {
                return back()->withErrors([
                    $field => ___('Must be a public HTTPS endpoint (no private, local or metadata addresses).'),
                ]);
            }
        }

        // Secrets are never echoed back to the form, so a blank field means "keep the
        // stored value" — merge over the existing secrets rather than wiping them.
        $existing = (array) (Credential::where('store_id', $rid)->where('feature', 'accounting')->value('secrets') ?? []);
        $secretKeys = ['access_token', 'refresh_token', 'client_secret'];
        $merged = ['provider' => $data['provider'], 'base_url' => $data['base_url']];
        foreach (['access_token', 'refresh_token', 'tenant_id', 'token_url', 'client_id', 'client_secret'] as $k) {
            $val = $data[$k] ?? null;
            if (($val === null || $val === '') && in_array($k, $secretKeys, true)) {
                $val = $existing[$k] ?? null; // keep the stored secret when left blank
            }
            $merged[$k] = $val;
        }
        if (empty($merged['access_token'])) {
            return back()->withErrors(['access_token' => ___('An access token is required to connect.')]);
        }

        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => 'accounting'],
            [
                'provider' => $data['provider'],
                'active' => true,
                'secrets' => array_filter($merged, fn ($v) => $v !== null && $v !== ''),
            ]
        );

        return back()->with('success', ___('Accounting connection saved.'));
    }

    public function disconnect(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        Credential::where('store_id', $rid)->where('feature', 'accounting')->delete();

        return back()->with('success', ___('Accounting disconnected.'));
    }

    public function retry(Request $request, int $orderId)
    {
        $rid = $this->currentRestaurantId($request);
        // Only the owner's own order may be re-synced.
        abort_unless(Order::where('id', $orderId)->where('store_id', $rid)->exists(), 404);

        SyncInvoiceJob::dispatch($orderId);

        return back()->with('success', ___('Re-sync queued.'));
    }

    public function retryAllFailed(Request $request): RedirectResponse
    {
        $rid = $this->currentRestaurantId($request);
        $rows = ExternalInvoice::where('store_id', $rid)->where('status', 'failed')->limit(200)->get();

        $rows->each(fn (ExternalInvoice $invoice) => SyncInvoiceJob::dispatch($invoice->order_id));

        return back()->with('success', ___(':count orders queued for re-sync.', ['count' => $rows->count()]));
    }
}
