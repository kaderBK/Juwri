<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use App\Services\FeatureAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Services\Telephony\VoiceErrorBudget;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\BridgeToken;
use Juwri\Modules\Agents\Services\Telephony\TelephonyRegistry;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;

/**
 * T6 inbound-call webhook. The provider posts here when a customer dials the
 * restaurant's number; we resolve the line (tenant) from the DIALLED number, verify the
 * provider signature, and answer by connecting the audio to our voice bridge WebSocket
 * (ConversationRelay / TeXML). Off-hours or a disabled line → a graceful spoken message.
 */
class VoiceWebhookController extends Controller
{
    public function incoming(Request $request, string $provider)
    {
        $started = microtime(true);
        $prov = TelephonyRegistry::get($provider);
        if (! $prov) {
            return response('Unknown provider', 404);
        }

        $number = $prov->dialledNumber($request);
        $line = $number ? PhoneLine::resolve($provider, $number) : null;
        if (! $line) {
            return response('No line', 404);
        }

        if (! $prov->verifyWebhook($request, $line)) {
            VoiceErrorBudget::signatureReject((int) $line->store_id);

            return response('Invalid signature', 401);
        }

        /* FIX-2 F2-13 (gap audit): the inbound webhook was the ai_phone_agent side
           door — the dashboard routes are `feature:`-gated, but a provider POST has
           no session user, so nothing here read the SKU and a lapsed tenant kept a
           fully-served AI agent. Subject = the STORE OWNER (the tenant who buys the
           line), resolved from the line, exactly as CrossCartService resolves a
           public shopper surface. Deliberately NOT route middleware: `feature:`
           subjects an anonymous caller via dashboard_owner() and would 403 every
           provider call (FIX-1 precedent). Refusal is a graceful 200 answer — a
           non-2xx here just makes the provider retry and drops a live caller. */
        if (! self::agentAvailable($line)) {
            [$body, $type] = $prov->unavailable($line);

            return response($body, 200)->header('Content-Type', $type);
        }

        /* P6-09 V-01: the bridge URL carries a signed single-use token bound to
           provider + line + store + call id; the bridge refuses without it. */
        $callId = self::callId($request);
        if ($callId === null) {
            return response('Missing call id', 400);
        }
        $wsUrl = $this->bridgeUrl($provider, $line).'?t='.rawurlencode(BridgeToken::issue($provider, $line, $callId));
        [$body, $type] = $prov->answer($line, $wsUrl);
        VoiceErrorBudget::answerLatency((int) $line->store_id, (int) round((microtime(true) - $started) * 1000));

        return response($body, 200)->header('Content-Type', $type);
    }

    /** Provider call states that end a call (Twilio / Telnyx TeXML `CallStatus`). */
    private const FINAL_STATES = ['completed', 'busy', 'failed', 'no-answer', 'canceled'];

    /**
     * P6-09 V-12: provider call-status callback. Same line resolution + signature check as {@see incoming()}; a
     * final status carries the billed `CallDuration`, which closes the call and writes its cost row. Only a call
     * this line opened is touched (a foreign / unknown call SID is acknowledged and ignored).
     */
    public function status(Request $request, string $provider): Response
    {
        $prov = TelephonyRegistry::get($provider);
        if (! $prov) {
            return response('Unknown provider', 404);
        }
        $number = $prov->dialledNumber($request);
        $line = $number ? PhoneLine::resolve($provider, $number) : null;
        if (! $line) {
            return response('No line', 404);
        }
        if (! $prov->verifyWebhook($request, $line)) {
            VoiceErrorBudget::signatureReject((int) $line->store_id);

            return response('Invalid signature', 401);
        }

        try {
            $callId = self::callId($request);
            $state = strtolower((string) $request->input('CallStatus', ''));
            $duration = $request->input('CallDuration');
            if ($callId === null || ! in_array($state, self::FINAL_STATES, true) || ! is_numeric($duration)) {
                return response('', 204);
            }
            $owned = CallLog::where('call_sid', $callId)
                ->where('store_id', $line->store_id)
                ->where('phone_line_id', $line->id)
                ->exists();
            if ($owned) {
                VoiceSession::finalize($callId, $state === 'failed' ? 'failed' : 'hangup', min(86400, max(0, (int) $duration)));
            }
        } catch (\Throwable $e) {
            VoiceErrorBudget::callbackError((int) $line->store_id);

            throw $e;
        }

        return response('', 204);
    }

    /**
     * FIX-2 F2-13: may this LINE be served by the AI agent? Kill-switch is the
     * veto (unchanged); once `ai_phone_agent` is plan_gated the line's store owner
     * must also be entitled — plan row, add-on, or grandfather override. Fails
     * closed: an unresolvable owner never gets a served agent.
     */
    public static function agentAvailable(PhoneLine $line): bool
    {
        try {
            return FeatureAccess::storeAllows((int) $line->store_id, 'ai_phone_agent');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Provider call id: Twilio/Telnyx TeXML `CallSid`, Telnyx call control id. */
    private static function callId(Request $request): ?string
    {
        foreach (['CallSid', 'call_control_id', 'CallControlId'] as $key) {
            $v = $request->input($key);
            if (is_string($v) && $v !== '' && strlen($v) <= 128) {
                return $v;
            }
        }

        return null;
    }

    private function bridgeUrl(string $provider, PhoneLine $line): string
    {
        $base = (string) (config('services.telephony.ws_base') ?: str_replace(['https://', 'http://'], 'wss://', url('/')));

        return rtrim($base, '/').'/voice/'.$provider.'/bridge/'.$line->id;
    }
}
