<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P0-8: ownership backstop — a PostPolicy that denies cross-tenant management,
 * plus an opt-in owner scope. (No global scope: storefront is public.)
 */
class OwnershipBackstopTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['status' => 1, 'email_verified_at' => now()], $attrs));
    }

    private function makePostFor(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234',
        ]);
    }

    public function test_owner_can_manage_own_restaurant_but_not_others(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $postA = $this->makePostFor($a);

        $this->assertTrue(Gate::forUser($a)->allows('update', $postA));
        $this->assertTrue(Gate::forUser($a)->allows('delete', $postA));
        $this->assertFalse(Gate::forUser($b)->allows('update', $postA));
        $this->assertFalse(Gate::forUser($b)->allows('view', $postA));
    }

    public function test_admin_bypasses_ownership(): void
    {
        $admin = $this->makeUser(['user_type' => 'admin']);
        $owner = $this->makeUser();
        $store = $this->makePostFor($owner);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $store));
    }

    public function test_owned_scope_filters_to_current_owner(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->makePostFor($a);
        $this->makePostFor($a);
        $this->makePostFor($b);

        $this->actingAs($a);
        $ids = Store::ownedByCurrentOwner()->pluck('user_id')->unique()->values()->all();
        $this->assertSame([$a->id], $ids);
        $this->assertCount(2, Store::ownedByCurrentOwner()->get());
    }

    public function test_public_visibility_is_not_scoped_away(): void
    {
        // No global scope: an unauthenticated context still sees all restaurants.
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->makePostFor($a);
        $this->makePostFor($b);

        $this->assertSame(2, Store::count(), 'public/unscoped query must see every restaurant');
    }
}
