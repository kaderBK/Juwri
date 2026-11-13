<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientInterface;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N2-P4 — Keeta owner UI: dashboard renders owner-scoped, Keeta is a connectable
 * provider, menu-push builds + logs, cross-tenant connection is 404.
 */
class KeetaOwnerUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
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
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);
        $this->withoutMiddleware();
        FakeKeetaClient::reset();
        $this->app->bind(KeetaClientInterface::class, fn () => new FakeKeetaClient);
    }

    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'aggregator_hub' => '1']),
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $r];
    }

    private function keetaConn(Store $r): AggregatorConnection
    {
        $c = AggregatorConnection::create([
            'provider' => 'keeta', 'store_id' => $r->id, 'external_store_id' => 'KS-1', 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'keeta', 'provider' => 'keeta',
            'secrets' => ['app_id' => 'a', 'app_secret' => 's'], 'active' => true,
        ]);

        return $c;
    }

    public function test_dashboard_lists_keeta_provider_and_push_button(): void
    {
        [$user, $r] = $this->owner();
        $this->keetaConn($r);

        $res = $this->actingAs($user)->get(route('stores.aggregators.index', ['store_id' => $r->id]));
        $res->assertOk();
        $res->assertSeeHtml('Keeta');
        $res->assertSeeHtml('data-keeta-push');
    }

    public function test_menu_push_builds_and_logs(): void
    {
        [$user, $r] = $this->owner();
        $conn = $this->keetaConn($r);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => '1', 'position' => 1]);

        $this->actingAs($user)
            ->post(route('stores.aggregators.menuPush', $conn->id), ['store_id' => $r->id])
            ->assertRedirect();

        $this->assertNotNull(FakeKeetaClient::$lastMenu);
        $this->assertSame(1, count(FakeKeetaClient::$lastMenu['spus']));
        $log = AggregatorSyncLog::where('store_id', $r->id)->where('provider', 'keeta')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->status);
    }

    public function test_cross_tenant_connection_is_not_found(): void
    {
        [$userA, $rA] = $this->owner();
        [$userB, $rB] = $this->owner();
        $connB = $this->keetaConn($rB);

        // User A cannot push User B's channel.
        $this->actingAs($userA)
            ->post(route('stores.aggregators.menuPush', $connB->id), ['store_id' => $rA->id])
            ->assertNotFound();
    }
}
