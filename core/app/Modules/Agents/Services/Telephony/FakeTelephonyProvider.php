<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * Fake telephony provider for CI / no-creds. Skips signature verification (a scripted
 * test harness), reads the dialled/caller numbers from plain params, and returns a
 * trivial answer body. No network, no audio.
 */
class FakeTelephonyProvider implements TelephonyProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function verifyWebhook(Request $request, PhoneLine $line): bool
    {
        return true;
    }

    public function dialledNumber(Request $request): ?string
    {
        return $request->input('To');
    }

    public function callerNumber(Request $request): ?string
    {
        return $request->input('From');
    }

    public function answer(PhoneLine $line, string $wsUrl): array
    {
        return ['<Response ws="'.$wsUrl.'"/>', 'text/xml'];
    }

    public function unavailable(PhoneLine $line): array
    {
        return ['<Response unavailable="1"/>', 'text/xml'];
    }

    /**
     * P6-09 V-13: no network in CI. Returns the warm-transfer instruction as a
     * plain array so tests can assert the number + whisper summary without parsing
     * TwiML. Shape: [body(array), contentType].
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function transfer(PhoneLine $line, string $number, string $summary): array
    {
        return [[
            'transfer' => $number,
            'whisper' => $summary,
            'line' => (int) $line->id,
        ], 'application/json'];
    }
}
