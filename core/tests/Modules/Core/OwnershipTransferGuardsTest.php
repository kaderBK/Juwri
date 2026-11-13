<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

/**
 * HPMS E2 — ownership-transfer guards: cooldown, unsettled-billing, verified
 * recipient, plus the superadmin-force exemption.
 */
class OwnershipTransferGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(User $u, string $slug): Store
    {
        return Store::create([
            'user_id' => $u->id, 'title' => 'Store '.$slug, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function accepted(Store $store, User $from, User $to, \DateTimeInterface $respondedAt): StoreTransfer
    {
        return StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $from->id,
            'to_user_id' => $to->id, 'to_email' => $to->email, 'store_id' => $store->id,
            'status' => 'accepted', 'responded_at' => $respondedAt,
        ]);
    }

    public function test_happy_path_offer_succeeds(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'happy');

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();

        $this->assertSame('pending', StoreTransfer::where('store_id', $store->id)->value('status'));
    }

    public function test_cooldown_blocks_a_fresh_offer(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $carol = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'cool');
        // A transfer accepted 2 days ago is inside the default 14-day cooldown.
        $this->accepted($store, $carol, $alice, now()->subDays(2));

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->from(route('transfer.index'))->post(route('transfer.store.offer'), ['to_email' => $bob->email])
            ->assertRedirect(route('transfer.index'))->assertSessionHas('error');

        $this->assertSame(0, StoreTransfer::where('store_id', $store->id)->where('status', 'pending')->count());
    }

    public function test_cooldown_zero_disables_the_guard(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $carol = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'coolzero');
        StoreOption::updateStoreOption($store->id, 'transfer_cooldown_days', '0');
        $this->accepted($store, $carol, $alice, now()->subDays(1));

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();

        $this->assertSame(1, StoreTransfer::where('store_id', $store->id)->where('status', 'pending')->count());
    }

    public function test_billing_guard_blocks_when_a_charge_is_unsettled(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'billing');
        // A pending platform charge on Alice's account (billing/details are object
        // casts — passed here as raw arrays).
        Transaction::create([
            'product_name' => 'Plan', 'user_id' => $alice->id, 'status' => Transaction::STATUS_PENDING,
            'amount' => 20, 'currency_code' => 'USD', 'billing' => ['name' => 'Alice'], 'details' => ['n' => 1],
        ]);

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->from(route('transfer.index'))->post(route('transfer.store.offer'), ['to_email' => $bob->email])
            ->assertRedirect(route('transfer.index'))->assertSessionHas('error');

        $this->assertSame(0, StoreTransfer::where('store_id', $store->id)->count());

        // A settled (success) charge does not block.
        Transaction::query()->update(['status' => Transaction::STATUS_SUCCESS]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();
        $this->assertSame(1, StoreTransfer::where('store_id', $store->id)->count());
    }

    public function test_unverified_recipient_is_refused(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->unverified()->create(['status' => 1]);
        $store = $this->store($alice, 'unver');

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertStatus(422);

        $this->assertSame(0, StoreTransfer::where('store_id', $store->id)->count());
    }

    public function test_superadmin_force_is_exempt_from_cooldown(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $carol = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'forceexempt');
        // Cooldown is active for the owner-offer path...
        $this->accepted($store, $carol, $alice, now()->subDay());
        $this->assertTrue(app(OwnershipTransferService::class)->cooldownActive($store));

        // ...but the superadmin force path never consults it.
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $alice->id,
            'to_user_id' => $bob->id, 'to_email' => $bob->email, 'store_id' => $store->id,
            'status' => 'pending', 'by_superadmin' => true,
        ]);
        app(OwnershipTransferService::class)->applyStore($transfer);

        $this->assertSame($bob->id, $store->fresh()->user_id);
        $this->assertSame('forced', $transfer->fresh()->status);
    }

    public function test_save_settings_persists_cooldown(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'setcool');

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.settings'), ['transfer_cooldown_days' => '30'])->assertRedirect();

        $this->assertSame('30', (string) StoreOption::getStoreOption($store->id, 'transfer_cooldown_days'));
    }
}
