<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Mail\StoreTransferOfferMail;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Tests\Support\ThrowingMailTransport;
use Tests\TestCase;

/**
 * WI-G7: ownership transfer — store between users, branch between the owner's
 * stores. Re-points the store + its user-keyed satellites, leaves user-level
 * rows, enforces consent + capacity, and audits.
 */
class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect()]);
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

    public function test_store_transfer_offer_then_accept_repoints_ownership_and_satellites(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'alice-cafe');
        StaffMember::forceCreate(['owner_id' => $alice->id, 'store_id' => $store->id, 'name' => 'Waiter', 'email' => 'w@x.com', 'role' => 'waiter', 'active' => 1, 'password' => bcrypt('x'), 'pin' => '1234']);
        StoreBackup::create(['store_id' => $store->id, 'user_id' => $alice->id, 'token' => StoreBackup::newToken(), 'status' => 'ready', 'include_pii' => false]);

        // Alice offers the store to Bob.
        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();
        $transfer = StoreTransfer::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('pending', $transfer->status);
        $this->assertSame($bob->id, $transfer->to_user_id);

        // Store still Alice's until accepted.
        $this->assertSame($alice->id, $store->fresh()->user_id);

        // Bob accepts.
        $this->actingAs($bob);
        $this->post(route('transfer.accept', $transfer->token))->assertRedirect();

        $this->assertSame($bob->id, $store->fresh()->user_id, 'store re-pointed to Bob');
        $this->assertSame($bob->id, StaffMember::where('store_id', $store->id)->value('owner_id'), 'staff owner re-pointed');
        $this->assertSame($bob->id, StoreBackup::where('store_id', $store->id)->value('user_id'), 'backup re-pointed');
        $this->assertSame('accepted', $transfer->fresh()->status);
    }

    public function test_old_owner_loses_access_after_transfer(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'ax');
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $alice->id,
            'to_user_id' => $bob->id, 'to_email' => $bob->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
        app(OwnershipTransferService::class)->applyStore($transfer);

        // Alice no longer owns it.
        $this->assertFalse(Store::where('id', $store->id)->where('user_id', $alice->id)->exists());
        $this->assertTrue(Store::where('id', $store->id)->where('user_id', $bob->id)->exists());
    }

    public function test_expired_offer_cannot_be_accepted(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'exp');
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $alice->id,
            'to_user_id' => $bob->id, 'to_email' => $bob->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($bob)->post(route('transfer.accept', $transfer->token))->assertStatus(410);
        $this->assertSame($alice->id, $store->fresh()->user_id, 'expired offer leaves ownership unchanged');
    }

    public function test_non_recipient_cannot_accept(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $mallory = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'mx');
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $alice->id,
            'to_user_id' => $bob->id, 'to_email' => $bob->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($mallory)->post(route('transfer.accept', $transfer->token))->assertNotFound();
        $this->assertSame($alice->id, $store->fresh()->user_id);
    }

    public function test_transfer_page_renders_and_offer_emails_the_recipient(): void
    {
        Mail::fake();
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1, 'email' => 'bob-transfer@x.com']);
        $store = $this->store($alice, 'ui-store');

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);

        $this->get(route('transfer.index'))->assertOk()->assertSeeHtml(route('transfer.store.offer'));

        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();
        Mail::assertQueued(StoreTransferOfferMail::class, fn ($m) => $m->afterCommit === true);
    }

    /** P6-194: offer saved, failure logged, sender told to share the link. */
    public function test_offer_mail_failure_is_logged_and_the_sender_is_told(): void
    {
        ThrowingMailTransport::install();
        Log::spy();
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1, 'email' => 'bob-deadsmtp@x.com']);
        $store = $this->store($alice, 'dead-smtp-store');

        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);

        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($w) => str_contains($w, route('transfer.index')))
            ->assertSessionMissing('success');
        $this->assertSame(1, StoreTransfer::where('store_id', $store->id)->where('status', 'pending')->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '[ownership-transfer] offer mail not sent'));
    }

    public function test_superadmin_force_transfer_needs_no_accept(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $bob = User::factory()->create(['status' => 1]);
        $store = $this->store($alice, 'force');
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $alice->id,
            'to_user_id' => $bob->id, 'to_email' => $bob->email, 'store_id' => $store->id,
            'status' => 'pending', 'by_superadmin' => true,
        ]);
        app(OwnershipTransferService::class)->applyStore($transfer);

        $this->assertSame($bob->id, $store->fresh()->user_id);
        $this->assertSame('forced', $transfer->fresh()->status);
    }

    public function test_branch_moves_between_owner_stores(): void
    {
        $alice = User::factory()->create(['status' => 1]);
        $a = $this->store($alice, 'ba');
        $b = $this->store($alice, 'bb');
        $branch = Branch::create(['store_id' => $a->id, 'name' => 'Downtown']);

        $this->actingAs($alice);
        session(['dashboard_store_id' => $a->id]);
        $this->post(route('transfer.branch.move'), ['branch_id' => $branch->id, 'target_store_id' => $b->id])->assertRedirect();

        $this->assertSame($b->id, $branch->fresh()->store_id, 'branch re-pointed to store B');
    }
}
