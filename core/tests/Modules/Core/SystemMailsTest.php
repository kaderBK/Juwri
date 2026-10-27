<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Mail\TermsChangedMail;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-23 part 3: the Terms-changed notice (Terms 14.2) renders in EN and AR (RTL) with the right subject and is sent
 * by its hook (`legal:notify-terms-change`) to active account owners, once per effective date, in their own language.
 * Also guards the system-mail lang files: same keys and :placeholders in all 11 locales, nothing left in English.
 */
class SystemMailsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
    }

    public function test_terms_changed_renders_in_english_with_subject_and_date(): void
    {
        $mail = (new TermsChangedMail('Sam', Carbon::parse('2026-12-01'), 'https://example.test/terms'))->locale('en');
        $html = $mail->render();

        $this->assertSame("We're updating the juwri Terms on 1 December 2026", $mail->subject);
        $this->assertStringContainsString('Hi Sam,', $html);
        $this->assertStringContainsString('1 December 2026', $html);
        $this->assertStringContainsString('at least 30 days', $html);
        $this->assertStringContainsString('https://example.test/terms', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
    }

    public function test_terms_changed_renders_in_arabic_rtl(): void
    {
        $mail = (new TermsChangedMail('سامي', Carbon::parse('2026-12-01'), 'https://example.test/terms'))->locale('ar');
        $html = $mail->render();

        $this->assertStringStartsWith('سنحدّث شروط juwri في ', $mail->subject);
        $this->assertStringContainsString('مرحباً سامي،', $html);
        $this->assertStringContainsString('30 يوماً', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringNotContainsString('We are updating', $html);
    }

    public function test_the_command_notifies_active_owners_once_per_effective_date_in_their_language(): void
    {
        // JUWRI LTD's own install (LEGAL_PAGES); a fixed day so both dates stay 30+ days ahead (Terms 14.2).
        config(['legal.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00', 'UTC'));
        Mail::fake();
        $en = $this->owner(['email' => 'a@example.test', 'preferred_locale' => 'en']);
        $ar = $this->owner(['email' => 'b@example.test', 'preferred_locale' => 'ar']);
        $this->owner(['email' => 'off@example.test', 'status' => 0]);
        User::factory()->create(['email' => 'admin@example.test', 'status' => 1, 'user_type' => 'admin']);

        $this->artisan('legal:notify-terms-change', ['effective' => '2026-12-01', '--url' => 'https://example.test/terms'])
            ->expectsOutputToContain('Sent 2 notice(s)')->assertExitCode(0);

        Mail::assertQueued(TermsChangedMail::class, 2);
        Mail::assertQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('b@example.test') && $m->locale === 'ar');
        Mail::assertQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('a@example.test') && $m->locale === 'en');
        Mail::assertNotQueued(TermsChangedMail::class, fn ($m) => $m->hasTo('off@example.test') || $m->hasTo('admin@example.test'));
        $this->assertSame('2026-12-01', (string) UserOption::getUserOption($en->id, 'terms_change_notice'));

        // A re-run reaches nobody twice; a new owner and a new date do get it.
        $this->artisan('legal:notify-terms-change', ['effective' => '2026-12-01'])->expectsOutputToContain('Sent 0 notice(s)')->assertExitCode(0);
        $this->owner(['email' => 'c@example.test']);
        $this->artisan('legal:notify-terms-change', ['effective' => '2026-12-01'])->expectsOutputToContain('Sent 1 notice(s)')->assertExitCode(0);
        $this->artisan('legal:notify-terms-change', ['effective' => '2027-01-15'])->expectsOutputToContain('Sent 3 notice(s)')->assertExitCode(0);
        $this->assertNotNull($ar->fresh());
    }

    public function test_dry_run_sends_nothing_and_a_bad_date_is_refused(): void
    {
        // JUWRI LTD's own install (LEGAL_PAGES); a fixed day so both dates stay 30+ days ahead (Terms 14.2).
        config(['legal.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00', 'UTC'));
        Mail::fake();
        $owner = $this->owner(['email' => 'a@example.test']);

        $this->artisan('legal:notify-terms-change', ['effective' => '2026-12-01', '--dry-run' => true])
            ->expectsOutputToContain('Would send 1')->assertExitCode(0);
        Mail::assertNothingQueued();
        $this->assertEmpty(UserOption::getUserOption($owner->id, 'terms_change_notice'));

        $this->artisan('legal:notify-terms-change', ['effective' => '01/12/2026'])->assertExitCode(2);
        Mail::assertNothingQueued();
    }

    /** @return array<string, array{0: string}> */
    public static function locales(): array
    {
        return array_combine(
            ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'],
            array_map(fn ($l) => [$l], ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'])
        );
    }

    #[DataProvider('locales')]
    public function test_system_mail_lang_files_are_aligned_in_every_locale(string $locale): void
    {
        foreach (['sysmail', 'site'] as $file) {
            $en = require base_path("lang/en/{$file}.php");
            $tr = require base_path("lang/{$locale}/{$file}.php");

            $this->assertSame(array_keys($en), array_keys($tr), "{$locale}/{$file}.php keys differ from en");
            foreach ($en as $key => $english) {
                $this->assertSame($this->placeholders($english), $this->placeholders($tr[$key]), "{$locale}/{$file}.php {$key}: placeholders differ");
                $this->assertNotSame('', trim($tr[$key]));
                // site.php also holds the website copy, where some lines are correctly the same as English (es
                // "Legal", fr "Contact"); Feature/Legal/SiteLangTest checks those with its own allowlist. Here only
                // the mail.* lines of site.php are system-mail text.
                $mailLine = $file === 'sysmail' || str_starts_with($key, 'mail.');
                if ($locale !== 'en' && $mailLine && ! str_ends_with($key, 'greeting')) {
                    $this->assertNotSame($english, $tr[$key], "{$locale}/{$file}.php {$key} is still English");
                }
            }
        }
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/', $text, $m);
        sort($m[0]);

        return $m[0];
    }
}
