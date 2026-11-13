<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Found by the P1-12 Arabic journey: owner Settings listed English country and language names in an Arabic dashboard
 * (the tables store the English name only). The pickers now use PHP intl (CLDR) names in the reader's language.
 */
class OwnerSettingsLocalisedNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Country::query()->firstOrCreate(['code' => 'SA'], ['name' => 'Saudi Arabia']);
        Country::query()->firstOrCreate(['code' => 'DE'], ['name' => 'Germany']);
    }

    public function test_helpers_name_in_the_reader_language_and_fall_back_to_the_stored_name(): void
    {
        $this->assertSame('المملكة العربية السعودية', country_display_name('SA', 'Saudi Arabia', 'ar'));
        $this->assertSame('Germany', country_display_name('DE', 'Germany', 'en'));
        $this->assertSame('Allemagne', country_display_name('de', 'Germany', 'fr'));
        $this->assertSame('Probeland', country_display_name('ZQ', 'Probeland', 'ar'), 'unknown code keeps the stored name');
        $this->assertSame('Stored', country_display_name('', 'Stored', 'ar'));
        $this->assertSame('الفرنسية', language_display_name('fr', 'French', 'ar'));
        $this->assertSame('French', language_display_name('fr', 'French', 'en'));
        $this->assertSame('Klingon', language_display_name('', 'Klingon', 'ar'));
        $this->assertSame('Custom', language_display_name('!!', 'Custom', 'ar'));
    }

    public function test_owner_account_page_in_arabic_shows_localised_country_and_language_names(): void
    {
        $user = User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'username' => 'own'.Str::lower(Str::random(5)),
            'email' => Str::random(6).'@example.com', 'password' => Hash::make('secret-pass-1'), 'image' => 'default_user.png',
        ]);
        app()->setLocale('ar');

        $html = $this->actingAs($user)->get(route('settings'))->assertOk()->getContent();

        // the two pickers on the page (the top bar's language switcher lists the languages by their own names, on purpose)
        preg_match('~<select[^>]*id="preferred_locale".*?</select>~s', $html, $lang);
        preg_match('~<select[^>]*id="billing_country".*?</select>~s', $html, $country);
        $this->assertNotEmpty($lang);
        $this->assertNotEmpty($country);

        $this->assertMatchesRegularExpression('/>\s*المملكة العربية السعودية\s*</u', $country[0]);
        $this->assertMatchesRegularExpression('/>\s*ألمانيا\s*</u', $country[0]);
        $this->assertDoesNotMatchRegularExpression('/>\s*(Saudi Arabia|Germany)\s*</', $country[0]);
        $this->assertMatchesRegularExpression('/>\s*الفرنسية\s*</u', $lang[0]);
        $this->assertDoesNotMatchRegularExpression('/>\s*(French|Arabic)\s*</', $lang[0]);
    }
}
