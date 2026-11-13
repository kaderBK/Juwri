<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL T1-7 (A6): StoreCloneService copies the store's bio blocks so a clone's
 * travelled bio_settings is not left pointing at an empty page. Branches are not
 * cloned → every block lands on the clone's main page (branch_id NULL); bio_clicks
 * stay behind and clicks_cached resets (fresh store, fresh stats).
 */
class BioLinkCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
        ]);
        MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat'])
            ->menus()->create(['store_id' => $store->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);

        return $store;
    }

    private function cloneOf(User $owner, Store $source): Store
    {
        return Store::where('user_id', $owner->id)->where('id', '!=', $source->id)->firstOrFail();
    }

    public function test_clone_copies_bio_blocks_to_the_clone_main_page(): void
    {
        $owner = $this->makeUser();
        $source = $this->makeRestaurant($owner);
        $branch = Branch::create(['store_id' => $source->id, 'name' => 'Downtown', 'active' => 1]);

        BioBlock::factory()->create(['store_id' => $source->id, 'label' => 'Menu', 'position' => 0, 'clicks_cached' => 25]);
        BioBlock::factory()->create(['store_id' => $source->id, 'label' => 'Instagram', 'position' => 1]);
        BioBlock::factory()->create(['store_id' => $source->id, 'branch_id' => $branch->id, 'label' => 'Branch hours', 'position' => 0]);
        // A click stat on the source that must NOT travel.
        BioClick::create(['store_id' => $source->id, 'block_id' => 0, 'day' => now()->toDateString(), 'source' => 'qr', 'device' => 'mobile', 'count' => 9]);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $source))
            ->assertOk()
            ->assertJsonPath('counts.bio_blocks', 3);

        $clone = $this->cloneOf($owner, $source);
        $cloneBlocks = BioBlock::where('store_id', $clone->id)->get();

        // Equal block count; all on the clone's main page (branches were not cloned).
        $this->assertCount(3, $cloneBlocks);
        $this->assertTrue($cloneBlocks->every(fn ($b) => $b->branch_id === null));
        $this->assertEqualsCanonicalizing(
            ['Menu', 'Instagram', 'Branch hours'],
            $cloneBlocks->pluck('label')->all()
        );

        // Fresh stats: clicks_cached reset on the clone; no bio_clicks copied over.
        $this->assertTrue($cloneBlocks->every(fn ($b) => (int) $b->clicks_cached === 0));
        $this->assertSame(0, BioClick::where('store_id', $clone->id)->count());
    }

    public function test_clone_leaves_source_bio_blocks_and_stats_untouched(): void
    {
        $owner = $this->makeUser();
        $source = $this->makeRestaurant($owner);
        BioBlock::factory()->create(['store_id' => $source->id, 'label' => 'Menu', 'clicks_cached' => 25]);
        BioClick::create(['store_id' => $source->id, 'block_id' => 0, 'day' => now()->toDateString(), 'source' => 'qr', 'device' => 'mobile', 'count' => 9]);

        $this->actingAs($owner)->postJson(route('stores.clone', $source))->assertOk();

        // Source keeps its block, its cached clicks and its bio_clicks row.
        $srcBlock = BioBlock::where('store_id', $source->id)->firstOrFail();
        $this->assertSame(25, (int) $srcBlock->clicks_cached);
        $this->assertSame(1, BioClick::where('store_id', $source->id)->count());
    }

    public function test_clone_counts_carry_bio_blocks_key_when_empty(): void
    {
        $owner = $this->makeUser();
        $source = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $source))
            ->assertOk()
            ->assertJsonPath('counts.bio_blocks', 0);
    }
}
