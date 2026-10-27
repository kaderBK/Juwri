<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S4-P0: Menu Studio foundations — public guest session + purge job.
 */
class StudioFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\EncryptCookies::class, // let the raw guest cookie round-trip in tests
        ]);
    }

    public function test_public_studio_creates_guest_session_without_auth(): void
    {
        $res = $this->get('/studio');

        $res->assertOk();
        $res->assertCookie('studio_guest');
        $this->assertSame(1, StudioSession::count());
        $this->assertNotNull(StudioSession::first()->expires_at);
        $this->assertNull(StudioSession::first()->user_id);
    }

    public function test_valid_cookie_reuses_session_expired_mints_new(): void
    {
        $ctrl = new \Juwri\Modules\Content\Http\Controllers\Studio\StudioController();
        $resolve = new \ReflectionClass($ctrl)->getMethod('resolveSession');
        $resolve->setAccessible(true);

        // Valid token -> reuse.
        $live = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $req = \Illuminate\Http\Request::create('/studio', 'GET');
        $req->cookies->set('studio_guest', $live->guest_token);
        $this->assertSame($live->id, $resolve->invoke($ctrl, $req)->id);
        $this->assertSame(1, StudioSession::count());

        // Expired token -> mint a fresh session.
        $expired = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->subHour()]);
        $req2 = \Illuminate\Http\Request::create('/studio', 'GET');
        $req2->cookies->set('studio_guest', $expired->guest_token);
        $fresh = $resolve->invoke($ctrl, $req2);
        $this->assertNotSame($expired->id, $fresh->id);
        $this->assertTrue($fresh->expires_at->isFuture());
    }

    public function test_set_output_persists_choice_on_session(): void
    {
        $res = $this->post('/studio/output', ['output_type' => 'pdf']);

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('output_type', 'pdf');
        $s = StudioSession::first();
        $this->assertSame('pdf', $s->output_type);
        $this->assertSame('onboarding', $s->status);
    }

    public function test_set_output_rejects_invalid_type(): void
    {
        $this->postJson('/studio/output', ['output_type' => 'bogus'])->assertStatus(422);
        $this->assertSame(0, StudioSession::whereNotNull('output_type')->count());
    }

    public function test_prune_deletes_only_expired_guest_sessions(): void
    {
        $expiredGuest = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->subHour()]);
        $liveGuest    = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHour()]);
        $expiredOwned = StudioSession::create(['guest_token' => Str::random(48), 'user_id' => 5, 'expires_at' => now()->subHour()]);

        Artisan::call('studio:prune');

        $this->assertNull(StudioSession::find($expiredGuest->id));     // purged
        $this->assertNotNull(StudioSession::find($liveGuest->id));     // still valid
        $this->assertNotNull(StudioSession::find($expiredOwned->id));  // migrated to account -> kept
    }
}
