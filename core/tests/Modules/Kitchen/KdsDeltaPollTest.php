<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\Order;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Kitchen\Http\Controllers\User\KdsController;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KdsDeltaPollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware();
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
    }

    private function makeUser(object $plan): User
    {
        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 'Test',
            'description' => 'Test',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test St',
            'phone' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(Store $store, string $status = 'new'): Order
    {
        return Order::create([
            'store_id' => $store->id,
            'status' => $status,
            'type' => 'on-table',
            'customer_name' => 'Test Customer',
            'table_number' => 1,
            'created_at' => now(),
        ]);
    }

    private function poll(User $u, Store $r, string $cursor)
    {
        return $this->actingAs($u)->getJson(route('kds.poll', ['store_id' => $r->id, 'cursor' => $cursor]));
    }

    public function test_first_cursor_poll_is_full_then_delta_reports_new_changed_removed(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $r = $this->makeRestaurant($owner);
        $a = $this->makeOrder($r, 'new');
        $b = $this->makeOrder($r, 'new');

        $first = $this->poll($owner, $r, 'cursorAAAA1111')->assertOk()->json();
        $this->assertCount(2, $first);              // legacy array
        $this->assertArrayNotHasKey('delta', $first);

        $same = $this->poll($owner, $r, 'cursorAAAA1111')->json();
        $this->assertTrue($same['delta']);
        $this->assertSame([], $same['orders']);     // nothing changed
        $this->assertSame([], $same['removed']);

        $c = $this->makeOrder($r, 'new');           // new
        $this->travel(2)->seconds();
        $a->update(['status' => 'preparing']);      // changed
        $b->update(['status' => 'completed']);      // removed
        $d = $this->poll($owner, $r, 'cursorAAAA1111')->json();
        $this->assertEqualsCanonicalizing([$a->id, $c->id], collect($d['orders'])->pluck('id')->all());
        $this->assertSame([$b->id], $d['removed']);
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $d['order']);
    }

    public function test_initial_load_is_capped_oldest_first_with_paging_and_scoped(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $r = $this->makeRestaurant($owner);
        $n = KdsController::KDS_WINDOW;
        $ids = [];
        for ($i = 0; $i < $n + 5; $i++) {
            $o = $this->makeOrder($r, 'new');
            $o->forceFill(['created_at' => now()->subMinutes(1000 - $i)])->saveQuietly(); // ascending age order
            $ids[] = $o->id;
        }
        $theirs = $this->makeOrder($this->makeRestaurant($this->makeUser($this->makePlan())), 'new');

        $res = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id]))->assertOk();
        $page1 = collect($res->json())->pluck('id')->all();
        $this->assertCount($n, $page1);
        $this->assertSame(array_slice($ids, 0, $n), $page1);       // oldest first
        $this->assertSame('1', $res->headers->get('X-Kds-Has-More'));
        $this->assertNotContains($theirs->id, $page1);              // store scoping kept

        $res2 = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id, 'page' => 2]))->assertOk();
        $this->assertSame(array_slice($ids, $n), collect($res2->json())->pluck('id')->all());
        $this->assertSame('0', $res2->headers->get('X-Kds-Has-More'));
    }

    public function test_rush_ticket_stays_in_the_capped_window_and_delta_is_capped(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $r = $this->makeRestaurant($owner);
        $n = KdsController::KDS_WINDOW;
        for ($i = 0; $i < $n + 3; $i++) {
            $this->makeOrder($r, 'new');
        }
        $rush = $this->makeOrder($r, 'new');
        $rush->forceFill(['is_rush' => true])->saveQuietly();

        $first = $this->poll($owner, $r, 'capCursor0001')->assertOk()->json();
        $this->assertCount($n, $first);
        $this->assertSame($rush->id, $first[0]['id']);

        $d = $this->poll($owner, $r, 'capCursor0001')->assertOk();
        $this->assertTrue($d->json('delta'));
        $this->assertCount($n, $d->json('order'));
        $this->assertSame([], $d->json('orders'));
        $this->assertSame('1', $d->headers->get('X-Kds-Has-More'));
    }

    public function test_delta_never_leaks_another_stores_orders(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $r = $this->makeRestaurant($owner);
        $mine = $this->makeOrder($r, 'new');
        $other = $this->makeUser($this->makePlan());
        $theirs = $this->makeOrder($this->makeRestaurant($other), 'new');

        $this->poll($owner, $r, 'sharedCursor99');
        $d = $this->poll($owner, $r, 'sharedCursor99')->json();
        $this->assertSame([$mine->id], $d['order']);

        // the other owner reusing the same token has their own state and scope
        $this->poll($other, $r, 'sharedCursor99')->assertForbidden();
        $own = $this->actingAs($other)->getJson(route('kds.poll', ['cursor' => 'sharedCursor99']))->json();
        $this->assertSame([$theirs->id], collect($own)->pluck('id')->all());
    }

    public function test_feature_registry_cache_busts_on_edit(): void
    {
        FeatureRegistry::clearCache();
        $f = Feature::query()->whereNotNull('default_value')->where('killable', true)->first();
        $this->assertNotNull($f);
        $before = collect(FeatureRegistry::all())->firstWhere('key', $f->key);
        $this->assertTrue(Cache::has(FeatureRegistry::CACHE_KEY));

        $f->update(['label' => 'Renamed P658']);
        $this->assertFalse(Cache::has(FeatureRegistry::CACHE_KEY));
        $after = collect(FeatureRegistry::all())->firstWhere('key', $f->key);
        $this->assertSame('Renamed P658', $after['label']);
        $this->assertNotSame($before['label'], $after['label']);
    }

    public function test_currencies_served_from_cache_after_first_load(): void
    {
        Currencies::flush();
        Currencies::exponent('JOD');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $m = new \ReflectionProperty(Currencies::class, 'exponents');
        $m->setAccessible(true);
        $m->setValue(null, null); // new "request": process memo empty, cache warm
        $this->assertSame(3, Currencies::exponent('JOD'));
        $this->assertEmpty(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'currencies')));
    }
}
