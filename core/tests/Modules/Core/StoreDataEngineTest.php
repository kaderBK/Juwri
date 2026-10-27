<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\StoreCloneService;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreDataPolicy;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\StoreDataEngine;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use RuntimeException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-21 part 1 (J29-8): the store-data engine — transfer and duplicate driven by the registry.
 *
 * Tenancy-critical: a row of store A never ends up under another account except by an accepted transfer, and a
 * duplicate never points at a row of the source store.
 */
class StoreDataEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    protected function tearDown(): void
    {
        StoreDataRegistry::forget('P121Boom');
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $u, string $slug): Store
    {
        return Store::create([
            'user_id' => $u->id, 'title' => 'Store '.$slug, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function row(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId(TenantFill::row(DB::connection(), $table, $row));
    }

    private function transfer(User $from, User $to, Store $store): StoreTransfer
    {
        return StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $from->id,
            'to_user_id' => $to->id, 'to_email' => $to->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
    }

    /** A catalogue with every copied kind of row. */
    private function catalogue(Store $store): array
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $sub = MenuCategory::create(['store_id' => $store->id, 'name' => 'Grill', 'parent' => $cat->id]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $sub->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);
        $gone = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Old dish', 'price' => 9, 'active' => 1]);
        $gone->delete(); // soft-deleted: not copied
        MenuVariantOption::create(['store_id' => $store->id, 'menu_id' => $item->id, 'title' => 'Size', 'options' => ['S', 'L'], 'option_prices' => [10, 15]]);
        MenuExtra::create(['store_id' => $store->id, 'menu_id' => $item->id, 'title' => 'Cheese', 'price' => 2]);
        $library = MenuExtra::create(['store_id' => $store->id, 'menu_id' => null, 'is_global' => true, 'title' => 'Sauce', 'price' => 1]);
        $item->attachedExtras()->attach($library->id, ['position' => 0, 'active' => true]);
        $variant = $this->row('menu_variants', ['store_id' => $store->id, 'menu_id' => $item->id, 'price_minor' => 3000, 'sku' => 'K-L', 'active' => 1, 'position' => 0]);
        $combo = $this->row('combos', ['store_id' => $store->id, 'name' => 'Meal', 'price_minor' => 3500, 'active' => 1, 'position' => 0]);
        $slot = $this->row('combo_slots', ['store_id' => $store->id, 'combo_id' => $combo, 'title' => 'Main', 'min_select' => 1, 'max_select' => 1, 'position' => 0]);
        $this->row('combo_slot_options', ['store_id' => $store->id, 'combo_id' => $combo, 'combo_slot_id' => $slot, 'menu_id' => $item->id, 'variant_id' => $variant, 'upcharge_minor' => 0, 'active' => 1, 'position' => 0]);

        return ['item' => $item->id, 'variant' => $variant, 'combo' => $combo];
    }

    public function test_duplicate_references_only_rows_of_the_clone(): void
    {
        $alice = $this->owner();
        $source = $this->store($alice, 'eng-src');
        $src = $this->catalogue($source);

        ['clone' => $clone, 'counts' => $counts] = app(StoreCloneService::class)->clone($source);

        $this->assertSame(1, $counts['combos']);
        $this->assertSame(1, $counts['variants']);
        $this->assertSame(1, $counts['modifiers'], 'variant groups');
        $this->assertSame(2, $counts['extras'], 'add-ons');
        $this->assertSame(1, $counts['items'], 'the soft-deleted item is not copied');
        $this->assertFalse(DB::table('items')->where('store_id', $clone->id)->where('name', 'Old dish')->exists());

        // Every remapped reference of every copied row points at a row OF THE CLONE.
        foreach ((new StoreDataEngine)->copyOrder() as $table) {
            foreach (DB::table($table->table)->where('store_id', $clone->id)->get() as $row) {
                $this->assertSame((int) $clone->account_id, (int) $row->account_id, $table->table.' account');
                foreach (array_keys($table->remap) as $column) {
                    [$target] = $table->remapTarget($column);
                    if ($row->{$column} !== null) {
                        $this->assertSame((int) $clone->id, (int) DB::table($target)->where('id', $row->{$column})->value('store_id'), "{$table->table}.{$column} -> {$target}");
                    }
                }
            }
        }
        $option = DB::table('combo_slot_options')->where('store_id', $clone->id)->first();
        $this->assertNotSame($src['variant'], (int) $option->variant_id);
        $this->assertSame('K-L', DB::table('menu_variants')->where('id', $option->variant_id)->value('sku'));
        $this->assertNotSame($src['combo'], (int) $option->combo_id);
        // The source keeps its rows.
        $this->assertSame(1, DB::table('combo_slot_options')->where('store_id', $source->id)->count());
        $this->assertSame($src['item'], (int) DB::table('combo_slot_options')->where('store_id', $source->id)->value('menu_id'));
    }

    public function test_duplicate_does_not_inherit_platform_grants(): void
    {
        $alice = $this->owner();
        $source = $this->store($alice, 'eng-grants');
        $source->forceFill([
            'is_verified' => true, 'custom_code_enabled' => true, 'mk_enabled' => true, 'mk_status' => 'approved',
            'mk_featured' => true, 'mk_reviewed_at' => now(), 'mk_review_note' => 'ok',
        ])->save();

        ['clone' => $clone] = app(StoreCloneService::class)->clone($source);
        $clone->refresh();

        $this->assertFalse((bool) $clone->is_verified);
        $this->assertFalse((bool) $clone->custom_code_enabled);
        $this->assertFalse((bool) $clone->mk_featured);
        $this->assertNull($clone->mk_status, 'the copy is not marketplace-approved without a review');
        $this->assertNull($clone->mk_reviewed_at);
        $this->assertFalse(Store::query()->whereKey($clone->id)->where('mk_enabled', true)->where('mk_status', 'approved')->exists());
        $this->assertSame('approved', $source->fresh()->mk_status);
    }

    public function test_transfer_leaves_no_row_of_the_store_under_the_old_account_but_the_ledgers(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->store($alice, 'eng-sweep');
        $this->catalogue($store);
        $this->row('orders', ['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => now()]);
        $this->row('message_costs', ['store_id' => $store->id, 'channel' => 'sms', 'category' => 'utility', 'units' => 1, 'created_at' => now()]);
        $aliceAccount = (int) $store->account_id;

        app(OwnershipTransferService::class)->applyStore($this->transfer($alice, $bob, $store));
        $bobAccount = (int) $store->fresh()->account_id;

        $stay = array_keys(StoreDataRegistry::where(StoreDataPolicy::StayWithOldAccount));
        foreach (TenantTables::storeAccountTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue; // a table of an edition package that is not installed
            }
            $expected = in_array($table, $stay, true) ? $aliceAccount : $bobAccount;
            $wrong = DB::table($table)->where('store_id', $store->id)->where(fn ($q) => $q->where('account_id', '!=', $expected)->orWhereNull('account_id'))->count();
            $this->assertSame(0, $wrong, "{$table}: rows of the store under the wrong account");
        }
        $this->assertSame(1, DB::table('message_costs')->where('store_id', $store->id)->where('account_id', $aliceAccount)->count());
    }

    public function test_a_failing_reset_rolls_the_whole_transfer_back(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->store($alice, 'eng-atomic');
        $this->catalogue($store);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $aliceAccount = (int) $store->account_id;
        $transfer = $this->transfer($alice, $bob, $store);

        StoreDataRegistry::register('P121Boom', new class extends StoreData
        {
            public function tables(): array
            {
                return [];
            }

            public function isOwnerSetting(string $name): bool
            {
                throw new RuntimeException('reset failed');
            }
        });

        try {
            app(OwnershipTransferService::class)->applyStore($transfer);
            $this->fail('the transfer should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('reset failed', $e->getMessage());
        }

        $this->assertSame($alice->id, (int) $store->fresh()->user_id);
        $this->assertSame($aliceAccount, (int) $store->fresh()->account_id);
        $this->assertSame(0, DB::table('items')->where('store_id', $store->id)->where('account_id', '!=', $aliceAccount)->count(), 'no row moved');
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_a_failing_audit_insert_does_not_undo_the_transfer(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->store($alice, 'eng-audit');
        $transfer = $this->transfer($alice, $bob, $store);
        // A database-level failure of the audit INSERT (PostgreSQL aborts the transaction on it; without a savepoint
        // the commit silently rolled the transfer back).
        ActivityLog::creating(function (ActivityLog $log): void {
            if ($log->action === 'store.transfer') {
                $log->subject_id = 'not-a-number';
                $log->actor_user_id = 'not-a-number';
            }
        });

        app(OwnershipTransferService::class)->applyStore($transfer);

        $this->assertSame($bob->id, (int) $store->fresh()->user_id);
        $this->assertSame('accepted', $transfer->fresh()->status);
    }

    public function test_transfer_moves_account_prefixed_files_and_forgets_store_caches(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->store($alice, 'eng-files');
        $a = (int) $store->account_id;
        $receipt = "accounts/{$a}/expenses/{$store->id}/r1.pdf";
        $legacy = "expenses/{$store->id}/old.pdf";
        $pod = "accounts/{$a}/pod/{$store->id}/p1.jpg";
        Storage::disk('local')->put($receipt, 'R');
        Storage::disk('local')->put($legacy, 'L');
        Storage::disk('public')->put($pod, 'P');
        $e1 = $this->row('expenses', ['store_id' => $store->id, 'amount_minor' => 100, 'tax_minor' => 0, 'date' => now()->toDateString(), 'payment_source' => 'cash', 'receipt_path' => $receipt]);
        $e2 = $this->row('expenses', ['store_id' => $store->id, 'amount_minor' => 100, 'tax_minor' => 0, 'date' => now()->toDateString(), 'payment_source' => 'cash', 'receipt_path' => $legacy]);
        $order = $this->row('orders', ['store_id' => $store->id, 'type' => 'delivery', 'status' => 'delivered', 'customer_name' => 'C', 'phone_number' => '1', 'pod_photo' => $pod, 'created_at' => now()]);
        $version = StorefrontMenuCache::version((int) $store->id);
        Cache::put("gbp_perf:{$store->id}", ['views' => 9], 3600);

        app(OwnershipTransferService::class)->applyStore($this->transfer($alice, $bob, $store));
        $b = (int) $store->fresh()->account_id;

        $newReceipt = "accounts/{$b}/expenses/{$store->id}/r1.pdf";
        $this->assertSame($newReceipt, DB::table('expenses')->where('id', $e1)->value('receipt_path'));
        Storage::disk('local')->assertExists($newReceipt);
        Storage::disk('local')->assertMissing($receipt);
        $this->assertSame("accounts/{$b}/pod/{$store->id}/p1.jpg", DB::table('orders')->where('id', $order)->value('pod_photo'));
        Storage::disk('public')->assertExists("accounts/{$b}/pod/{$store->id}/p1.jpg");
        // Older uploads outside the account prefix stay where they are.
        $this->assertSame($legacy, DB::table('expenses')->where('id', $e2)->value('receipt_path'));
        Storage::disk('local')->assertExists($legacy);

        // P1-21 bug fix: the cached storefront (previous owner's pixel ids / gateways) and the Google data fetched
        // with the previous owner's connection were still served after the transfer.
        $this->assertNotSame($version, StorefrontMenuCache::version((int) $store->id));
        $this->assertNull(Cache::get("gbp_perf:{$store->id}"));
        $log = ActivityLog::where('action', 'store.transfer')->where('store_id', $store->id)->firstOrFail();
        $this->assertSame(2, $log->meta['reset']['files']);
    }
}
