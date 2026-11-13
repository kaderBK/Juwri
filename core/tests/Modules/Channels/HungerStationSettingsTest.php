<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

class HungerStationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(User $o): Store
    {
        $t = 'R'.Str::random(5);

        return Store::create(['user_id' => $o->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_connect_stores_encrypted_credentials(): void
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($o);
        $this->actingAs($o)->postJson(route('hungerstation.connect', $r->id), ['client_id' => 'cid', 'client_secret' => 'sec', 'chain_id' => 'C1'])->assertOk();
        $cred = Credential::where('store_id', $r->id)->where('feature', 'hungerstation')->first();
        $this->assertSame('cid', $cred->secrets['client_id']);
        // stored encrypted: raw column not plaintext
        $raw = \DB::table('credentials')->where('id', $cred->id)->value('secrets');
        $this->assertStringNotContainsString('cid', $raw);
    }

    public function test_map_branch_and_scoping(): void
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($o);
        $b = Branch::where('store_id', $r->id)->first();
        $this->actingAs($o)->postJson(route('hungerstation.mapBranch', $r->id), ['branch_id' => $b->id, 'hs_vendor_id' => 'V9', 'hs_chain_id' => 'C1'])->assertOk();
        $this->assertSame('V9', $b->fresh()->hs_vendor_id);
        // foreign owner blocked
        $o2 = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($o2)->postJson(route('hungerstation.connect', $r->id), ['client_id' => 'x', 'client_secret' => 'y'])->assertStatus(404);
    }
}
