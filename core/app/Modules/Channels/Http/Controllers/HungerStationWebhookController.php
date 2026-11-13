<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Http\Controllers;

use Juwri\Modules\Channels\Jobs\ProcessHungerStationOrderJob;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Models\HsEventLog;
use Illuminate\Http\Request;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;
use Juwri\Core\Http\Controllers\Controller;

/**
 * Inbound HungerStation webhook (new order + status changes). Verifies the tenant's
 * signature, returns 200 fast, and queues the heavy work. Idempotent downstream, so
 * HS's up-to-5 retries never double-create.
 *
 * Signature scheme (HS's exact header/algorithm is an open question): HMAC-SHA256 over
 * the raw body using the tenant's webhook_secret, header X-HS-Signature. Verification
 * fails closed when the vendor or secret is missing, and can be explicitly disabled
 * only via config('services.hungerstation.verify_signatures') for local fixtures.
 */
class HungerStationWebhookController extends Controller
{
    public function inbound(Request $request)
    {
        $payload = $request->json()->all() ?: $request->all();
        $vendorId = (string) ($payload['vendor_id'] ?? '');

        // P1-13 13c-R: a Cloud partner relay (when bound) vouches for the webhooks it forwards; direct = HS's signature.
        if (! PartnerTransports::verifyInbound('hungerstation', $request, fn (Request $r) => $this->signatureOk($r, $vendorId))) {
            HsEventLog::record('in', 'order.bad_signature', ['ok' => false, 'request' => ['vendor_id' => $vendorId]]);

            return response()->json(['error' => 'invalid signature'], 401);
        }

        // Fast 200 + queue the ingest (idempotent).
        ProcessHungerStationOrderJob::dispatch($payload);

        return response()->json(['received' => true], 202);
    }

    private function signatureOk(Request $request, string $vendorId): bool
    {
        // P6-249 sweep: the off switch is for local testing only; production always verifies.
        if (! app()->environment('production') && ! config('services.hungerstation.verify_signatures', true)) {
            return true;
        }

        $secret = $this->secretForVendor($vendorId);
        if (! $secret) {
            return false;
        }

        $sig = (string) $request->header('X-HS-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return $sig !== '' && hash_equals($expected, $sig);
    }

    private function secretForVendor(string $vendorId): ?string
    {
        if ($vendorId === '') {
            return null;
        }
        $branch = Branch::where('hs_vendor_id', $vendorId)->first();
        if (! $branch) {
            return null;
        }
        $creds = Credential::for('hungerstation', (int) $branch->store_id);

        return $creds['webhook_secret'] ?? null;
    }
}
