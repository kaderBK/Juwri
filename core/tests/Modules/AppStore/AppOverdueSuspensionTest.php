<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use App\Models\AppInstallation;
use App\Services\AppInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Mail\AppDunningMail;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\TestCase;

/**
 * P1-23 part 7 — Terms 6.4 for paid app installs. juwri bills them (the owner's platform wallet is debited; the
 * developer is credited a revenue share), so a failed renewal follows the same rule as plans and resellers: the app keeps
 * working, written notice when the renewal is 14 days overdue, pause only 7+ days after it (OverduePolicy).
 */
class AppOverdueSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: AppInstallation, 1: Carbon} the install, due (unpaid) at the returned date */
    private function dueInstall(string $locale = 'en'): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'preferred_locale' => $locale]);
        WalletService::credit($owner, 10, 'test seed'); // covers the install only
        $app = App::create([
            'name' => 'Paid App', 'slug' => 'paid-app-'.uniqid(), 'scopes' => ['menu:read'], 'status' => 'approved',
            'billing_type' => 'recurring', 'price' => 10.00, 'billing_period_days' => 30,
        ]);
        $installation = (new AppInstallService)->install($app, $owner)->fresh();
        $due = Carbon::parse('2026-11-01 02:30:00');
        $installation->update(['current_period_ends_at' => $due]);

        return [$installation, $due];
    }

    private function renewAt(Carbon $at): array
    {
        Carbon::setTestNow($at);

        return app(AppBillingService::class)->renewDue();
    }

    public function test_day_by_day_ladder_failed_mail_notice_at_14_pause_at_21(): void
    {
        [$inst, $due] = $this->dueInstall();

        // Day 0: past_due, access kept, one "renewal failed" mail (no pause date promised).
        $this->renewAt($due->copy()->addMinutes(5));
        $this->assertSame('past_due', $inst->fresh()->status);
        $this->assertTrue($inst->fresh()->grace_period_ends_at->isSameDay($due->copy()->addDays(21)));
        Mail::assertQueued(AppDunningMail::class, 1);
        Mail::assertQueued(AppDunningMail::class, fn ($m) => $m->subjectLine === 'Paid App renewal failed');

        // Days 1–13: retried daily, nothing new.
        foreach (range(1, 13) as $day) {
            $this->assertSame(0, $this->renewAt($due->copy()->addDays($day)->addMinutes(5))['suspended']);
        }
        Mail::assertQueued(AppDunningMail::class, 1);
        $this->assertNull($inst->fresh()->suspension_notice_at);

        // Day 14: the written notice (pause date = day 21, the amount), once.
        $this->renewAt($due->copy()->addDays(14)->addMinutes(5));
        Mail::assertQueued(AppDunningMail::class, 2);
        Mail::assertQueued(AppDunningMail::class, function ($m) {
            $body = implode(' ', $m->lines);

            return $m->subjectLine === 'Paid App will be paused on 22 November 2026'
                && str_contains($body, '14 days overdue') && str_contains($body, '22 November 2026') && str_contains($body, '7 days from now');
        });
        $this->assertNotNull($inst->fresh()->suspension_notice_at);

        // Days 15–20: still working; no second notice.
        foreach (range(15, 20) as $day) {
            $this->assertSame(0, $this->renewAt($due->copy()->addDays($day)->addMinutes(5))['suspended']);
        }
        $this->assertSame('past_due', $inst->fresh()->status);
        Mail::assertQueued(AppDunningMail::class, 2);

        // Day 21 (= notice + 7): paused.
        $this->assertSame(1, $this->renewAt($due->copy()->addDays(21)->addMinutes(10))['suspended']);
        $this->assertSame('suspended', $inst->fresh()->status);
    }

    public function test_paying_before_the_pause_clears_the_ladder_and_the_next_period_gets_its_own_notice(): void
    {
        [$inst, $due] = $this->dueInstall();
        $this->renewAt($due->copy()->addMinutes(5));
        $this->renewAt($due->copy()->addDays(14)->addMinutes(5));
        $this->assertNotNull($inst->fresh()->suspension_notice_at);

        WalletService::credit(User::find($inst->user_id), 50, 'top up');
        $this->renewAt($due->copy()->addDays(16));
        $fresh = $inst->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->suspension_notice_at);
        $this->assertNull($fresh->grace_period_ends_at);
    }

    public function test_a_row_on_the_old_three_day_grace_is_not_paused_without_notice(): void
    {
        [$inst, $due] = $this->dueInstall();
        // Deploy: past_due under the old rule, its 3-day grace long over, no notice.
        $inst->update(['status' => 'past_due', 'grace_period_ends_at' => $due->copy()->addDays(3)]);

        $this->assertSame(0, $this->renewAt($due->copy()->addDays(10))['suspended']);
        $this->assertSame('past_due', $inst->fresh()->status);
        $this->assertTrue($inst->fresh()->grace_period_ends_at->isSameDay($due->copy()->addDays(21)), 'moved to the rule');

        // 16 days overdue: notice now, pause 7 days later (not at day 21).
        $this->renewAt($due->copy()->addDays(16));
        $this->assertTrue($inst->fresh()->grace_period_ends_at->isSameDay($due->copy()->addDays(23)));
        $this->assertSame(0, $this->renewAt($due->copy()->addDays(22))['suspended']);
        $this->assertSame(1, $this->renewAt($due->copy()->addDays(23)->addHour())['suspended']);
    }

    public function test_no_notice_no_pause_when_the_owner_has_no_address(): void
    {
        [$inst, $due] = $this->dueInstall();
        User::whereKey($inst->user_id)->update(['email' => '']);

        foreach ([0, 14, 21, 30, 60] as $day) {
            $this->assertSame(0, $this->renewAt($due->copy()->addDays($day)->addMinutes(5))['suspended']);
        }
        $this->assertSame('past_due', $inst->fresh()->status);
        $this->assertNull($inst->fresh()->suspension_notice_at);
    }

    public function test_the_notice_is_in_the_owners_language(): void
    {
        [$inst, $due] = $this->dueInstall('ar');
        $this->renewAt($due->copy()->addMinutes(5));
        $this->renewAt($due->copy()->addDays(14)->addMinutes(5));

        Mail::assertQueued(AppDunningMail::class, fn ($m) => (bool) preg_match('/\p{Arabic}/u', $m->subjectLine) && $m->locale === 'ar');
    }
}
