<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\TestCase;

/**
 * P0a — effective_public_url() is the outbound counterpart to
 * StoreDomain::resolve(). It is shared infrastructure consumed by the
 * google-suite (JSON-LD urls, Wallet origins, central OAuth bounce) and
 * google-partner (order-link + merchant feeds) packs, so it lives on main
 * before any lane worktree forks.
 */
class EffectivePublicUrlTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_plain_store_uses_platform_slug_route(): void
    {
        $store = $this->store();

        $this->assertSame(route('publicView', $store->slug), effective_public_url($store));
    }

    public function test_verified_custom_domain_returns_that_origin(): void
    {
        $store = $this->store();
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'menu.thethai.com', 'verification_token' => 't', 'verified' => true]);

        $this->assertSame('https://menu.thethai.com', effective_public_url($store));
    }

    public function test_unverified_domain_is_ignored_falls_back_to_platform(): void
    {
        $store = $this->store();
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'pending.com', 'verification_token' => 't', 'verified' => false]);

        $this->assertSame(route('publicView', $store->slug), effective_public_url($store));
    }

    public function test_verified_domain_wins_over_unverified_and_is_stable(): void
    {
        $store = $this->store();
        // insertion order: verified first, then an unverified — verified must win regardless.
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'live.com', 'verification_token' => 't', 'verified' => true]);
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'draft.com', 'verification_token' => 't', 'verified' => false]);

        $this->assertSame('https://live.com', effective_public_url($store));
    }

    public function test_accepts_raw_slug_string(): void
    {
        $store = $this->store();

        // no store_id known → cannot look up a custom domain → platform route by slug.
        $this->assertSame(route('publicView', $store->slug), effective_public_url($store->slug));
    }
}
