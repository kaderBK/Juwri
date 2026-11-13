<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-036 U2a: every owner dashboard screen must price in the WORKING store's
 * currency.
 *
 * Only a few controllers loaded the store's currency_code/sign/pos, so
 * analytics, reports, the wallet and the other owner screens priced everything
 * in the platform default (USD) for a SAR store. SyncDashboardStore now loads
 * the working store's currency once per dashboard request — and the swap is
 * DISPLAY only: the platform currency (merchant wallet, platform ledger) must
 * survive it, so money written later in the same request is never stamped with
 * the store's currency.
 *
 * The Riyal glyph (U+20C1) must also be in every kit font stack: it is
 * unicode-range scoped to that one character, so it is inert for everything
 * else — but without it a Saudi Riyal sign in a big number renders as a box.
 */
class DashboardStoreCurrencyTest extends TestCase
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

        // Keep the real pipeline but skip the install-gate, CSRF and
        // staff-permission middlewares that would 4xx for reasons unrelated to
        // the currency swap.
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);

        // The layout globals the owner shell renders against (WI-M12 helper).
        $this->shareLayoutGlobals();
        View::share(['admin_languages' => collect()]);

        // The PLATFORM currency the owner sees before the store swap, and the
        // platform code the wallet/ledger money must keep.
        config([
            'settings.currency_code' => 'USD',
            'settings.currency_sign' => '$',
            'settings.currency_pos' => 1,
            'settings.platform_currency_code' => null,
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The dashboard swap loads the store's currency once per request, so EVERY
     * owner screen prices in it — not just the handful of controllers that used
     * to read the store's own currency.
     */
    public function test_every_dashboard_screen_prices_in_the_working_store_currency(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', "\u{20C1}");
        StoreOption::updateStoreOption($store->id, 'currency_pos', 1);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('SAR', config('settings.currency_code'));
        $this->assertSame("\u{20C1}", config('settings.currency_sign'));
        $this->assertSame(1, config('settings.currency_pos'));

        // Another dashboard screen prices in the same currency: the swap lives in
        // the middleware, so it is per request and not per controller.
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('analytics.index'))
            ->assertOk();

        $this->assertSame('SAR', config('settings.currency_code'));
        $this->assertSame("\u{20C1}", config('settings.currency_sign'));

        // The PLATFORM currency survives the swap: money written later in the
        // same request (wallet, platform ledger) is not stamped with the
        // store's currency.
        $this->assertSame('USD', platform_currency());
    }

    /** A store that never chose a currency keeps the platform's — no blank sign, no reset code. */
    public function test_a_store_without_currency_options_leaves_the_platform_currency_alone(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertSame('USD', config('settings.currency_code'));
        $this->assertSame('$', config('settings.currency_sign'));
        $this->assertSame(1, config('settings.currency_pos'));
        $this->assertSame('USD', platform_currency());
    }

    /**
     * A NEW store starts in the PLATFORM currency, not the one of the store the
     * owner happened to have selected: the create request runs in the dashboard
     * group, so it was priced (and seeded) in the working store's riyals.
     */
    public function test_a_new_store_starts_in_the_platform_currency_not_the_working_stores(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', "\u{20C1}");

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->from(route('stores.create'))
            ->post(route('stores.store'), [
                'color' => '#111111', 'title' => 'Second Shop', 'description' => 'A restaurant', 'address' => '1 St',
                'store_template' => app(StorefrontThemeService::class)->defaultSlug(),
                'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
                'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
            ])
            ->assertRedirect(route('stores.index'));

        $new = Store::where('user_id', $owner->id)->where('title', 'Second Shop')->sole();
        $this->assertSame('USD', store_options($new->id, 'currency_code')); // before: SAR
        $this->assertSame('$', store_options($new->id, 'currency_sign')); // before: the riyal sign
    }

    /** The Riyal face (U+20C1) must lead every kit stack, or a riyal price renders as a box. */
    public function test_the_owner_ui_font_stacks_carry_the_saudi_riyal_face(): void
    {
        $css = (string) file_get_contents(public_path('assets/owner-ui/tokens.css'));

        foreach (['--ui-font', '--ui-font-display', '--ui-font-mono'] as $var) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($var, '/').':\s*\'Saudi Riyal\'/',
                $css,
                "[{$var}] must lead with the 'Saudi Riyal' face so U+20C1 renders in prices"
            );
        }
    }
}
