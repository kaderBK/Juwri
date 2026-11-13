<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\UserSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-47: after sign-up an owner is prompted to turn on 2FA or add a passkey, until they do or
 * dismiss it; the onboarding checklist gets a "Secure your account" step that ticks on either.
 */
class OwnerSecurityNudgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_new_owner_sees_the_prompt_and_an_open_checklist_step(): void
    {
        $owner = $this->makeOwner();

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="security-nudge"', $html);
        $this->assertStringContainsString(route('2fa.show'), $html);
        $this->assertStringContainsString(route('passkeys.add'), $html);
        $this->assertStringContainsString(route('security-nudge.dismiss'), $html);
        // Checklist step present and not done (its "Security settings" CTA only renders while open).
        $this->assertStringContainsString('Secure your account', $html);
        $this->assertStringContainsString('Security settings', $html);
    }

    public function test_dismissing_hides_the_prompt_and_is_stored_per_user(): void
    {
        $owner = $this->makeOwner();
        $other = $this->makeOwner();

        $this->actingAs($owner)->from(route('dashboard'))
            ->post(route('security-nudge.dismiss'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame('1', UserSettings::legacy((int) $owner->id, 'security_nudge_dismissed'));
        $this->assertTrue((bool) (json_decode((string) DB::table('users')->where('id', $owner->id)->value('preferences'), true)['security_nudge_dismissed'] ?? false));
        $this->assertNull(UserSettings::legacy((int) $other->id, 'security_nudge_dismissed'));

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('id="security-nudge"', false);
        // The checklist step stays (still not secured); only the prompt goes away.
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Security settings');
        $this->actingAs($other)->get(route('dashboard'))->assertSee('id="security-nudge"', false);
    }

    public function test_dismiss_answers_json_for_xhr(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->postJson(route('security-nudge.dismiss'))->assertOk()->assertJson(['ok' => true]);
    }

    public function test_dismiss_requires_login(): void
    {
        $this->post(route('security-nudge.dismiss'))->assertRedirect();
    }

    public function test_two_factor_completes_the_step_and_hides_the_prompt(): void
    {
        $owner = $this->makeOwner();
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertTrue($owner->fresh()->hasSecondFactor());
        $this->assertStringNotContainsString('id="security-nudge"', $html);
        $this->assertStringNotContainsString('Security settings', $html); // step done → no CTA
    }

    public function test_unconfirmed_two_factor_does_not_count(): void
    {
        $owner = $this->makeOwner();
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => null])->save();

        $this->assertFalse($owner->fresh()->hasSecondFactor());
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('id="security-nudge"', false);
    }

    public function test_a_passkey_completes_the_step_and_hides_the_prompt(): void
    {
        $owner = $this->makeOwner();
        DB::table('passkeys')->insert([
            'user_id' => $owner->id, 'name' => 'Laptop', 'credential_id' => Str::random(32),
            'rp_id' => 'localhost', 'public_key' => 'x', 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($owner->fresh()->hasSecondFactor());
        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="security-nudge"', $html);
        $this->assertStringNotContainsString('Security settings', $html);
    }

    public function test_strings_are_translated_in_every_locale(): void
    {
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            foreach (['Secure your account', 'Set up two-factor sign-in', 'Security settings', 'Turn on two-factor sign-in or add a passkey.'] as $text) {
                $slug = Str::slug($text, '_');
                $this->assertTrue(\Lang::has("lang.{$slug}", $locale, false), "{$locale} missing {$slug}");
                $this->assertNotSame($text, trans("lang.{$slug}", [], $locale), "{$locale} left in English: {$slug}");
            }
        }
    }
}
