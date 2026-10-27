<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OwnerApp;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner app billing screen (server item P5-22): GET owner-app/billing.
 *
 * The page is a Blade shell; the plan data comes from owner-app/billing/overview
 * (Lane C) and the purchase runs through the native `purchase` bridge component.
 * These tests pin the shell's contract: owner-only, every user-facing string
 * comes from ___() and is handed to billing.js as data-i18n, and no link to a
 * store or web checkout is rendered (Apple 3.1.3).
 */
class BillingScreenTest extends TestCase
{
    use RefreshDatabase;

    /** The exact source strings the page must carry (the brief lists 30 unique). */
    private const STRINGS = [
        'Subscription',
        'Choose a plan',
        'Your plan',
        'No active subscription',
        'Renews on :date',
        'Payment problem. Your plan stays active until :date while the store retries.',
        'Cancelled. Your plan stays active until :date.',
        'Expired on :date.',
        'Refunded. This plan is no longer active.',
        'The store removed access to this plan.',
        'Subscribe',
        'Restore purchases',
        'Restoring…',
        'Purchases restored.',
        'No purchases to restore.',
        'Purchase complete.',
        'Purchase cancelled.',
        'Waiting for approval from the store.',
        'Something went wrong. Please try again.',
        'Billing is not available right now.',
        'Loading plans…',
        'per month',
        'per year',
        'Manage or cancel your subscription in the App Store or Google Play settings.',
        'Active',
        'Grace period',
        'Cancels at period end',
        'Expired',
        'Refunded',
        'Removed',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, TrackOwnerScreenView::class]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function screen(): string
    {
        $owner = $this->owner();
        $this->store($owner);

        return (string) $this->actingAs($owner)->get(route('owner-app.billing.screen'))->assertOk()->getContent();
    }

    public function test_an_owner_can_open_the_billing_screen(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('data-owner-billing', $html);
        $this->assertStringContainsString('data-controller="bridge--purchase"', $html);
        $this->assertStringContainsString("data-i18n='", $html);
        $this->assertStringContainsString('assets/owner-app/billing.css', $html);
        // The page script is same-origin, nonce'd (CSP) and re-runs after Turbo.
        $this->assertMatchesRegularExpression(
            '#<script nonce="[^"]+" src="[^"]*assets/owner-app/billing\.js[^"]*" data-turbo-eval="false"></script>#',
            $html
        );
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('owner-app.billing.screen'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_page_carries_every_source_string_via_translation(): void
    {
        $html = $this->screen();

        foreach (self::STRINGS as $string) {
            $this->assertStringContainsString($string, $html, "missing source string: {$string}");
            $this->assertStringContainsString(___($string), $html, "string not translated: {$string}");
        }
    }

    public function test_it_renders_no_link_to_a_store_or_web_checkout(): void
    {
        $html = $this->screen();

        // The billing content (root -> footer) contains no anchor at all; the
        // footer sentence is plain text and points nowhere.
        preg_match('/data-owner-billing(.*?)Manage or cancel your subscription/s', $html, $m);
        $this->assertNotEmpty($m, 'could not isolate the billing block');
        $this->assertStringNotContainsString('<a ', $m[1]);
        $this->assertStringNotContainsString('href', $m[1]);
        $this->assertStringNotContainsString('apps.apple.com', $m[1]);
        $this->assertStringNotContainsString('play.google.com', $m[1]);
        $this->assertStringNotContainsString('itunes.apple.com', $m[1]);

        $this->assertStringContainsString('Manage or cancel your subscription in the App Store or Google Play settings.', $html);
    }
}
