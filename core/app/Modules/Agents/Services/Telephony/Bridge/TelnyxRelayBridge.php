<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;
use Symfony\Component\HttpFoundation\Response;

/**
 * V-05: Telnyx AI text-relay bridge. There is no socket inside the request cycle, so
 * accept() holds a codec for the authenticated call and tells the caller a WebSocket
 * upgrade is required (the Cloud worker owns the actual socket). All tenancy comes from
 * the resolved PhoneLine, never from the request.
 */
final class TelnyxRelayBridge implements VoiceBridge
{
    private ?TelnyxRelayCodec $codec = null;

    /**
     * @param  array{p: string, l: int, s: int, c: string, exp: int, n: string}  $claims
     */
    public function accept(Request $request, string $provider, PhoneLine $line, array $claims): Response
    {
        $this->codec = new TelnyxRelayCodec($line, $claims['c']);

        return response()->json([
            'error' => 'websocket required',
            'provider' => $provider,
            'bridge' => 'telnyx-relay',
        ], Response::HTTP_UPGRADE_REQUIRED);
    }

    /** The per-call codec built on accept(), if this bridge has accepted a call. */
    public function codec(): ?TelnyxRelayCodec
    {
        return $this->codec;
    }
}
