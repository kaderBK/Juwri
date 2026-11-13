<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers;

use App\Services\PlatformMetrics;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Messaging\Jobs\ProcessWhatsAppWebhookJob;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;

/**
 * Public WhatsApp webhook endpoint. GET verifies the subscription (Meta
 * hub.challenge); POST authenticates the signature, then enqueues processing
 * and returns 200 fast so the provider doesn't retry. (spec §6/§15.1/§15.2)
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(protected WhatsAppProviderFactory $factory) {}

    /** GET /whatsapp-webhook/{provider} — subscription verification. */
    public function verify(Request $request, string $provider)
    {
        $driver = $this->factory->makeByKey($provider, null);
        if (! $driver) {
            abort(404);
        }

        $result = $driver->verifyWebhook($request->query(), $this->headers($request), (string) $request->getContent());

        if (is_string($result)) {
            return response($result, 200);
        }

        return response('', $result ? 200 : 403);
    }

    /** POST /whatsapp-webhook/{provider} — inbound + status callbacks. */
    public function handle(Request $request, string $provider)
    {
        // C1: the wa.me link driver has no inbound webhook and no secret; a POST
        // here can only be forged traffic that costs us a queued job.
        if ($provider === 'link') {
            abort(404);
        }

        $raw = (string) $request->getContent();
        $params = $request->all();
        // Meta wraps events in JSON; Twilio posts form fields. Pass whatever we got.
        $payload = $request->isJson() ? $request->json()->all() : $params;

        /* C1: resolve the TENANT FIRST, then authenticate with that tenant's key.
           The probe driver is built with a null tenant and is used ONLY to parse
           the payload — parsing needs no secret. `tenantHint()` returns the
           business identity the payload claims to have been sent to; that claim is
           untrusted, and is used solely to pick WHICH secret to verify against. A
           forged hint therefore selects a key the attacker cannot sign with. */
        $probe = $this->factory->makeByKey($provider, null);
        if (! $probe) {
            abort(404);
        }

        $storeId = WhatsAppRoute::restaurantFor($provider, $probe->tenantHint($payload));
        $driver = $this->factory->makeByKey($provider, $storeId) ?? $probe;

        if (whatsapp_verify_signatures()
            && ! $driver->authenticate($this->headers($request), $raw, $request->fullUrl(), $request->post())) {
            PlatformMetrics::record('whatsapp.webhook_rejected', $provider);

            return response('invalid signature', 403);
        }

        if (! whatsapp_verify_signatures()) {
            // C1: signature verification is OFF platform-wide. Counted so the
            // superadmin panel can show it is off and how much it let through.
            PlatformMetrics::record('whatsapp.webhook_unverified', $provider);
        }

        ProcessWhatsAppWebhookJob::dispatch($provider, $payload);

        return response('', 200);
    }

    /** @return array<string, array<int, string>> lower-cased header map */
    protected function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $k => $v) {
            $out[strtolower($k)] = $v;
        }

        return $out;
    }
}
