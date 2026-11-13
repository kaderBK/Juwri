<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug 4 (2026-10-01): users.country_code was char(50). It is now a 2-letter ISO-3166 alpha-2 column and every
 * writer stores the ISO-2 code or null:
 *  - signup with geo absent (the default) stored the lookup's 'Other' sentinel → now null;
 *  - the User model normalises any Eloquent write (trim, upper-case, non-ISO-2 → null) and reads tolerantly;
 *  - the billing form rejects a country code that is not two letters, even if a `countries` row carries it.
 */
class UserCountryCodeIsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    public function test_iso_country_code_normalises_or_returns_null(): void
    {
        $this->assertSame('SA', User::isoCountryCode('SA'));
        $this->assertSame('TR', User::isoCountryCode(' tr '));
        $this->assertSame('TR', User::isoCountryCode('TR   '), 'PostgreSQL CHAR padding');
        $this->assertNull(User::isoCountryCode('Other'));
        $this->assertNull(User::isoCountryCode('Saudi Arabia'));
        $this->assertNull(User::isoCountryCode('+966'));
        $this->assertNull(User::isoCountryCode('SAU'));
        $this->assertNull(User::isoCountryCode(''));
        $this->assertNull(User::isoCountryCode(null));
    }

    public function test_eloquent_writes_store_iso2_or_null(): void
    {
        $user = User::factory()->create(['country_code' => ' de ']);
        $this->assertSame('DE', DB::table('users')->where('id', $user->id)->value('country_code'));

        $user->update(['country_code' => 'Other']);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('country_code'));
        $this->assertNull($user->fresh()->country_code);
    }

    public function test_signup_without_geo_stores_no_country_code_instead_of_the_other_sentinel(): void
    {
        Mail::fake();
        $_SERVER['HTTP_USER_AGENT'] = 'bug4/phpunit';
        $plan = TestPlans::create([
            'name' => 'Trial', 'description' => 'T',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => (object) ['restaurant_limit' => 3], 'status' => 1,
        ]);
        config(['settings.default_user_plan' => (string) $plan->id]);

        $this->post(route('register'), [
            'username' => 'bugfourowner',
            'email' => 'bug4-owner@local.invalid',
            'password' => 'Bug4-pass-1234',
            'password_confirmation' => 'Bug4-pass-1234',
            'agree_for_term' => '1',
        ])->assertRedirect(route('dashboard'));

        $row = DB::table('users')->where('email', 'bug4-owner@local.invalid')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->country_code, "before: 'Other'");
    }

    public function test_billing_form_rejects_a_code_that_is_not_two_letters(): void
    {
        Country::factory()->create(['code' => 'OTHER', 'name' => 'Elsewhere']);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'country_code' => 'SA']);

        $this->actingAs($owner)->from('/settings')->post(route('settings.editBilling'), [
            'billing_details_type' => 'business', 'billing_name' => 'Owner Co', 'billing_address' => '1 Road',
            'billing_city' => 'Riyadh', 'billing_state' => 'RY', 'billing_zipcode' => '11564',
            'billing_country' => 'OTHER',
        ])->assertRedirect('/settings')->assertSessionHasErrorsIn('billing', ['billing_country']);

        $this->assertSame('SA', $owner->fresh()->country_code);
    }

    public function test_column_is_two_characters_on_server_databases(): void
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'country_code');
        $this->assertNotNull($column);
        $this->assertTrue($column['nullable']);
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no varchar length; the server drivers carry it in the type.
            return;
        }
        $this->assertStringContainsString('(2)', (string) $column['type'], 'before: char(50)');
    }
}
