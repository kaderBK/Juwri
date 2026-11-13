<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
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

    public function test_cannot_refer_self(): void
    {
        $s = $this->mkSession();
        $resp = $this->apply($s, $s->ensureShareToken());
        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, app(StudioCreditService::class)->balance($s));
    }
}
