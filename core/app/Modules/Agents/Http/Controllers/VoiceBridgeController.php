<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\BridgeToken;
use Juwri\Modules\Agents\Services\Telephony\Bridge\VoiceBridge;
use Juwri\Modules\Agents\Services\Telephony\TelephonyRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-09 V-03: entry point of the voice bridge `/voice/{provider}/bridge/{line}`.
 * Fails closed: no valid single-use token bound to this provider + line + the
 * line's store → 401, before anything about the line is used. The entitlement is
 * re-checked on every connect (a lapse between webhook and connect is refused).
 */
class VoiceBridgeController extends Controller
{
    public function connect(Request $request, string $provider, string $line, VoiceBridge $bridge): Response
    {
        if (! TelephonyRegistry::get($provider) || ! ctype_digit($line)) {
            return response('Not found', 404);
        }
        $phoneLine = PhoneLine::query()->whereKey((int) $line)->where('provider', $provider)->where('active', true)->first();
        $token = $request->query('t');
        $claims = $phoneLine ? BridgeToken::verify(is_string($token) ? $token : null, $provider, $phoneLine) : null;
        if (! $phoneLine || ! $claims || ! BridgeToken::consume($claims)) {
            return response('Unauthorized', 401);
        }
        if (! VoiceWebhookController::agentAvailable($phoneLine)) {
            return response('Forbidden', 403);
        }

        return $bridge->accept($request, $provider, $phoneLine, $claims);
    }
}
