<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * P6-09 V-04: Twilio ConversationRelay Mode A bridge.
 *
 * The real bridge is a WebSocket upgrader (V-06). Until that worker exists this
 * implementation owns no socket: it accepts the already-token-authenticated request,
 * keeps the per-call {@see TwilioRelayCodec}, and answers 426 Upgrade Required so the
 * handoff to the worker is explicit. Twilio retries with the WebSocket upgrade.
 */
final class TwilioRelayBridge implements VoiceBridge
{
    /** Codec bound to the accepted line; the worker (V-06) reads it after the upgrade. */
    public ?TwilioRelayCodec $codec = null;

    public function accept(Request $request, string $provider, PhoneLine $line, array $claims): Response
    {
        $this->codec = new TwilioRelayCodec($line);

        return response()->json([
            'bridge' => 'twilio-conversationrelay',
            'error' => 'websocket required',
            'line' => (int) $line->id,
        ], Response::HTTP_UPGRADE_REQUIRED);
    }
}
