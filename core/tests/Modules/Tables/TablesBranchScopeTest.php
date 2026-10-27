<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The Tables & QR screen must show the SAME set of tables as the Floor Map:
 * both scope to a branch, with legacy branch_id=NULL rows belonging to the
 * default (first) branch.
 */
class TablesBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store, 2: Branch, 3: Branch} */
    private function multiBranchStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Post::created auto-creates the default branch — use it as "main".
        $main = Branch::where('store_id', $store->id)->orderBy('id')->firstOrFail();
        $second = Branch::create(['store_id' => $store->id, 'name' => 'Second']);

        return [$owner, $store, $main, $second];
    }

    private function table(Store $store, ?int $branchId, int $no): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'branch_id' => $branchId,
            'table_no' => $no, 'key' => Str::random(10),
        ]);
    }

    public function test_default_branch_view_includes_legacy_null_tables_and_hides_other_branches(): void
    {
        [$owner, $store, $main, $second] = $this->multiBranchStore();
        $this->table($store, $main->id, 1);
        $this->table($store, $second->id, 2);
        $this->table($store, null, 3); // legacy row → belongs to default branch

        $res = $this->actingAs($owner)->get(route('stores.tables', $store));

        // Match the exact row markup — bare "#N" also matches hex colors and
        // anchors in the page chrome, which made this flaky in the full suite.
        $res->assertOk()->assertSeeHtml('<strong>#1</strong>')->assertSeeHtml('<strong>#3</strong>')->assertDontSeeHtml('<strong>#2</strong>');
    }

    public function test_second_branch_view_shows_only_its_tables(): void
    {
        [$owner, $store, $main, $second] = $this->multiBranchStore();
        $this->table($store, $main->id, 1);
        $this->table($store, $second->id, 2);
        $this->table($store, null, 3);

        $res = $this->actingAs($owner)->get(route('stores.tables', $store).'?branch='.$second->id);

        $res->assertOk()->assertSeeHtml('<strong>#2</strong>')->assertDontSeeHtml('<strong>#1</strong>')->assertDontSeeHtml('<strong>#3</strong>');
    }

    public function test_foreign_branch_is_rejected(): void
    {
        [$owner, $store] = $this->multiBranchStore();
        [, , $foreignBranch] = $this->multiBranchStore(); // belongs to another store

        $this->actingAs($owner)
            ->get(route('stores.tables', $store).'?branch='.$foreignBranch->id)
            ->assertNotFound();
    }

    public function test_new_table_is_created_on_the_viewed_branch(): void
    {
        [$owner, $store, , $second] = $this->multiBranchStore();

        $this->actingAs($owner)->post(route('stores.updateTable', $store), [
            'table_number' => 7,
            'branch_id' => $second->id,
        ]);

        $this->assertDatabaseHas('store_tables', [
            'store_id' => $store->id, 'table_no' => 7, 'branch_id' => $second->id,
        ]);
    }

    public function test_foreign_branch_id_on_create_falls_back_to_null(): void
    {
        [$owner, $store] = $this->multiBranchStore();
        [, , $foreignBranch] = $this->multiBranchStore();

        $this->actingAs($owner)->post(route('stores.updateTable', $store), [
            'table_number' => 9,
            'branch_id' => $foreignBranch->id,
        ]);

        $this->assertDatabaseHas('store_tables', [
            'store_id' => $store->id, 'table_no' => 9, 'branch_id' => null,
        ]);
    }
}
