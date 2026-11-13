<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/** P1-20 follow-ups: owner claims/releases a platform subdomain; unverified requests cannot squat on a host. */
class OwnerSubdomainAndSquattingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The assertions read English strings: pin the locale (the install default may be another language).
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, Locale::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 's'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$user, $store];
    }

    private function pending(Store $store, string $host, ?\DateTimeInterface $createdAt = null): StoreDomain
    {
        $row = StoreDomains::requestCustom($store, $host);
        if ($createdAt) {
            StoreDomain::query()->withoutGlobalScopes()->whereKey($row->id)->update(['created_at' => $createdAt]);
        }

        return $row;
    }

    public function test_owner_claims_a_subdomain_and_page_shows_it(): void
    {
        [$user, $store] = $this->store();

        $this->actingAs($user)->post("/stores/{$store->id}/domains/subdomain", ['subdomain' => 'Yourbrand'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $host = 'yourbrand.'.HostMap::storefrontHost();
        $row = StoreDomain::where('store_id', $store->id)->firstOrFail();
        $this->assertSame($host, $row->host);
        $this->assertSame(StoreDomain::TYPE_SUBDOMAIN, $row->type);
        $this->assertTrue($row->isVerified());

        $this->actingAs($user)->get("/stores/{$store->id}/domains")->assertOk()
            ->assertSee($host)->assertSee(___('Release subdomain'));
    }

    public function test_reserved_taken_invalid_and_second_claims_are_refused(): void
    {
        [$user, $store] = $this->store();
        [, $other] = $this->store();
        StoreDomains::claimSubdomain($other, 'acme');
        $url = "/stores/{$store->id}/domains/subdomain";

        $this->actingAs($user)->post($url, ['subdomain' => 'www'])->assertSessionHas('error', ___('That name is reserved. Choose another one.'));
        $this->actingAs($user)->post($url, ['subdomain' => 'ACME'])->assertSessionHas('error', ___('That subdomain is already taken.'));
        $this->actingAs($user)->post($url, ['subdomain' => 'a--b'])->assertSessionHas('error');
        $this->actingAs($user)->post($url, ['subdomain' => '-bad'])->assertSessionHas('error');
        $this->assertSame(0, StoreDomain::where('store_id', $store->id)->count());

        $this->actingAs($user)->post($url, ['subdomain' => 'mine'])->assertSessionHas('success');
        $this->actingAs($user)->post($url, ['subdomain' => 'mine2'])
            ->assertSessionHas('error', ___('Release your current subdomain before claiming another one.'));
        $this->assertSame(1, StoreDomain::where('store_id', $store->id)->count());
    }

    public function test_owner_releases_subdomain_and_another_store_can_claim_it(): void
    {
        [$user, $store] = $this->store();
        [, $other] = $this->store();
        $row = StoreDomains::claimSubdomain($store, 'freeme');

        $this->actingAs($user)->delete("/stores/{$store->id}/domains/{$row->id}")->assertRedirect();
        $this->assertNull(StoreDomain::query()->withoutGlobalScopes()->find($row->id));
        $this->assertNull(StoreDomains::subdomainProblem('freeme'));
        $this->assertSame('freeme.'.HostMap::storefrontHost(), StoreDomains::claimSubdomain($other, 'freeme')->host);
    }

    public function test_owner_cannot_claim_for_a_store_they_do_not_own(): void
    {
        [$user] = $this->store();
        [, $foreign] = $this->store();

        $this->actingAs($user)->post("/stores/{$foreign->id}/domains/subdomain", ['subdomain' => 'sneaky'])->assertNotFound();
        $this->assertSame(0, StoreDomain::where('store_id', $foreign->id)->count());
    }

    public function test_host_verified_by_another_store_is_never_available(): void
    {
        [, $a] = $this->store();
        [$userB, $b] = $this->store();
        $row = $this->pending($a, 'menu.shared.com', now()->subDays(30));
        StoreDomains::markVerified($row);

        $this->assertSame('taken', StoreDomains::customHostProblem('menu.shared.com'));
        $this->actingAs($userB)->postJson("/stores/{$b->id}/domains", ['domain' => 'menu.shared.com'])->assertStatus(409);
        $this->assertSame(1, StoreDomain::where('host', 'menu.shared.com')->count());
    }

    public function test_fresh_pending_request_holds_the_host_during_grace(): void
    {
        config(['hosts.pending_grace_hours' => 72]);
        [, $a] = $this->store();
        [$userB, $b] = $this->store();
        $this->pending($a, 'menu.squat.com', now()->subHours(71));

        $this->actingAs($userB)->postJson("/stores/{$b->id}/domains", ['domain' => 'menu.squat.com'])->assertStatus(409);
        $this->assertSame($a->id, StoreDomain::where('host', 'menu.squat.com')->value('store_id'));
    }

    public function test_expired_pending_request_is_replaced_by_another_store(): void
    {
        config(['hosts.pending_grace_hours' => 72]);
        [, $a] = $this->store();
        [$userB, $b] = $this->store();
        $this->pending($a, 'menu.stale.com', now()->subHours(73));

        $this->actingAs($userB)->postJson("/stores/{$b->id}/domains", ['domain' => 'menu.stale.com'])->assertCreated();
        $rows = StoreDomain::where('host', 'menu.stale.com')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($b->id, $rows->first()->store_id);
        $this->assertFalse($rows->first()->isVerified());
    }
}
