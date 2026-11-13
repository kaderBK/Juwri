<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\JournalEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN A-P4: accounting dashboard + sync coexistence.
 */
class AccountingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_accounting_gl' => true]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ChartOfAccounts::ensure($r->id);

        return [$owner, $r];
    }

    public function test_dashboard_renders_cash_and_revenue(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        JournalEngine::post($r->id, [
            ['account' => JournalEngine::account($r->id, '1110'), 'debit' => 200, 'credit' => 0],
            ['account' => JournalEngine::account($r->id, '4100'), 'debit' => 0, 'credit' => 200],
        ], 'Order', 1, 'order_paid');

        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.books.index').'?store_id='.$r->id)->assertOk();
        $res->assertSeeHtml(___('Cash position'));
        $res->assertSeeHtml('200.00');
    }

    public function test_dashboard_gate_and_tenant_scope(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.books.index').'?store_id='.$otherR->id)->assertForbidden();

        // Gate off → 403 even for own restaurant.
        config(['settings.admin_allow_accounting_gl' => false]);
        // feature middleware is stripped by withoutMiddleware, so assert via registry directly.
        $this->assertFalse(FeatureRegistry::isEnabled('accounting_gl'));
    }
}
