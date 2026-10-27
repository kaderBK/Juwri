<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL F1 (rev/guest-order-linking): the signed claim-verify link is rate
 * limited so a leaked-but-valid URL cannot be replayed to hammer the
 * login+claim path.
 */
class GuestClaimThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_claim_verify_link_is_throttled(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'source' => 'checkout']);
        $shadow->forceFill(['pending_password' => Hash::make('chosen-pw-123')])->save();

        $url = URL::temporarySignedRoute('customer.claim.verify', now()->addHours(48), [
            'store' => $r->id, 'customer' => $shadow->id,
        ]);

        // Limit is 10/min. Hit it 10 times (first promotes+redirects, rest are
        // valid-signature replays), then the 11th must be blocked with 429.
        for ($i = 0; $i < 10; $i++) {
            $status = $this->get($url)->getStatusCode();
            $this->assertNotSame(429, $status, "request $i should not be throttled yet");
        }
        $this->get($url)->assertStatus(429);
    }
}
