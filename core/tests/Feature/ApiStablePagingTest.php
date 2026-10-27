<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Brand;
use Tests\TestCase;

/**
 * P6-340 — paged lists sorted by a non-unique column must not repeat or drop rows
 * across pages. Each list carries a unique `id` tiebreak right after its primary
 * sort, so rows sharing the sort key page deterministically.
 *
 * Owner-token endpoints are used (deliveries, brands) — the staff/driver
 * transport authenticates the same way and only adds heavier fixture setup.
 */
class ApiStablePagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // driver_app ships with its platform kill-switch OFF; the deliveries list
        // sits behind `feature:driver_app`, so flip it on for these tests.
        config(['settings.admin_allow_driver_app' => true]);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function auth(User $owner, array $abilities): array
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw),
            'abilities' => $abilities,
        ]);

        return ['Authorization' => 'Bearer '.$raw];
    }

    /**
     * Fetch `$pages` pages of a `?per_page=` list and flatten the `id`s in order.
     *
     * @return list<int>
     */
    private function pagedIds(string $baseUrl, array $headers, int $perPage, int $pages): array
    {
        $ids = [];
        for ($page = 1; $page <= $pages; $page++) {
            $res = $this->getJson($baseUrl.'?per_page='.$perPage.'&page='.$page, $headers)->assertOk();
            foreach ($res->json('data') as $row) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    public function test_deliveries_page_stably_when_orders_share_created_at(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->auth($owner, ['orders:read']);

        $at = now();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Order::create(['store_id' => $store->id, 'type' => 'delivery', 'status' => 'pending', 'created_at' => $at])->id;
        }

        $expected = collect($ids)->sortDesc()->values()->all();
        $seen = $this->pagedIds('/api/v1/stores/'.$store->id.'/deliveries', $headers, 2, 3);

        $this->assertSame($expected, $seen, 'pages 1..3 must join into one stable id-descending list');
        $this->assertCount(5, $seen, 'every order must appear exactly once across the pages');
        $this->assertSame($seen, array_values(array_unique($seen)), 'no id may repeat across pages');
    }

    public function test_per_page_is_honoured_on_deliveries(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->auth($owner, ['orders:read']);

        for ($i = 0; $i < 4; $i++) {
            Order::create(['store_id' => $store->id, 'type' => 'delivery', 'status' => 'pending', 'created_at' => now()]);
        }

        $res = $this->getJson('/api/v1/stores/'.$store->id.'/deliveries?per_page=2', $headers)->assertOk();

        $res->assertJsonPath('per_page', 2);
        $this->assertCount(2, $res->json('data'));
    }

    public function test_brands_page_stably_when_positions_are_equal(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $headers = $this->auth($owner, ['menu:read']);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Brand::create(['store_id' => $store->id, 'name' => 'Brand '.$i, 'position' => 0])->id;
        }

        $expected = collect($ids)->sort()->values()->all();
        $seen = $this->pagedIds('/api/v1/stores/'.$store->id.'/brands', $headers, 2, 3);

        $this->assertSame($expected, $seen, 'pages 1..3 must join into one stable id-ascending list');
        $this->assertCount(5, $seen, 'every brand must appear exactly once across the pages');
        $this->assertSame($seen, array_values(array_unique($seen)), 'no id may repeat across pages');
    }
}
