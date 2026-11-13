<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Editions;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Juwri\Core\Editions\Community\CommunityStoreLimits;
use Juwri\Core\Editions\Limits\LimitDecision;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Editions\Limits\StoreLimitReached;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataImporter;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\CommunityTestCase;
use Tests\Support\UnlimitedStoreLimits;

/**
 * Owner 2026-10-05 (DECISIONS "Community limits + self-hosted Chain licence"): the Community default — one store of
 * each store type per business, each with up to three branches, unlimited staff — holds on EVERY creation path, server
 * side; over-limit data from before keeps working; the message points to the Chain licence / juwri Cloud (no price).
 * Runs as Community (no licence) with the app's real StoreLimitPolicy binding (enforcesStoreLimits).
 */
class StoreLimitsCommunityTest extends CommunityTestCase
{
    use RefreshDatabase;

    protected function enforcesStoreLimits(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, ?string $type = 'restaurant', ?string $title = null): Store
    {
        $title ??= 'S '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'store_type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branch(Store $store, string $name = 'B'): Branch
    {
        return Branch::create(['store_id' => $store->id, 'name' => $name.' '.Str::random(4), 'active' => true]);
    }

    private function storesOf(User $owner): int
    {
        return Store::withoutGlobalScopes()->where('user_id', $owner->id)->count();
    }

    public function test_the_community_binding_is_the_default(): void
    {
        $this->assertInstanceOf(CommunityStoreLimits::class, app(StoreLimitPolicy::class));
        $limits = app(StoreLimitGuard::class)->limits();
        $this->assertSame(['edition' => 'community', 'stores_per_type' => 1, 'branches_per_store' => 3, 'branches_total' => null], $limits->toArray());
    }

    public function test_one_store_of_each_type_on_the_model_itself(): void
    {
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $this->store($owner, 'retail'); // another type: allowed
        $this->store($owner, 'pharmacy');

        try {
            $this->store($owner, 'restaurant');
            $this->fail('a second restaurant must be refused');
        } catch (StoreLimitReached $e) {
            $this->assertSame(LimitDecision::STORES_PER_TYPE, $e->decision->reason);
            $this->assertStringContainsString('Chain licence', $e->getMessage());
            $this->assertStringContainsString('juwri Cloud', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/\d+\s*(SAR|AED|USD|EGP)|SAR\s*\d/', $e->getMessage(), 'no price in product text');
        }
        // An empty / unknown store_type counts as the default vertical (restaurant).
        $this->expectException(StoreLimitReached::class);
        $this->store($owner, null);
    }

    public function test_up_to_three_branches_per_store_on_the_model_itself(): void
    {
        $store = $this->store($this->owner()); // its Main branch = 1
        $this->branch($store);
        $this->branch($store);
        $this->assertSame(3, Branch::where('store_id', $store->id)->count());

        $this->expectException(StoreLimitReached::class);
        $this->branch($store);
    }

    public function test_the_wizard_refuses_a_second_store_of_a_type_with_the_message(): void
    {
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $owner->refresh();

        $res = $this->actingAs($owner)->from(route('stores.create'))->post(route('stores.store'), [
            'store_type' => 'restaurant', 'color' => '#111111', 'title' => 'Second', 'description' => 'x', 'address' => 'x',
        ]);
        $res->assertRedirect(route('stores.create'));
        $res->assertSessionHas('store_limit', fn (array $l) => $l['reason'] === LimitDecision::STORES_PER_TYPE && str_contains($l['message'], 'Chain licence'));
        $res->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(1, $this->storesOf($owner));

        // The create page keeps the message on screen (the toast fades).
        $this->withSession(['store_limit' => ['reason' => 'stores_per_type', 'message' => 'LIMIT-TEXT']])
            ->get(route('stores.create'))->assertSee('data-store-limit="stores_per_type"', false)->assertSee('LIMIT-TEXT');

        // Another type passes the edition gate (it fails later only on the wizard's own required fields).
        $this->actingAs($owner)->from(route('stores.create'))->post(route('stores.store'), [
            'store_type' => 'retail', 'color' => '#111111', 'title' => 'Shop', 'description' => 'x', 'address' => 'x',
        ])->assertSessionMissing('store_limit');
    }

    public function test_the_branches_page_refuses_a_fourth_branch(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->branch($store);
        $owner->refresh();

        $this->actingAs($owner)->post(route('branches.store'), ['store_id' => $store->id, 'name' => 'Third'])
            ->assertSessionMissing('store_limit');
        $this->assertSame(3, Branch::where('store_id', $store->id)->count());

        $res = $this->actingAs($owner)->post(route('branches.store'), ['store_id' => $store->id, 'name' => 'Fourth']);
        $res->assertSessionHas('store_limit', fn (array $l) => $l['reason'] === LimitDecision::BRANCHES_PER_STORE && $l['limit'] === 3);
        $res->assertSessionHas('error');
        $this->assertSame(3, Branch::where('store_id', $store->id)->count());
    }

    public function test_clone_and_duplicate_and_convert_are_held_to_the_same_rule(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'restaurant');
        $retail = $this->store($owner, 'retail');
        $owner->refresh();

        $this->actingAs($owner)->postJson(route('stores.clone', $store))
            ->assertStatus(422)->assertJson(['code' => 'store_limit']);
        $this->actingAs($owner)->postJson(route('stores.duplicateVertical', $store), ['store_type' => 'retail', 'store_template' => 'shelf'])
            ->assertStatus(422);
        $this->actingAs($owner)->postJson(route('stores.convert', $retail), ['store_type' => 'restaurant', 'store_template' => 'default'])
            ->assertStatus(422);
        $this->assertSame(2, $this->storesOf($owner));
        $this->assertSame('retail', $retail->fresh()->store_type);

        // A type the business does not have yet is fine: duplicate as a pharmacy, convert the retail store to clothing.
        $this->actingAs($owner)->postJson(route('stores.duplicateVertical', $retail), ['store_type' => 'pharmacy'])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.convert', $retail), ['store_type' => 'clothing'])->assertOk();
        $this->assertSame('clothing', $retail->fresh()->store_type);
        // Converting to the same type it already is (idempotent) is not "another" store.
        $this->actingAs($owner)->postJson(route('stores.convert', $retail), ['store_type' => 'clothing'])->assertOk();
    }

    public function test_the_model_refuses_a_type_change_into_a_used_type(): void
    {
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $retail = $this->store($owner, 'retail');

        $this->expectException(StoreLimitReached::class);
        $retail->update(['store_type' => 'restaurant']);
    }

    public function test_ai_studio_publish_is_refused_at_the_limit(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $owner->refresh();
        $session = StudioSession::create(['guest_token' => Str::random(48), 'output_type' => 'online', 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($session->storagePath('draft.json'), json_encode(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Burger', 'description' => 'Beef', 'price' => 9]]]]]));

        $req = Request::create('/studio/publish', 'POST');
        $req->cookies->set('studio_guest', $session->guest_token);
        $req->setUserResolver(fn () => $owner);
        $resp = (new StudioController)->publish($req);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('store_limit', $resp->getData(true)['code']);
        $this->assertSame(1, $this->storesOf($owner));
        $this->assertNotSame('published', $session->fresh()->status, 'nothing moved on a refusal');
    }

    public function test_ownership_and_branch_transfers_cannot_grow_past_the_limit(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $aliceRestaurant = $this->store($alice, 'restaurant');
        $bobRestaurant = $this->store($bob, 'restaurant');
        $bobRetail = $this->store($bob, 'retail');
        $this->branch($bobRestaurant);
        $this->branch($bobRestaurant); // Bob's restaurant: 3 branches

        $storeTransfer = StoreTransfer::create(['kind' => 'store', 'token' => StoreTransfer::newToken(), 'to_email' => $bob->email, 'expires_at' => now()->addDay(), 'store_id' => $aliceRestaurant->id, 'from_user_id' => $alice->id, 'to_user_id' => $bob->id, 'status' => 'pending']);
        try {
            app(OwnershipTransferService::class)->applyStore($storeTransfer);
            $this->fail('Bob already has a restaurant');
        } catch (StoreLimitReached) {
        }
        $this->assertSame($alice->id, (int) $aliceRestaurant->fresh()->user_id, 'the transfer rolled back');

        $retailBranch = Branch::where('store_id', $bobRetail->id)->firstOrFail();
        $this->branch($bobRetail); // P6-341: a store's only branch cannot move at all
        $branchTransfer = StoreTransfer::create(['kind' => 'branch', 'token' => StoreTransfer::newToken(), 'to_email' => $bob->email, 'expires_at' => now()->addDay(), 'store_id' => $bobRetail->id, 'branch_id' => $retailBranch->id, 'target_store_id' => $bobRestaurant->id, 'from_user_id' => $bob->id, 'to_user_id' => $bob->id, 'status' => 'pending']);
        try {
            app(OwnershipTransferService::class)->applyBranch($branchTransfer);
            $this->fail('the target store already has 3 branches');
        } catch (StoreLimitReached $e) {
            $this->assertSame(LimitDecision::BRANCHES_PER_STORE, $e->decision->reason);
        }
        $this->assertSame($bobRetail->id, (int) $retailBranch->fresh()->store_id);
    }

    public function test_an_account_import_over_the_limit_is_refused_and_writes_nothing(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = $this->owner();
        StoreLimitGuard::bypass(function () use ($owner): void { // data from a bigger install (e.g. juwri Cloud)
            $this->store($owner, 'restaurant');
            $this->store($owner, 'restaurant');
        });
        $owner->refresh();
        $account = (int) $owner->account_id;
        $zip = storage_path('framework/testing/limits-'.Str::lower(Str::random(8)).'.zip');
        @mkdir(dirname($zip), 0775, true);
        try {
            app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::account($account), $zip);
            app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::account($account));

            try {
                app(StoreDataImporter::class)->import(DB::connection(), $zip);
                $this->fail('two restaurants do not fit the Community limits');
            } catch (StoreLimitReached $e) {
                $this->assertSame(LimitDecision::STORES_PER_TYPE, $e->decision->reason);
            }
            $this->assertFalse(DB::table('accounts')->where('id', $account)->exists(), 'nothing restored');

            // Under a licence that allows it the same bundle restores.
            app()->instance(StoreLimitPolicy::class, new UnlimitedStoreLimits);
            app(StoreDataImporter::class)->import(DB::connection(), $zip);
            $this->assertSame(2, Store::withoutGlobalScopes()->where('account_id', $account)->count());
        } finally {
            @unlink($zip);
        }
    }

    public function test_store_sets_are_checked_store_by_store(): void
    {
        $guard = app(StoreLimitGuard::class);
        $this->assertTrue($guard->checkStoreSet(null, [['type' => 'restaurant', 'branches' => 3], ['type' => 'retail', 'branches' => 1]])->allowed);
        $this->assertSame(LimitDecision::BRANCHES_PER_STORE, $guard->checkStoreSet(null, [['type' => 'restaurant', 'branches' => 4]])->reason);
        $this->assertSame(LimitDecision::STORES_PER_TYPE, $guard->checkStoreSet(null, [['type' => 'retail', 'branches' => 1], ['type' => 'retail', 'branches' => 1]])->reason);
        $this->assertSame(LimitDecision::STORES_PER_TYPE, $guard->checkStoreSet(null, [['type' => null, 'branches' => 1], ['type' => 'restaurant', 'branches' => 1]])->reason, 'no type = the default vertical');
    }

    public function test_over_limit_data_from_before_keeps_working(): void
    {
        $owner = $this->owner();
        [$first, $second] = StoreLimitGuard::bypass(function () use ($owner): array {
            $first = $this->store($owner, 'restaurant', 'First');
            $second = $this->store($owner, 'restaurant', 'Second');
            foreach (range(1, 4) as $i) {
                $this->branch($first, 'Extra');
            }

            return [$first, $second];
        });
        $this->assertSame(5, Branch::where('store_id', $first->id)->count());

        // Read and write: editing stores and branches is never limited.
        $second->update(['title' => 'Second renamed']);
        $this->assertSame('Second renamed', $second->fresh()->title);
        $branch = Branch::where('store_id', $first->id)->latest('id')->firstOrFail();
        $branch->update(['name' => 'Renamed branch', 'active' => false]);
        $this->assertSame('Renamed branch', $branch->fresh()->name);
        $this->actingAs($owner->fresh())->get(route('branches.index', ['store_id' => $first->id]))->assertOk()->assertSee('Renamed branch');

        // Only growth is refused.
        $this->expectException(StoreLimitReached::class);
        $this->branch($first);
    }

    public function test_the_store_list_says_what_the_community_edition_includes_instead_of_unlimited(): void
    {
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $this->actingAs($owner->fresh())->get(route('stores.index'))->assertOk()
            ->assertSee('data-edition-limits', false)
            ->assertSee('One store of each store type, up to 3 branches each')
            ->assertDontSee('Unlimited store slots');
    }

    public function test_seeders_run_without_the_limits(): void
    {
        $owner = $this->owner();
        $this->store($owner, 'restaurant');
        $events = app('events');
        $events->dispatch(new CommandStarting('db:seed', new ArrayInput([]), new NullOutput));
        try {
            $this->store($owner, 'restaurant', 'Demo second');
        } finally {
            $events->dispatch(new CommandFinished('db:seed', new ArrayInput([]), new NullOutput, 0));
        }
        $this->assertSame(2, $this->storesOf($owner));
        $this->assertFalse(StoreLimitGuard::bypassed(), 'the bypass ends with the command');

        $this->expectException(StoreLimitReached::class);
        $this->store($owner, 'restaurant', 'Owner third');
    }

    public function test_the_message_is_translated(): void
    {
        $decision = app(StoreLimitGuard::class)->checkStoreSet(null, [['type' => 'retail', 'branches' => 1], ['type' => 'retail', 'branches' => 1]]);
        $english = $decision->message();
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            config(['app.locale' => $locale]);
            $text = $decision->message();
            $this->assertNotSame($english, $text, "{$locale}: the limit message is translated");
            $this->assertStringContainsString('Chain', $text, "{$locale}: names the Chain licence");
        }
    }
}
