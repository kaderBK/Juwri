<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\Installed;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W2-CONTROLLER: StudioController now delegates session ownership to
 * StudioSessionService. This proves the wiring (SPEC §7 W2-CONTROLLER):
 *  - every action still resolves a session (guest + authed);
 *  - an authed owner reuses ONE account session across requests (durable
 *    beyond the cookie), not a fresh one each time;
 *  - the guest funnel is byte-for-byte unchanged;
 *  - `?resume` is ownership-safe — a session owned by user B is never absorbed
 *    by user A, while an unowned token is claimed for the signer.
 *
 * studio_sessions.user_id carries no FK; we authenticate real users via
 * actingAs() so the controller's auth()->id() resolves.
 */
class StudioControllerOwnerSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            EncryptCookies::class, // let the raw guest cookie round-trip
        ]);
        Storage::fake('local');
    }

    private function mkSession(array $attrs = []): StudioSession
    {
        return StudioSession::create(array_merge([
            'guest_token' => Str::random(48),
            'status' => 'exploring',
            'expires_at' => now()->addHours(48),
        ], $attrs));
    }

    // ---- every action still resolves --------------------------------------

    public function test_app_shell_resolves_for_guest_and_owner(): void
    {
        // Guest: renders + mints a cookie-scoped session (the free funnel).
        $this->get('/studio/app')->assertOk();

        // Owner: renders too (auth()->id() drives the owner-aware resolve).
        $user = User::factory()->create();
        $this->actingAs($user)->get('/studio/app')->assertOk();
    }

    public function test_json_action_resolves_for_guest_and_owner(): void
    {
        // A non-rendering action (pricing) still resolves through the service.
        $this->getJson('/studio/pricing')->assertOk()->assertJsonStructure(['balance', 'costs', 'bundles']);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/studio/pricing')->assertOk()->assertJsonPath('balance', 0);
    }

    // ---- owner reuses ONE session across requests -------------------------

    public function test_authed_owner_reuses_single_session_across_requests(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Two independent GETs (no cookie carried between them): the account
        // binding — not the cookie — must keep the owner on one session.
        $this->get('/studio/app')->assertOk();
        $this->get('/studio/app')->assertOk();

        $owned = StudioSession::where('user_id', $user->id)->get();
        $this->assertCount(1, $owned, 'an owner reuses one account session, not a fresh one per request');

        $session = $owned->first();
        $this->assertNotSame('published', $session->status, 'the reused session is live');
        $this->assertNull($session->expires_at, 'owned sessions do not purge');
    }

    // ---- guest funnel unchanged -------------------------------------------

    public function test_guest_app_shell_mints_unowned_ttl_session(): void
    {
        $this->get('/studio/app')->assertOk();

        $this->assertSame(1, StudioSession::count());
        $session = StudioSession::first();
        $this->assertNull($session->user_id, 'a guest session is never owned');
        $this->assertNotNull($session->expires_at, 'guest sessions keep the 48h TTL');
        $this->assertTrue($session->expires_at->isFuture());
    }

    public function test_guest_resume_restores_prior_session_without_claiming(): void
    {
        $prior = $this->mkSession();

        $res = $this->get('/studio?resume='.$prior->guest_token);

        $res->assertOk();
        $res->assertCookie('studio_guest', $prior->guest_token, false); // plain (EncryptCookies bypassed)
        $this->assertSame(1, StudioSession::count(), 'no new session minted on resume');
        $this->assertNull($prior->fresh()->user_id, 'a guest resume never claims');
    }

    // ---- ?resume ownership isolation --------------------------------------

    public function test_resume_claims_unowned_session_for_signed_in_user(): void
    {
        $user = User::factory()->create();
        $unowned = $this->mkSession(); // user_id null

        $this->actingAs($user)->get('/studio?resume='.$unowned->guest_token)->assertOk();

        $fresh = $unowned->fresh();
        $this->assertSame($user->id, (int) $fresh->user_id, 'an unowned resume token is claimed for the signer');
        $this->assertNull($fresh->expires_at, 'a claimed session no longer purges');
        $this->assertSame(1, StudioSession::count(), 'the session is claimed, not duplicated');
    }

    public function test_resume_never_absorbs_a_session_owned_by_another_user(): void
    {
        $owner = User::factory()->create();   // user B
        $viewer = User::factory()->create();  // user A
        $bSession = $this->mkSession(['user_id' => $owner->id, 'expires_at' => null]);

        // User A resumes B's session by its token.
        $this->actingAs($viewer)->get('/studio?resume='.$bSession->guest_token)->assertOk();

        // Ownership is never reassigned; A does not absorb B's session.
        $this->assertSame($owner->id, (int) $bSession->fresh()->user_id, "user B's session is never reassigned to A");
        $this->assertSame(1, StudioSession::count(), 'B\'s session is read as-is, not cloned or claimed');
    }
}
