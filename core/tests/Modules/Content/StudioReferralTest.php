<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Tests\TestCase;

/**
 * SI9 (#62): referral credits — both sides credited, once per referee, no self.
 */
class StudioReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['studio.referral_bonus' => 5]);
    }

    private function mkSession(): StudioSession
    {
        return StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
    }

    private function apply(StudioSession $referee, string $code)
    {
        $req = Request::create('/studio/referral', 'POST', ['code' => $code]);
        $req->cookies->set('studio_guest', $referee->guest_token);

        return new StudioController()->applyReferral($req);
    }

    public function test_referral_credits_both_sides_once(): void
    {
        $referrer = $this->mkSession();
        $referee = $this->mkSession();
        $credits = app(StudioCreditService::class);

        $resp = $this->apply($referee, $referrer->ensureShareToken());
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(5, $credits->balance($referee));
        $this->assertSame(5, $credits->balance($referrer));

        // Second redemption blocked — no double.
        $resp2 = $this->apply($referee, $referrer->ensureShareToken());
        $this->assertSame(422, $resp2->getStatusCode());
        $this->assertSame(5, $credits->balance($referee));
    }

    public function test_referral_rejects_guest_credential_token(): void
    {
        // Referral codes are the PUBLIC share token — the guest credential
        // must not be redeemable (it should never circulate).
        $referrer = $this->mkSession();
        $referee = $this->mkSession();
        $resp = $this->apply($referee, $referrer->guest_token);
        $this->assertSame(422, $resp->getStatusCode());
    }

    /** w5 #8: a cookie-less unauthenticated POST minted a fresh referee each time. */
    public function test_referral_without_a_session_cookie_mints_nothing(): void
    {
        $referrer = $this->mkSession();
        $code = $referrer->ensureShareToken();
        $sessions = StudioSession::count();

        for ($i = 0; $i < 3; $i++) {
            $resp = new StudioController()->applyReferral(Request::create('/studio/referral', 'POST', ['code' => $code]));
            $this->assertSame(422, $resp->getStatusCode());
        }

        $this->assertSame(0, app(StudioCreditService::class)->balance($referrer));
        $this->assertSame($sessions, StudioSession::count(), 'no guest session minted by the referral call');
    }

    /** w5 #8: the referrer's referral earnings are capped. */
    public function test_referrer_earnings_are_capped(): void
    {
        config(['studio.referral_max_per_referrer' => 2]);
        $referrer = $this->mkSession();
        $code = $referrer->ensureShareToken();

        $this->assertSame(200, $this->apply($this->mkSession(), $code)->getStatusCode());
        $this->assertSame(200, $this->apply($this->mkSession(), $code)->getStatusCode());
        $third = $this->mkSession();
        $this->assertSame(422, $this->apply($third, $code)->getStatusCode());

        $credits = app(StudioCreditService::class);
        $this->assertSame(10, $credits->balance($referrer));
        $this->assertSame(0, $credits->balance($third));
    }

    /** w5 #8: an owner cannot redeem again from a second session of the same account. */
    public function test_owner_cannot_redeem_again_from_another_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $referrer = $this->mkSession();
        $code = $referrer->ensureShareToken();

        $first = StudioSession::create(['guest_token' => Str::random(48), 'user_id' => $user->id, 'status' => 'exploring']);
        $this->assertSame(200, $this->apply($first, $code)->getStatusCode());
        $first->update(['status' => 'published']);
        $second = StudioSession::create(['guest_token' => Str::random(48), 'user_id' => $user->id, 'status' => 'exploring']);

        $this->assertSame(422, $this->apply($second, $code)->getStatusCode());
        $this->assertSame(5, app(StudioCreditService::class)->balance($referrer));
    }

    /** Opus review NIT 4: a user cannot refer themselves from a second session of their own account. */
    public function test_owner_cannot_refer_themselves_from_another_own_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $own = StudioSession::create(['guest_token' => Str::random(48), 'user_id' => $user->id, 'status' => 'published']);
        $code = $own->ensureShareToken();
        $second = StudioSession::create(['guest_token' => Str::random(48), 'user_id' => $user->id, 'status' => 'exploring']);

        $this->assertSame(422, $this->apply($second, $code)->getStatusCode());
        $credits = app(StudioCreditService::class);
        $this->assertSame(0, $credits->balance($own));
        $this->assertSame(0, $credits->balance($second));
    }

    public function test_cannot_refer_self(): void
    {
        $s = $this->mkSession();
        $resp = $this->apply($s, $s->ensureShareToken());
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, app(StudioCreditService::class)->balance($s));
    }
}
