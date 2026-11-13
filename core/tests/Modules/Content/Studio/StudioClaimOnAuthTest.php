<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use Juwri\Modules\Content\Listeners\ClaimStudioSession;
use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W2-LISTENER — ClaimStudioSession auto-claims an UNOWNED guest Studio session
 * onto the account when a visitor authenticates, on BOTH Registered and Login,
 * and carries the session's credit ledger over with it (SPEC §5.4 / §7).
 *
 * Because Login fires on every request under remember-me the handler must be
 * cheap + idempotent: an already-owned session, or no `studio_guest` cookie, is
 * a clean no-op that never reassigns ownership.
 *
 * studio_sessions.user_id + studio_credit_ledger.user_id carry no FK, so the
 * tests use bare int user ids and a minimal Authenticatable.
 */
class StudioClaimOnAuthTest extends TestCase
{
    use RefreshDatabase;

    private const USER_A = 501;

    private const USER_B = 502;

    /** A minimal Authenticatable carrying just the id the listener reads. */
    private function user(int $id): Authenticatable
    {
        return new class($id) implements Authenticatable
        {
            public function __construct(private int $id) {}

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return $this->id;
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function setRememberToken($value) {}

            public function getRememberTokenName()
            {
                return 'remember_token';
            }
        };
    }

    /**
     * Bind the request the listener reads via request(), carrying the
     * `studio_guest` cookie (or none). Reliably puts the cookie on request()
     * without depending on a full auth-flow round trip.
     */
    private function bindRequest(?string $token): void
    {
        $this->app->instance('request', Request::create(
            '/register',
            'POST',
            [],
            $token !== null ? ['studio_guest' => $token] : []
        ));
    }

    private function guestSession(): StudioSession
    {
        return StudioSession::create([
            'guest_token' => Str::random(48),
            'status' => 'exploring',
            'expires_at' => now()->addHours(48),
        ]);
    }

    /** Seed ledger rows on a (guest) session through the real service. */
    private function seedCredits(StudioSession $session): void
    {
        $svc = app(StudioCreditService::class);
        $svc->credit($session, 30, 'topup', 'ref-a');
        $svc->credit($session, 120, 'topup', 'ref-b');
    }

    /** The listener is actually wired to BOTH events (registration check). */
    public function test_listener_is_registered_for_registered_and_login(): void
    {
        foreach ([Registered::class, Login::class] as $event) {
            $this->assertContains(
                ClaimStudioSession::class,
                $this->listenerClasses($event),
                "ClaimStudioSession must handle {$event}"
            );
        }
    }

    /** Concrete listener class names bound to an event via EventServiceProvider. */
    private function listenerClasses(string $event): array
    {
        $classes = [];
        foreach (Event::getRawListeners()[$event] ?? [] as $listener) {
            if (is_string($listener)) {
                $classes[] = explode('@', $listener)[0];
            }
        }

        return $classes;
    }

    // (a) Registered + guest cookie → session claimed + credits transferred.
    public function test_registered_with_guest_cookie_claims_and_transfers_credits(): void
    {
        $session = $this->guestSession();
        $this->seedCredits($session);
        $this->bindRequest($session->guest_token);

        event(new Registered($this->user(self::USER_A)));

        $fresh = StudioSession::find($session->id);
        $this->assertSame(self::USER_A, (int) $fresh->user_id, 'session claimed onto the account');
        $this->assertNull($fresh->expires_at, 'owned sessions do not purge');

        $this->assertSame(
            0,
            StudioCreditLedger::where('studio_session_id', $session->id)->whereNull('user_id')->count(),
            'no null-user ledger rows remain on the session'
        );
        $this->assertSame(2, StudioCreditLedger::where('user_id', self::USER_A)->count(), 'both rows moved to the user');
        $this->assertSame(
            150,
            app(StudioCreditService::class)->balance($fresh),
            'owner balance sums the transferred rows'
        );
    }

    // (b) Login + guest cookie → same outcome as Registered.
    public function test_login_with_guest_cookie_claims_and_transfers_credits(): void
    {
        $session = $this->guestSession();
        $this->seedCredits($session);
        $this->bindRequest($session->guest_token);

        event(new Login('web', $this->user(self::USER_A), false));

        $fresh = StudioSession::find($session->id);
        $this->assertSame(self::USER_A, (int) $fresh->user_id);
        $this->assertNull($fresh->expires_at);
        $this->assertSame(2, StudioCreditLedger::where('user_id', self::USER_A)->count());
        $this->assertSame(150, app(StudioCreditService::class)->balance($fresh));
    }

    // (c) Login when the cookie session is already owned → idempotent no-op.
    public function test_login_when_session_already_owned_is_a_noop(): void
    {
        $session = $this->guestSession();
        $session->update(['user_id' => self::USER_B, 'expires_at' => null]);
        StudioCreditLedger::create([
            'studio_session_id' => $session->id,
            'user_id' => self::USER_B,
            'delta' => 40,
            'reason' => 'topup',
            'created_at' => now(),
        ]);
        $this->bindRequest($session->guest_token);

        // USER_A "logs in" while USER_B's cookie is present — remember-me replays
        // this on every request; ownership must never be reassigned.
        event(new Login('web', $this->user(self::USER_A), true));

        $fresh = StudioSession::find($session->id);
        $this->assertSame(self::USER_B, (int) $fresh->user_id, 'ownership never reassigned');
        $this->assertSame(
            0,
            StudioCreditLedger::where('user_id', self::USER_A)->count(),
            'no rows moved to the other user'
        );
        $this->assertSame(1, StudioCreditLedger::where('user_id', self::USER_B)->count(), 'owner rows untouched');
    }

    // (d) No `studio_guest` cookie → no-op (the common remember-me path).
    public function test_login_without_cookie_is_a_noop(): void
    {
        $session = $this->guestSession(); // unowned; no cookie on the request
        $this->bindRequest(null);

        event(new Login('web', $this->user(self::USER_A), false));

        $fresh = StudioSession::find($session->id);
        $this->assertNull($fresh->user_id, 'no cookie → nothing claimed');
        $this->assertSame(0, StudioSession::whereNotNull('user_id')->count(), 'no session owned for the user');
    }
}
