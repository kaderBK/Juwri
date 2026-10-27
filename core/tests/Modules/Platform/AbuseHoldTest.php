<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseHold;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiSpendGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abuse Guard P6 (6.3): per-merchant abuse hold freezes AI + dashboard writes;
 * public menus stay live (the middleware only guards the authed dashboard group).
 */
class AbuseHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_hold_toggle_and_active(): void
    {
        $u = User::factory()->create();
        $this->assertFalse(AbuseHold::active($u->id));
        AbuseHold::set($u->id, true);
        $this->assertTrue(AbuseHold::active($u->id));
        AbuseHold::set($u->id, false);
        $this->assertFalse(AbuseHold::active($u->id));
    }

    public function test_hold_freezes_ai_regardless_of_mode(): void
    {
        config(['settings.abuse_ai_spend_mode' => 'off']); // even off, hold wins
        $u = User::factory()->create();
        AbuseHold::set($u->id, true);

        $this->expectException(AiLimitExceededException::class);
        AiSpendGuard::assert($u->id, null, false);
    }

    public function test_write_wall_blocks_held_merchant(): void
    {
        $u = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        AbuseHold::set($u->id, true);

        \Illuminate\Support\Facades\Route::post('/_t/hold', fn () => 'ok')
            ->middleware(['web', 'auth', 'abuse.hold']);

        $this->actingAs($u)->post('/_t/hold', [], ['HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(423);
    }

    public function test_write_wall_allows_reads_and_unheld(): void
    {
        $u = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        \Illuminate\Support\Facades\Route::post('/_t/hold2', fn () => 'ok')
            ->middleware(['web', 'auth', 'abuse.hold']);

        // not held → passes
        $this->actingAs($u)->post('/_t/hold2', [], ['HTTP_ACCEPT' => 'application/json'])->assertOk();
    }
}
