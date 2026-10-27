<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The track page renders in the visitor's CURRENT language — the language they
 * are viewing the whole storefront in (the qc_lang_choice cookie) — so it matches
 * the rest of the site: browse in English, the track page is English; browse in
 * Arabic, it is Arabic. The order's own placed-in locale is the fallback ONLY for
 * a cold link (SMS/email in a fresh browser) that expresses no current language.
 */
class TrackPageLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // current_language() reads the languages table for direction; without rows
        // it is null and the RTL/LTR the assertions check never resolves.
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1, 'position' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 2]);

        $this->shareLayoutGlobals();

        // The track route otherwise 302s: the localization filter would redirect
        // to add a locale prefix, and Installed guards the public app.
        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            Installed::class,
            ThrottleRequests::class,
        ]);
    }

    private function order(?string $locale): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        return Order::create([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'customer_name' => 'Ada', 'track_token' => Str::random(32), 'locale' => $locale,
            'created_at' => now(),
        ]);
    }

    public function test_current_language_wins_english_cookie_over_an_arabic_order(): void
    {
        // Diner is viewing the storefront in English; the order was placed in
        // Arabic. The track page must match what they are reading now — English.
        $order = $this->order('ar');

        $this->withUnencryptedCookie('qc_lang_choice', 'en')
            ->get(route('store.orderTrack', $order->track_token))->assertOk()->assertSeeHtml('lang="en"');
    }

    public function test_current_language_wins_arabic_cookie_over_an_english_order(): void
    {
        // The mirror case: viewing in Arabic, order placed in English -> Arabic.
        $order = $this->order('en');

        $this->withUnencryptedCookie('qc_lang_choice', 'ar')
            ->get(route('store.orderTrack', $order->track_token))->assertOk()->assertSeeHtml('dir="rtl"')->assertSeeHtml('lang="ar"');
    }

    public function test_cold_link_with_no_choice_falls_back_to_the_order_locale(): void
    {
        // No qc_lang_choice cookie (fresh browser from an SMS/email link): the
        // language the order was placed in is the best guess.
        $order = $this->order('ar');

        $this->get(route('store.orderTrack', $order->track_token))->assertOk()->assertSeeHtml('dir="rtl"')->assertSeeHtml('lang="ar"');
    }
}
