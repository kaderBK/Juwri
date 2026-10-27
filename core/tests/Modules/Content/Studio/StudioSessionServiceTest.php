<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W1-OWNERSHIP: StudioSessionService resolves which session a request owns.
 * Guest funnel stays cookie-scoped; authed requests bind to the account; an
 * owned session is never handed to a different user and never reassigned.
 *
 * studio_sessions.user_id carries no FK, so tests use bare int user ids.
 */
class StudioSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const USER_A = 401;

    private const USER_B = 402;

    private function svc(): StudioSessionService
    {
        return new StudioSessionService;
    }

    /** Request carrying (or not) the studio_guest cookie. */
    private function req(?string $token = null): Request
    {
        return Request::create(
            '/studio/app',
            'GET',
            [],
            $token !== null ? ['studio_guest' => $token] : []
        );
    }

    private function mkSession(array $attrs = []): StudioSession
    {
        return StudioSession::create(array_merge([
            'guest_token' => Str::random(48),
            'status' => 'exploring',
            'expires_at' => now()->addHours(48),
        ], $attrs));
    }

    // ---- resolve(): authed --------------------------------------------------

    public function test_authed_resume_returns_owners_latest_live_session(): void
    {
        // Two live sessions owned by user A (owned sessions never purge).
        $older = $this->mkSession(['user_id' => self::USER_A, 'expires_at' => null]);
        $newer = $this->mkSession(['user_id' => self::USER_A, 'expires_at' => null]);
        // A stray unowned cookie session must be ignored in favor of the account.
        $cookie = $this->mkSession();

        $resolved = $this->svc()->resolve($this->req($cookie->guest_token), self::USER_A);

        $this->assertSame($newer->id, $resolved->id, 'latest owned live session wins');
        $this->assertSame(self::USER_A, (int) $resolved->user_id);
    }

    public function test_owned_live_session_excludes_published_and_expired(): void
    {
        $this->mkSession(['user_id' => self::USER_A, 'status' => 'published', 'expires_at' => null]);
        $this->mkSession(['user_id' => self::USER_A, 'expires_at' => now()->subHour()]); // expired
        $live = $this->mkSession(['user_id' => self::USER_A, 'expires_at' => null]);

        $found = $this->svc()->ownedLiveSession(self::USER_A);

        $this->assertNotNull($found);
        $this->assertSame($live->id, $found->id);
        $this->assertNull($this->svc()->ownedLiveSession(self::USER_B));
    }

    public function test_unowned_cookie_session_gets_claimed(): void
    {
        $guest = $this->mkSession(); // user_id null, expiry set

        $resolved = $this->svc()->resolve($this->req($guest->guest_token), self::USER_A);

        $this->assertSame($guest->id, $resolved->id, 'the guest session is kept, not replaced');
        $this->assertSame(self::USER_A, (int) $resolved->user_id, 'user_id stamped');
        $this->assertNull($resolved->expires_at, 'owned sessions do not purge');

        $fresh = StudioSession::find($guest->id);
        $this->assertSame(self::USER_A, (int) $fresh->user_id);
        $this->assertNull($fresh->expires_at);
    }

    public function test_user_b_session_is_never_returned_to_user_a(): void
    {
        // User B owns a session; its guest_token sits in user A's cookie.
        $bSession = $this->mkSession(['user_id' => self::USER_B, 'expires_at' => null]);

        $resolved = $this->svc()->resolve($this->req($bSession->guest_token), self::USER_A);

        $this->assertNotSame($bSession->id, $resolved->id, 'must not hand over B\'s session');
        $this->assertSame(self::USER_A, (int) $resolved->user_id, 'A gets a fresh, stamped session');

        // B's session is untouched.
        $b = StudioSession::find($bSession->id);
        $this->assertSame(self::USER_B, (int) $b->user_id);
    }

    public function test_authed_with_no_owned_and_no_cookie_mints_stamped_session(): void
    {
        $before = StudioSession::count();

        $resolved = $this->svc()->resolve($this->req(), self::USER_A);

        $this->assertSame($before + 1, StudioSession::count());
        $this->assertSame(self::USER_A, (int) $resolved->user_id);
        $this->assertNull($resolved->expires_at, 'a fresh owned session does not purge');
        $this->assertSame('exploring', $resolved->status);
    }

    // ---- resolve(): guest (unchanged) --------------------------------------

    public function test_guest_with_cookie_returns_same_session_unowned(): void
    {
        $guest = $this->mkSession();

        $resolved = $this->svc()->resolve($this->req($guest->guest_token), null);

        $this->assertSame($guest->id, $resolved->id);
        $this->assertNull($resolved->user_id, 'guest resolve never claims');
    }

    public function test_guest_without_cookie_mints_fresh_guest_session(): void
    {
        $resolved = $this->svc()->resolve($this->req(), null);

        $this->assertNull($resolved->user_id);
        $this->assertNotNull($resolved->expires_at, 'guest sessions keep the TTL');
        $this->assertTrue($resolved->expires_at->isFuture());
    }

    public function test_guest_with_expired_cookie_mints_fresh(): void
    {
        $expired = $this->mkSession(['expires_at' => now()->subHour()]);

        $resolved = $this->svc()->resolve($this->req($expired->guest_token), null);

        $this->assertNotSame($expired->id, $resolved->id);
        $this->assertNull($resolved->user_id);
    }

    // ---- claimForUser(): idempotent, never reassigns -----------------------

    public function test_claim_is_idempotent_and_never_reassigns(): void
    {
        $svc = $this->svc();
        $s = $this->mkSession();

        $svc->claimForUser($s, self::USER_A);
        $this->assertSame(self::USER_A, (int) $s->fresh()->user_id);
        $this->assertNull($s->fresh()->expires_at);

        // Re-claiming by the same owner is a no-op.
        $svc->claimForUser($s, self::USER_A);
        $this->assertSame(self::USER_A, (int) $s->fresh()->user_id);

        // Claiming a session owned by A on behalf of B never reassigns it.
        $svc->claimForUser($s, self::USER_B);
        $this->assertSame(self::USER_A, (int) $s->fresh()->user_id);
    }

    // ---- resumeByToken(): claims only unowned ------------------------------

    public function test_resume_by_token_claims_only_unowned(): void
    {
        $svc = $this->svc();

        // Unowned + authed -> claimed.
        $unowned = $this->mkSession();
        $out = $svc->resumeByToken($unowned->guest_token, self::USER_A);
        $this->assertNotNull($out);
        $this->assertSame($unowned->id, $out->id);
        $this->assertSame(self::USER_A, (int) $out->fresh()->user_id);

        // Owned by B + a different user resuming -> read-as-is, NOT reassigned.
        $owned = $this->mkSession(['user_id' => self::USER_B, 'expires_at' => null]);
        $out2 = $svc->resumeByToken($owned->guest_token, self::USER_A);
        $this->assertNotNull($out2);
        $this->assertSame($owned->id, $out2->id);
        $this->assertSame(self::USER_B, (int) $out2->fresh()->user_id, 'never reassigned');
    }

    public function test_resume_by_token_as_guest_does_not_claim(): void
    {
        $unowned = $this->mkSession();

        $out = $this->svc()->resumeByToken($unowned->guest_token, null);

        $this->assertNotNull($out);
        $this->assertNull($out->fresh()->user_id);
    }

    public function test_resume_by_token_null_missing_or_expired_returns_null(): void
    {
        $svc = $this->svc();

        $this->assertNull($svc->resumeByToken(null, self::USER_A));
        $this->assertNull($svc->resumeByToken('', self::USER_A));
        $this->assertNull($svc->resumeByToken('does-not-exist', self::USER_A));

        $expired = $this->mkSession(['expires_at' => now()->subHour()]);
        $this->assertNull($svc->resumeByToken($expired->guest_token, self::USER_A));
    }
}
