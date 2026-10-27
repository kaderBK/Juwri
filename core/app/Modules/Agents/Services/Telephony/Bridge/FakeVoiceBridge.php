<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;
use Symfony\Component\HttpFoundation\Response;

/** P6-09 V-03: CI / no-worker bridge. Records accepted connections; no audio, no socket. */
class FakeVoiceBridge implements VoiceBridge
{
    /** @var list<array{provider: string, line: int, store: int, call: string}> */
    public array $accepted = [];

    public function accept(Request $request, string $provider, PhoneLine $line, array $claims): Response
    {
        $this->accepted[] = ['provider' => $provider, 'line' => (int) $line->id, 'store' => (int) $line->store_id, 'call' => $claims['c']];

        return response()->json(['bridge' => 'fake', 'line' => (int) $line->id, 'call' => $claims['c']]);
    }
}
