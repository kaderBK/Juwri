<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\TermsChangeNotice;
use Juwri\Core\Mail\TermsChangedMail;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Tests\TestCase;

/**
 * P1-23: a new Terms version published in config/legal.php with an effective date is announced automatically (daily
 * `legal:notify-terms-change`): every active account owner once per version, in their language, queued; a material
 * change with less than 30 days' notice is refused and flagged (Terms 14.2) unless marked non-material; only on
 * JUWRI LTD's own install (LEGAL_PAGES), never to a reseller's customers.
 */
class TermsChangeNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legal.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00', 'UTC'));
        Mail::fake();
    }

    private function owner(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
    }

    /** @param  list<string>  $nonMaterial */
    private function publish(string $version, string $effective, array $nonMaterial = []): void
    {
        config([
            'legal.documents.terms.versions' => ['1.0' => '2026-10-04', $version => $effective],
            'legal.documents.terms.non_material' => $nonMaterial,
        ]);
    }

    public function test_a_published_version_is_announced_once_to_every_active_owner_in_their_language(): void
    {
        $en = $this->owner(['email' => 'a@example.test', 'preferred_locale' => 'en']);
        $this->owner(['email' => 'b@example.test', 'preferred_locale' => 'ar']);
        $this->owner(['email' => 'off@example.test', 'status' => 0]);
        $this->owner(['email' => 'resold@example.test', 'reseller_id' => 77]);
        User::factory()->create(['email' => 'admin@example.test', 'status' => 1, 'user_type' => 'admin']);

        // Nothing announced while the newest version is the one in force.
        config(['legal.documents.terms.versions' => ['1.0' => '2026-10-04']]);
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('No announced Terms version')->assertExitCode(0);
        Mail::assertNothingQueued();

        $this->publish('1.1', '2026-11-20');
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('Sent 2 notice(s) for 2026-11-20 (Terms 1.1)')->assertExitCode(0);

        Mail::assertQueued(TermsChangedMail::class, 2);
        Mail::assertQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('a@example.test') && $m->locale === 'en'
            && $m->effectiveOn->format('Y-m-d') === '2026-11-20' && $m->termsUrl === LegalDocuments::url('terms'));
        Mail::assertQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('b@example.test') && $m->locale === 'ar');
        Mail::assertNotQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('off@example.test') || $m->hasTo('resold@example.test') || $m->hasTo('admin@example.test'));
        $this->assertSame('2026-11-20', (string) UserOption::getUserOption($en->id, TermsChangeNotice::STAMP));

        // The next daily runs reach nobody twice, but an owner who joined since does get it — even under 30 days.
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('Sent 0 notice(s)')->assertExitCode(0);
        $this->travelTo(Carbon::parse('2026-11-05 09:15:00', 'UTC'));
        $this->owner(['email' => 'c@example.test']);
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('Sent 1 notice(s)')->assertExitCode(0);
        Mail::assertQueued(TermsChangedMail::class, 3);

        // Once in force there is nothing left to announce.
        $this->travelTo(Carbon::parse('2026-11-21 09:15:00', 'UTC'));
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('No announced Terms version')->assertExitCode(0);
    }

    public function test_a_material_change_with_less_than_30_days_notice_is_refused_and_flagged(): void
    {
        $owner = $this->owner(['email' => 'a@example.test']);
        $this->publish('1.1', '2026-10-20');
        Log::spy();

        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('a material change needs at least 30 days')->assertExitCode(1);
        Mail::assertNothingQueued();
        $this->assertEmpty(UserOption::getUserOption($owner->id, TermsChangeNotice::STAMP));
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context = []) => $message === 'legal.terms_change_refused' && $context['version'] === '1.1');

        // The release gate says so too.
        Artisan::call('legal:check');
        $this->assertStringContainsString('terms 1.1: a material change needs at least 30 days', Artisan::output());

        // 30 days exactly is enough.
        $this->publish('1.1', '2026-11-03');
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('Sent 1 notice(s)')->assertExitCode(0);
    }

    public function test_a_non_material_change_may_take_effect_sooner(): void
    {
        $this->owner(['email' => 'a@example.test']);
        $this->publish('1.1', '2026-10-10', ['1.1']);

        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('Sent 1 notice(s) for 2026-10-10')->assertExitCode(0);
        Mail::assertQueued(TermsChangedMail::class, 1);
        $this->assertFalse(LegalDocuments::material('terms', '1.1'));
        $this->assertTrue(LegalDocuments::material('terms', '1.2'));
    }

    public function test_by_hand_a_short_date_needs_the_non_material_flag(): void
    {
        $this->owner(['email' => 'a@example.test']);

        $this->artisan('legal:notify-terms-change', ['effective' => '2026-10-15'])->expectsOutputToContain('Not announced')->assertExitCode(1);
        $this->artisan('legal:notify-terms-change', ['effective' => '2026-09-01', '--non-material' => true])->expectsOutputToContain('has passed')->assertExitCode(1);
        Mail::assertNothingQueued();

        $this->artisan('legal:notify-terms-change', ['effective' => '2026-10-15', '--non-material' => true])->expectsOutputToContain('Sent 1 notice(s)')->assertExitCode(0);
        Mail::assertQueued(TermsChangedMail::class, 1);
    }

    public function test_nothing_is_sent_without_legal_pages(): void
    {
        config(['legal.enabled' => false]);
        $this->owner(['email' => 'a@example.test']);
        $this->publish('1.1', '2026-12-01');

        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('LEGAL_PAGES is off')->assertExitCode(0);
        $this->artisan('legal:notify-terms-change', ['effective' => '2026-12-01'])->assertExitCode(1);
        Mail::assertNothingQueued();
        $this->assertSame(['status' => 'off', 'sent' => 0], TermsChangeNotice::run());
    }

    public function test_the_announcement_runs_daily_only_where_the_legal_pages_are_published(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $e) => str_contains((string) $e->command, 'legal:notify-terms-change'),
        ));
        $this->assertCount(1, $events);
        $this->assertSame('15 9 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->filtersPass($this->app));

        config(['legal.enabled' => false]);
        $this->assertFalse($events[0]->filtersPass($this->app));
    }

    public function test_the_shipped_terms_1_1_is_not_announced_until_its_date_is_set(): void
    {
        // J51e: 1.1 is in the shipped config with no date (LEGAL_TERMS_1_1_EFFECTIVE unset): 1.0 stays in force, nothing is sent.
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'UTC'));
        $this->assertNull(config('legal.documents.terms.versions')['1.1']);
        $this->assertSame('1.0', LegalDocuments::currentVersion('terms'));
        $this->assertSame('1.1', LegalDocuments::latestVersion('terms'));
        $this->assertNull(TermsChangeNotice::announced());
        $this->owner(['email' => 'a@example.test']);
        $this->artisan('legal:notify-terms-change')->expectsOutputToContain('No announced Terms version')->assertExitCode(0);
        Mail::assertNothingQueued();

        // Dated 31 days ahead: a material change with enough notice, so the notice may go out.
        config(['legal.documents.terms.versions' => ['1.0' => '2026-10-04', '1.1' => now()->addDays(31)->format('Y-m-d')]]);
        $announced = TermsChangeNotice::announced();
        $this->assertNotNull($announced);
        $this->assertSame('1.1', $announced['version']);
        $this->assertTrue($announced['material']);
        $this->assertNull(TermsChangeNotice::problem($announced['effective'], true));
        $this->assertSame('1.0', LegalDocuments::currentVersion('terms'));
    }
}
