<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers;

use App\Services\Agents\AgentChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Contracts\Webhooks\WebhookDeduplication;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Modules\Messaging\Services\SmsService;

/**
 * Inbound SMS webhook (Twilio / Vonage forward replies here). Handles the
 * STOP / UNSUBSCRIBE keyword by recording a global opt-out for that number,
 * and START / UNSTOP to remove it.
 *
 * Public endpoint (no session auth) — providers post here. Keep it defensive.
 */
class SmsWebhookController extends Controller
{
    private const STOP_WORDS = ['stop', 'unsubscribe', 'stopall', 'cancel', 'end', 'quit'];

    private const START_WORDS = ['start', 'unstop', 'subscribe', 'yes'];

    public function inbound(Request $request, SmsService $sms)
    {
        // Twilio uses From/Body; Vonage uses msisdn/text.
        $from = (string) ($request->input('From') ?? $request->input('msisdn') ?? '');
        $body = (string) ($request->input('Body') ?? $request->input('text') ?? '');

        $phone = $sms->normalizePhone($from);
        $keyword = strtolower(trim($body));

        if ($phone === '' || $keyword === '') {
            return response('OK', 200);
        }

        // Resolve the destination and verify the provider before ANY state
        // change, including global STOP/START. Otherwise an anonymous request
        // could opt arbitrary phone numbers out without proving provider origin.
        $to = (string) ($request->input('To') ?? $request->input('to') ?? '');
        $cred = $this->resolveCredential($sms->normalizePhone($to));
        if (! $cred) {
            return response('OK', 200); // unknown destination — ignore
        }

        if (! $this->signatureValid($request, $cred)) {
            Log::warning('[SMS] inbound signature rejected', ['to' => $to]);

            return response('invalid signature', 403);
        }

        if (in_array($keyword, self::STOP_WORDS, true)) {
            SmsOptout::firstOrCreate(
                ['phone' => $phone, 'store_id' => null],
                ['source' => 'reply']
            );
            Log::info("[SMS] opt-out recorded for {$phone}");

            return response('OK', 200);
        }
        if (in_array($keyword, self::START_WORDS, true)) {
            SmsOptout::where('phone', $phone)->whereNull('store_id')->delete();
            Log::info("[SMS] opt-in (STOP removed) for {$phone}");

            return response('OK', 200);
        }

        // AO7 #35: SMS ordering. A non-keyword message goes to the agent tool loop,
        // routed to the restaurant that owns the destination number. Because this is a
        // public endpoint, the request must be PROVEN to come from the provider before
        // we spend AI/SMS credits or reply to a client-supplied number:
        //   1) the inbound number must map to a restaurant's active SMS credential,
        //   2) the provider signature must verify against that credential's token,
        //   3) the sender must not have opted out (no reply to STOP'd numbers).
        // The route is also rate-limited (see routes/web.php) as a backstop.
        $storeId = (int) $cred->store_id;
        if (SmsOptout::isOptedOut($phone, $storeId)) {
            return response('OK', 200); // opted out — never message them
        }

        // Replay guard: providers retry inbound webhooks. P6-100: dedupe on the provider's message id through the
        // durable webhook_receipts claim (was a Cache::add that a cache flush forgot and that a failing agent run
        // still "spent"), so a redelivery of an order SMS never re-invokes the AI agent (credits, a second order)
        // or re-replies to the customer, while a run that throws is released for the provider's retry.
        $sid = (string) ($request->input('MessageSid') ?? $request->input('SmsMessageSid') ?? $request->input('messageId') ?? '');

        return app(WebhookDeduplication::class)->once(
            'sms_inbound',
            $sid !== '' ? $storeId.':'.$sid : null,
            function () use ($sms, $storeId, $phone, $body) {
                $reply = AgentChatService::forSms($storeId, $phone, $body);
                if ($reply !== null && $reply !== '') {
                    // marketing=true → SmsService honours opt-out as a second guard.
                    $sms->send($phone, $reply, $storeId, 'agent_sms', true);
                }

                // Providers expect a 2xx; empty body is fine.
                return response('OK', 200);
            },
            fn () => response('OK', 200),
        );
    }

    /** AO7 #35: the active SMS credential whose from-number owns this inbound destination. */
    private function resolveCredential(string $toNumber): ?Credential
    {
        if ($toNumber === '') {
            return null;
        }
        // secrets are encrypted (can't be indexed in SQL), so the from-number → credential
        // map is built ONCE and cached briefly, instead of decrypting every tenant's SMS
        // credential on every inbound message (the previous O(N-tenants) per-request scan).
        $map = Cache::remember('sms:inbound_routes', 60, function () {
            $sms = app(SmsService::class);
            $routes = [];
            foreach (Credential::where('feature', 'sms')->where('active', true)->whereNotNull('store_id')->get() as $cred) {
                $s = (array) $cred->secrets;
                $from = (string) ($s['twilio_from'] ?? $s['nexmo_from'] ?? $s['from_number'] ?? '');
                if ($from !== '') {
                    $routes[$sms->normalizePhone($from)] = $cred->id;
                }
            }

            return $routes;
        });

        $credId = $map[$toNumber] ?? null;

        return $credId ? Credential::find($credId) : null;
    }

    /**
     * Verify the Twilio request signature against the restaurant's own auth token:
     * base64(HMAC-SHA1(token, url + sorted param k+v)). Missing verification
     * secrets fail closed unless the explicit local-only compatibility flag is on.
     */
    private function signatureValid(Request $request, Credential $cred): bool
    {
        $s = (array) $cred->secrets;
        $token = (string) ($s['twilio_token'] ?? $s['auth_token'] ?? '');
        if ($token === '') {
            Log::error('[SMS] inbound credential has no verification secret', [
                'credential_id' => $cred->id,
                'provider' => $cred->provider,
            ]);

            // P6-249 sweep: unsigned inbound is a local-only compatibility switch; production always refuses.
            return ! app()->environment('production') && (bool) config('sms.inbound_allow_unsigned', false);
        }
        $signature = (string) $request->header('X-Twilio-Signature', '');
        if ($signature === '') {
            return false;
        }
        $params = $request->except([]); // all POST params
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $k => $v) {
            $data .= $k.(is_array($v) ? implode('', $v) : (string) $v);
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $token, true));

        return hash_equals($expected, $signature);
    }
}
