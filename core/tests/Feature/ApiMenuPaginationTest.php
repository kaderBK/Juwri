<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * P6-277 — the public owner API's menu surface must not load a whole 20k-SKU
 * catalogue to answer one request.
 *
 *  - GET /api/v1/stores/{id} (show) counts the store's menu rows in SQL
 *    (COUNT), it never SELECTs them just to call ->count() in PHP.
 *  - GET /api/v1/stores/{id}/menu stays in its legacy bare-array shape when
 *    the caller sends no `?per_page`, but honours a capped `?per_page` (max 200)
 *    with a paginator envelope when they do — so a page is bounded and carries
 *    `meta.total` / `links.next`.
 */
class ApiMenuPaginationTest extends TestCase
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
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function owner(): User
    {
        return User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id,
            'store_type' => 'restaurant',
            'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111',
            'title' => 'R '.Str::random(4),
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'd.png',
            'cover_image' => 'd.png',
            'address' => '1 St',
            'phone' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function token(User $owner): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id,
            'name' => 'T',
            'token' => hash('sha256', $raw),
            'abilities' => ['read'],
        ]);

        return $raw;
    }

    private function headers(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }

    private function category(Store $store, string $name, int $position = 0): MenuCategory
    {
        return MenuCategory::create([
            'store_id' => $store->id,
            'name' => $name,
            'position' => $position,
        ]);
    }

    private function item(Store $store, MenuCategory $category, string $name): Menu
    {
        return Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => 5,
            'active' => '1',
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Does this SQL touch the menu table (`items`), prefixed or quoted? */
    private function referencesMenuTable(string $sql): bool
    {
        return (bool) preg_match(
            '/\bfrom\s+[`"\[]?[a-z0-9_]*?(items|menus)[`"\]]?(?:\s|$|\))/i',
            $sql
        );
    }

    /** Count menu items in either envelope shape: a flat list, or categories each with `items`. */
    private function itemTotal(mixed $data): int
    {
        if (! is_array($data)) {
            return 0;
        }

        $total = 0;
        foreach ($data as $row) {
            if (is_array($row) && array_key_exists('items', $row) && is_array($row['items'])) {
                $total += count($row['items']);
            } else {
                $total++;
            }
        }

        return $total;
    }

    // ── show(): count in SQL, never load the rows to count them ───────────────

    public function test_show_counts_menu_rows_in_sql_without_selecting_them(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $category = $this->category($store, 'Starters');
        $this->item($store, $category, 'Soup');
        $this->item($store, $category, 'Salad');
        $this->item($store, $category, 'Bread');

        $raw = $this->token($owner);

        DB::enableQueryLog();
        $res = $this->getJson(route('api.v1.stores.show', $store->id), $this->headers($raw))
            ->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $res->assertJsonPath('items_count', 3);

        $menuQueries = array_values(array_filter(
            array_map(fn ($q) => strtolower((string) $q['query']), $log),
            fn (string $sql) => $this->referencesMenuTable($sql)
        ));

        $this->assertNotEmpty($menuQueries, 'show() must query the menu table');

        $counts = array_filter(
            $menuQueries,
            fn (string $sql) => preg_match('/\bcount\s*\(/i', $sql) === 1
        );
        $this->assertNotEmpty(
            $counts,
            "show() must COUNT menu rows in SQL, saw:\n".implode("\n", $menuQueries)
        );

        $unboundedSelects = array_values(array_filter($menuQueries, function (string $sql): bool {
            if (! str_starts_with(ltrim($sql), 'select')) {
                return false;
            }
            if (preg_match('/\b(count|sum|min|max|avg)\s*\(/i', $sql) === 1) {
                return false;
            }

            return preg_match('/\blimit\b/i', $sql) !== 1;
        }));

        $this->assertSame(
            [],
            $unboundedSelects,
            "show() must not SELECT every menu row; saw:\n".implode("\n", $unboundedSelects)
        );
    }

    // ── menu(): ?per_page=50 → 50 items + a next link ─────────────────────────

    public function test_menu_per_page_returns_a_bounded_page_with_a_next_link(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $first = $this->category($store, 'First', 0);
        $second = $this->category($store, 'Second', 1);
        $third = $this->category($store, 'Third', 2);

        $categories = [$first, $second, $third];
        for ($i = 1; $i <= 60; $i++) {
            $this->item($store, $categories[($i - 1) % 3], 'Item '.$i);
        }

        $raw = $this->token($owner);

        $res = $this->getJson(
            route('api.v1.stores.menu', $store->id).'?per_page=50',
            $this->headers($raw)
        )->assertOk();

        $this->assertSame(
            50,
            $this->itemTotal($res->json('data')),
            'a page must hold exactly per_page items'
        );
        $this->assertEquals(60, (int) $res->json('meta.total'));
        $this->assertNotNull($res->json('links.next'), 'a second page must exist');
    }

    // ── menu(): no ?per_page → legacy bare category array ─────────────────────

    public function test_menu_without_per_page_keeps_the_legacy_category_array(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $first = $this->category($store, 'Drinks', 0);
        $second = $this->category($store, 'Desserts', 1);
        $this->item($store, $first, 'Tea');
        $this->item($store, $first, 'Coffee');
        $this->item($store, $second, 'Cake');

        $raw = $this->token($owner);

        $res = $this->getJson(route('api.v1.stores.menu', $store->id), $this->headers($raw))
            ->assertOk();

        $json = $res->json();
        $this->assertIsArray($json);
        $this->assertTrue(array_is_list($json), 'the legacy shape is a bare list of categories');
        $this->assertArrayNotHasKey('data', $json);
        $this->assertArrayNotHasKey('meta', $json);
        $this->assertCount(2, $json);

        $this->assertArrayHasKey('id', $json[0]);
        $this->assertArrayHasKey('name', $json[0]);
        $this->assertArrayHasKey('items', $json[0]);
        $this->assertCount(2, $json[0]['items']);
        $this->assertCount(1, $json[1]['items']);
        $this->assertSame('Tea', $json[0]['items'][0]['name']);

        $this->assertSame(3, $this->itemTotal($json), 'all items remain visible without per_page');
    }

    // ── menu(): ?per_page=9999 is capped at 200 ───────────────────────────────

    public function test_menu_per_page_is_capped_at_two_hundred(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $category = $this->category($store, 'Only', 0);
        for ($i = 1; $i <= 3; $i++) {
            $this->item($store, $category, 'Item '.$i);
        }

        $raw = $this->token($owner);

        $res = $this->getJson(
            route('api.v1.stores.menu', $store->id).'?per_page=9999',
            $this->headers($raw)
        )->assertOk();

        $this->assertEquals(200, (int) $res->json('meta.per_page'));
    }
}
