<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Verticals\Models\SkinProfile;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/** P6-74 per-store daily cap on public AI/SMS endpoints + P6-71 skin profile size cap and prune. */
class StoreDailyCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['abuse.store_daily_caps.ai' => 3, 'abuse.modes.store_cap' => 'enforce']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(): Store
    {
        return Store::factory()->create(['user_id' => User::factory()->create()->id, 'store_type' => 'cosmetic']);
    }

    private function scan(Store $s)
    {
        return $this->postJson(route('skin.scan', ['slug' => $s->slug]), ['image' => 'data:image/png;base64,AAAA']);
    }

    public function test_call_over_the_cap_is_refused_and_other_store_unaffected(): void
    {
        $a = $this->store();
        $b = $this->store();
        for ($i = 0; $i < 3; $i++) {
            $this->scan($a)->assertOk();
        }
        $this->scan($a)->assertStatus(429)->assertJsonPath('code', 'daily_cap_exceeded')->assertJsonStructure(['message']);
        $this->scan($b)->assertOk();
    }

    public function test_counter_resets_next_utc_day(): void
    {
        $a = $this->store();
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        for ($i = 0; $i < 3; $i++) {
            $this->scan($a)->assertOk();
        }
        $this->scan($a)->assertStatus(429);
        Carbon::setTestNow(Carbon::parse('2026-10-14 00:05:00', 'UTC'));
        $this->scan($a)->assertOk();
    }

    public function test_warn_mode_never_blocks_and_zero_is_unlimited(): void
    {
        $a = $this->store();
        config(['abuse.modes.store_cap' => 'warn']);
        for ($i = 0; $i < 5; $i++) {
            $this->scan($a)->assertOk();
        }
        config(['abuse.modes.store_cap' => 'enforce', 'abuse.store_daily_caps.ai' => 0]);
        for ($i = 0; $i < 5; $i++) {
            $this->scan($a)->assertOk();
        }
    }

    public function test_skin_profile_oversize_is_422_and_small_is_ok(): void
    {
        $a = $this->store();
        $big = ['routine' => [str_repeat('x', 17000)]];
        $this->postJson(route('skin.profile', ['slug' => $a->slug]), $big)->assertStatus(422)->assertJsonPath('code', 'payload_too_large');
        $this->postJson(route('skin.profile', ['slug' => $a->slug]), ['skin_age' => 30, 'routine' => ['a']])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_prune_deletes_only_old_anonymous_rows(): void
    {
        $a = $this->store();
        $old = SkinProfile::create(['store_id' => $a->id, 'session_token' => 'old']);
        $new = SkinProfile::create(['store_id' => $a->id, 'session_token' => 'new']);
        $kept = SkinProfile::create(['store_id' => $a->id, 'session_token' => 'cust', 'customer_id' => 1]);
        $ts = now()->subDays(31)->toDateTimeString();
        DB::table('skin_profiles')->whereIn('id', [$old->id, $kept->id])->update(['updated_at' => $ts]);

        Artisan::call('verticals:prune-skin-profiles');

        $this->assertNull(SkinProfile::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(SkinProfile::withoutGlobalScopes()->find($new->id));
        $this->assertNotNull(SkinProfile::withoutGlobalScopes()->find($kept->id));
    }

    public function test_body_store_id_is_ignored(): void
    {
        $a = $this->store();
        $b = $this->store();
        // Naming store B in the body must not count against B (route store A is the only attribution).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(route('skin.scan', ['slug' => $a->slug]), ['store_id' => $b->id])->assertOk();
        }
        $this->postJson(route('skin.scan', ['slug' => $a->slug]), ['store_id' => $b->id])->assertStatus(429);
        $this->scan($b)->assertOk();
    }

    public function test_per_ip_daily_sub_cap_blocks_one_address_not_the_store(): void
    {
        $a = $this->store();
        config(['abuse.store_daily_caps.ai' => 100, 'abuse.store_daily_ip_caps.ai' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
        $this->scan($a)->assertOk();
        $this->scan($a)->assertOk();
        $this->scan($a)->assertStatus(429)->assertJsonPath('code', 'daily_cap_exceeded');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8']);
        $this->scan($a)->assertOk();
    }

    public function test_ip_over_its_sub_cap_does_not_drain_the_store_cap(): void
    {
        $a = $this->store();
        config(['abuse.store_daily_caps.ai' => 3, 'abuse.store_daily_ip_caps.ai' => 1]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->scan($a)->assertOk();
        for ($i = 0; $i < 5; $i++) {
            $this->scan($a)->assertStatus(429);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $this->scan($a)->assertOk();
    }

    public function test_ip_sub_cap_is_per_store_not_global(): void
    {
        $a = $this->store();
        $b = $this->store();
        config(['abuse.store_daily_caps.ai' => 100, 'abuse.store_daily_ip_caps.ai' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->scan($a)->assertOk();
        $this->scan($a)->assertOk();
        $this->scan($a)->assertStatus(429);
        $this->scan($b)->assertOk();
    }

    public function test_logged_in_customer_is_counted_by_id_not_ip(): void
    {
        $a = $this->store();
        config(['abuse.store_daily_caps.ai' => 100, 'abuse.store_daily_ip_caps.ai' => 2]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $c1 = Customer::create(['store_id' => $a->id, 'name' => 'C One', 'phone' => UniquePhone::next('5')]);
        $c2 = Customer::create(['store_id' => $a->id, 'name' => 'C Two', 'phone' => UniquePhone::next('6')]);
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($c1, 'customer')->postJson(route('skin.scan', ['slug' => $a->slug]), [])->assertOk();
        }
        $this->actingAs($c1, 'customer')->postJson(route('skin.scan', ['slug' => $a->slug]), [])->assertStatus(429);
        $this->actingAs($c2, 'customer')->postJson(route('skin.scan', ['slug' => $a->slug]), [])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->scan($a)->assertOk();
    }

    public function test_all_digit_slug_resolves_as_slug_not_id(): void
    {
        $a = $this->store();
        $digits = Store::factory()->create(['user_id' => User::factory()->create()->id, 'store_type' => 'cosmetic', 'slug' => (string) ($a->id + 5000)]);
        for ($i = 0; $i < 3; $i++) {
            $this->scan($digits)->assertOk();
        }
        $this->scan($digits)->assertStatus(429);
        $this->scan($a)->assertOk();
    }
}
