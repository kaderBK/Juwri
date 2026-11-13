<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Accounting\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Models\ExternalInvoice;
use Juwri\Modules\Accounting\Services\Accounting\AccountingRegistry;

/**
 * #98: export a paid order to the restaurant's connected accounting provider
 * (QuickBooks / Xero) as a sales receipt. Idempotent — an order is exported at
 * most once per provider (external_invoices unique on order+provider). No-op
 * unless an 'accounting' connection is stored in the BYOK Credential vault.
 *
 * The connection (OAuth tokens + base_url) is provided per restaurant via the
 * vault; the interactive OAuth-connect UI is a follow-up (needs a provider app).
 */
class SyncInvoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public array $backoff = [60, 300];

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::with('items.menu')->find($this->orderId);
        if (! $order) {
            return;
        }

        $conn = Credential::for('accounting', (int) $order->store_id);
        if (empty($conn) || empty($conn['access_token']) || empty($conn['base_url'])) {
            return; // no accounting connection for this restaurant
        }

        $providerKey = $conn['provider'] ?? 'quickbooks';

        // Idempotency: only a row already marked 'synced' means the invoice was exported.
        // A 'failed' row must not block the retry.
        if (ExternalInvoice::where('order_id', $order->id)->where('provider', $providerKey)->where('status', 'synced')->exists()) {
            return;
        }

        // B3-P1: provider-shaped request (QuickBooks / Xero) behind a small contract.
        $provider = AccountingRegistry::get($providerKey);
        $payload = $provider->buildPayload($order);

        // P6-68b: base_url is merchant-supplied (checked at save) — re-vet and pin it at send time (no rebinding,
        // no redirects). Unsafe → skip, no retry.
        $endpoint = $provider->endpoint($conn);
        // P6-139: a stable per-order idempotency key, so a job killed after the provider took the invoice but before
        // the external_invoices row was written re-sends the SAME request and the provider answers with the first
        // invoice instead of booking a second sale. Xero reads the Idempotency-Key header; QuickBooks the requestid
        // query parameter (both keyed on order + provider, ≤ 50 chars).
        $idempotencyKey = 'juwri-order-'.$order->id.'-'.$providerKey;
        if ($providerKey === 'quickbooks') {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?').'requestid='.rawurlencode($idempotencyKey);
        }
        $guard = SsrfGuard::pinnedOptions($endpoint, false);
        if ($guard === null) {
            Log::warning('[accounting] sync skipped: provider URL is not a public https host');
            $this->recordFailed((int) $order->store_id, $order->id, $providerKey, 'Provider URL is not a public https host');

            return;
        }

        try {
            $res = Http::withToken($conn['access_token'])
                ->withHeaders($provider->headers($conn) + ['Idempotency-Key' => $idempotencyKey])
                ->acceptJson()
                ->timeout(30)->connectTimeout(5)
                ->withOptions($guard)
                ->post($endpoint, $payload);
        } catch (\Throwable $e) {
            Log::warning('[accounting] sync failed: '.$e->getMessage());
            throw $e; // let the queue retry
        }

        if ($res->failed()) {
            Log::warning('[accounting] provider error: '.$res->status());
            throw new \RuntimeException('accounting provider error '.$res->status());
        }

        ExternalInvoice::updateOrCreate(
            ['order_id' => $order->id, 'provider' => $providerKey],
            [
                'store_id' => $order->store_id,
                'external_id' => $provider->extractId($res),
                'status' => 'synced',
                'error' => null,
            ]
        );
    }

    /**
     * The queue calls this after the final attempt. It records the failure against
     * the order+provider so the owner sees a visible failed row (not just a failed_jobs
     * entry). A successful export must never be downgraded, so a 'synced' row is left alone.
     */
    public function failed(\Throwable $e): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        $conn = Credential::for('accounting', (int) $order->store_id);
        if (empty($conn)) {
            return;
        }

        $providerKey = $conn['provider'] ?? 'quickbooks';

        $message = $e->getMessage();
        foreach (['access_token', 'refresh_token', 'client_secret'] as $secret) {
            if (! empty($conn[$secret]) && is_string($conn[$secret])) {
                $message = str_replace($conn[$secret], '[redacted]', $message);
            }
        }

        $this->recordFailed((int) $order->store_id, $order->id, $providerKey, $message);
    }

    /**
     * Upsert a failed row for an order + provider (never overwriting a 'synced' row),
     * with the error truncated to the column's 255-char limit. Tokens are never stored.
     */
    private function recordFailed(int $storeId, int $orderId, string $providerKey, string $error): void
    {
        if (ExternalInvoice::where('order_id', $orderId)->where('provider', $providerKey)->where('status', 'synced')->exists()) {
            return;
        }

        ExternalInvoice::updateOrCreate(
            ['order_id' => $orderId, 'provider' => $providerKey],
            [
                'store_id' => $storeId,
                'status' => 'failed',
                'error' => Str::limit($error, 255, ''),
            ]
        );
    }
}
