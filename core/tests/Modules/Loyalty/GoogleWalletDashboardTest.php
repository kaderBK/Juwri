<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Lane C — C5. Owner loyalty settings page shows the Google Wallet card; the
 * per-store toggle round-trips through the owner endpoint with tenant enforcement.
 */
class GoogleWalletDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $plan = TestPlans::create([
            'name' => 'Test Plan', 'description' => 'p', 'monthly_price' => 0, 'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => ['allow_viewonly' => '1', 'allow_pro' => '1', 'restaurant_limit' => 999],
            'status' => 1,
        ]);

        // Share the layout context the production ViewServiceProvider hands views
        // (stdClass settings, theme, languages, pages) instead of hand-rolling it —
        // see ViewComposerContractTest. The loyalty page's feature gate reads
        // $plan->settings, so plan is shared separately (not a layout global).
        $this->shareLayoutGlobals();
        View::share('plan', $plan);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'p-'.rand(1000, 99999), 'color' => '#ff5500',
            'title' => 'Pizza', 'sub_title' => 't', 'main_image' => 'store.png',
            'cover_image' => 'store.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    public function test_wallet_card_renders_on_loyalty_settings(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        session(['template' => 'juwri']);

        $res = $this->actingAs($owner)->get(route('loyalty.settings', ['storeId' => $store->id]));

        $res->assertStatus(200);
        $res->assertSee('Google Wallet passes');
        $res->assertSeeHtml('google_wallet_enabled'); // the toggle input name
        $res->assertSee('White-label note:'); // the reseller warning
    }

    public function test_owner_can_toggle_google_wallet_on_and_off(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);

        $this->actingAs($owner)
            ->post(route('googleWallet.toggle', $store->id), ['google_wallet_enabled' => '1'])
            ->assertRedirect();
        $this->assertSame('1', (string) store_options($store->id, 'google_wallet_enabled'));

        $this->actingAs($owner)
            ->post(route('googleWallet.toggle', $store->id), []) // unchecked box → absent
            ->assertRedirect();
        $this->assertSame('0', (string) StoreOption::getStoreOption($store->id, 'google_wallet_enabled', '0'));
    }

    public function test_other_owner_cannot_toggle(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        $intruder = User::factory()->create(['status' => 1]);

        $this->actingAs($intruder)
            ->post(route('googleWallet.toggle', $store->id), ['google_wallet_enabled' => '1'])
            ->assertNotFound();

        // The victim store's option is untouched.
        $this->assertNotSame('1', (string) StoreOption::getStoreOption($store->id, 'google_wallet_enabled', '0'));
    }
}
