<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T6 — proves the base TestCase arms Http::preventStrayRequests() in setUp,
 * so any test that makes an UNFAKED outbound HTTP call throws instead of
 * silently hitting the live network (a determinism hole: such a test passes
 * on a live network and flakes when it is down). This is the net that keeps
 * the guard from silently regressing if setUp is ever edited.
 */
class StrayRequestGuardTest extends TestCase
{
    public function test_unfaked_outbound_request_throws(): void
    {
        $this->expectException(StrayRequestException::class);

        // No Http::fake() — the guard armed by TestCase::setUp must reject this.
        Http::get('https://example.com/definitely-not-faked');
    }

    public function test_faked_request_is_allowed(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $response = Http::get('https://example.com/faked');

        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->body());
    }

    public function test_guard_can_be_opted_out_per_test(): void
    {
        // A subclass that genuinely cannot fake its subject can set
        // $preventStrayRequests = false; assert the escape hatch exists so the
        // opt-out documented on TestCase stays real.
        $this->assertObjectHasProperty('preventStrayRequests', $this);
    }
}
