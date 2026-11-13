<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\DomainVerifier;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/** P6-241: a verified custom domain is released when its DNS lapses, when the store removes it, or the store is deleted. */
class DomainReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => 's'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    private function verified(Store $store, string $host): StoreDomain
    {
        $row = StoreDomains::requestCustom($store, $host);
        StoreDomains::markVerified($row);

        return $row->refresh();
    }

    /** A verifier whose stillPointed() answer the test sets. */
    private function dns(?bool $pointed): DomainVerifier
    {
        return new class($pointed) extends DomainVerifier
        {
            public function __construct(private ?bool $pointed) {}

            public function stillPointed(string $domain, string $token): ?bool
            {
                return $this->pointed;
            }
        };
    }

    public function test_lapsed_domain_is_released_after_the_window_and_a_new_store_can_claim_it(): void
    {
        config(['hosts.release_after_days' => 14]);
        $old = $this->store();
        $row = $this->verified($old, 'lapsed-shop.example');
        $this->assertTrue((bool) $row->is_primary);
        $this->assertSame('taken', StoreDomains::customHostProblem('lapsed-shop.example'));

        $this->assertSame('failing', StoreDomains::recheck($row, $this->dns(false)));
        $this->assertNotNull($row->fresh()->dns_failing_since);

        $this->travel(13)->days();
        $this->assertSame('failing', StoreDomains::recheck($row->fresh(), $this->dns(false)), 'still inside the window');
        $this->assertSame('taken', StoreDomains::customHostProblem('lapsed-shop.example'));

        $this->travel(2)->days();
        $this->assertSame('released', StoreDomains::recheck($row->fresh(), $this->dns(false)));
        $this->assertNull(StoreDomain::query()->withoutGlobalScopes()->find($row->id));
        $this->assertNull(StoreDomains::customHostProblem('lapsed-shop.example'), 'host is free');
        $this->assertSame(0, StoreDomain::query()->withoutGlobalScopes()->where('store_id', $old->id)->where('is_primary', true)->count());

        // The new owner claims it; it serves only after their own verification.
        $new = $this->store();
        $claim = StoreDomains::requestCustom($new, 'lapsed-shop.example');
        $this->assertFalse($claim->isVerified());
        StoreDomains::markVerified($claim);
        $this->assertSame($new->id, (int) StoreDomain::query()->withoutGlobalScopes()->where('host', 'lapsed-shop.example')->value('store_id'));
    }

    public function test_a_passing_check_resets_the_clock_and_a_resolver_outage_changes_nothing(): void
    {
        $row = $this->verified($this->store(), 'flaky-shop.example');
        StoreDomains::recheck($row, $this->dns(false));
        $this->travel(20)->days();
        $this->assertSame('skipped', StoreDomains::recheck($row->fresh(), $this->dns(null)), 'resolver down: no release');
        $this->assertNotNull(StoreDomain::query()->withoutGlobalScopes()->find($row->id));
        $this->assertSame('ok', StoreDomains::recheck($row->fresh(), $this->dns(true)));
        $this->assertNull($row->fresh()->dns_failing_since);
    }

    public function test_command_runs_the_recheck(): void
    {
        config(['hosts.release_after_days' => 1]);
        $row = $this->verified($this->store(), 'gone-shop.example');
        $this->app->instance(DomainVerifier::class, $this->dns(false));
        $this->artisan('storefront:domains-recheck')->assertSuccessful();
        $this->travel(2)->days();
        $this->artisan('storefront:domains-recheck')->expectsOutputToContain('released 1')->assertSuccessful();
        $this->assertNull(StoreDomain::query()->withoutGlobalScopes()->find($row->id));
    }

    public function test_deleting_the_store_releases_its_domains(): void
    {
        $store = $this->store();
        $this->verified($store, 'closed-shop.example');
        $store->delete();
        $this->assertSame(0, StoreDomain::query()->withoutGlobalScopes()->where('host', 'closed-shop.example')->count());
        $this->assertNull(StoreDomains::customHostProblem('closed-shop.example'));
    }

    public function test_removing_the_primary_domain_releases_it_and_falls_back_to_the_path(): void
    {
        $store = $this->store();
        $row = $this->verified($store, 'removed-shop.example');
        StoreDomains::release($row, 'removed');
        $this->assertNull(StoreDomains::customHostProblem('removed-shop.example'));
        $this->assertSame(0, StoreDomain::query()->withoutGlobalScopes()->where('store_id', $store->id)->count());
    }

    /** Ownership change: the domain stops serving and the new owner gets the full grace before anyone else may claim it. */
    public function test_transferred_store_domain_cannot_be_taken_over_at_once(): void
    {
        $store = $this->store();
        $row = $this->verified($store, 'sold-shop.example');
        StoreDomain::query()->withoutGlobalScopes()->whereKey($row->id)->update(['created_at' => now()->subYear()]);

        $ctx = new \Juwri\Core\Contracts\Tenancy\StoreTransferContext(
            storeId: (int) $store->id, fromUserId: (int) $store->user_id, toUserId: 999, fromAccountId: (int) $store->account_id, toAccountId: (int) $store->account_id,
        );
        \Juwri\Modules\Storefront\Providers\StorefrontStoreData::unverifyDomains(\Illuminate\Support\Facades\DB::connection(), $ctx);

        $fresh = $row->fresh();
        $this->assertFalse($fresh->isVerified());
        $this->assertFalse((bool) $fresh->is_primary);
        $this->assertSame('taken', StoreDomains::customHostProblem('sold-shop.example'), 'held for the new owner during the grace');
    }
}
