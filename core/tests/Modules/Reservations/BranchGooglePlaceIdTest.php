<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P5-25: a branch's own Google place ID — optional, one branch per place within a store. */
class BranchGooglePlaceIdTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create();
        $t = 'R '.Str::random(5);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1']);
    }

    public function test_place_ids_are_optional_and_unique_within_a_store(): void
    {
        $a = $this->store();
        $b = $this->store();
        $mk = fn (Store $s, ?string $place) => Branch::create(['store_id' => $s->id, 'name' => 'B '.Str::random(4), 'active' => 1, 'timezone' => 'UTC', 'google_place_id' => $place]);
        $mk($a, null);
        $mk($a, null); // several branches without one
        $this->assertSame('ChIJ-a1', $mk($a, 'ChIJ-a1')->fresh()->google_place_id);
        $mk($a, 'ChIJ-a2');
        $mk($b, 'ChIJ-a1'); // another store's branch is its own row
        $this->expectException(UniqueConstraintViolationException::class);
        $mk($a, 'ChIJ-a1');
    }
}
