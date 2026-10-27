<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Models\ApiToken;
use App\Models\Customer;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\Crm\AudienceService;
use App\Services\Crm\RfmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * K6 — read-only guest CDP API + segment→audience bridge. The API returns a
 * store's guests (contact hashed, never raw) with their RFM segment, is
 * tenant-scoped, and an RFM segment resolves to an audience whose membership
 * matches the segment count.
 */
class GuestCdpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_crm_suite' => true]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        return [$owner, $r];
    }

    private function token(User $user, array $abilities): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);
        return $raw;
    }

    private function guest(Store $r, string $name, string $phone, int $orders, float $spent, int $lastOrderDaysAgo): Customer
    {
        return Customer::create([
            'name' => $name, 'store_id' => $r->id, 'phone' => $phone,
            'orders_count' => $orders, 'total_spent' => $spent,
            'last_order_at' => now()->subDays($lastOrderDaysAgo), 'source' => 'qr',
        ]);
    }

    public function test_guests_endpoint_is_scoped_and_hashes_contact(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $g = $this->guest($r, 'Alice', '55512345', 5, 500, 2);
        $raw = $this->token($owner, ['customers:read']);

        $json = $this->getJson(route('api.v1.guests.index', $r->id), ['Authorization' => 'Bearer ' . $raw])
            ->assertOk()->json();

        $this->assertNotEmpty($json['data']);
        $row = $json['data'][0];
        $this->assertArrayHasKey('contact_hash', $row);
        $this->assertArrayNotHasKey('phone', $row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertSame(hash('sha256', '55512345'), $row['contact_hash']);
        $this->assertArrayHasKey('rfm_segment', $row);
    }

    public function test_a_foreign_store_is_not_readable(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $r2] = $this->ownerRestaurant();
        $this->guest($r2, 'Bob', '55599999', 3, 200, 1);
        $raw = $this->token($owner, ['customers:read']);

        // Owner token cannot read another owner's restaurant.
        $this->getJson(route('api.v1.guests.index', $r2->id), ['Authorization' => 'Bearer ' . $raw])
            ->assertNotFound();
    }

    public function test_segments_endpoint_returns_counts(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->guest($r, 'A', '551', 9, 9000, 1);
        $this->guest($r, 'B', '552', 1, 30, 400);
        $raw = $this->token($owner, ['customers:read']);

        $json = $this->getJson(route('api.v1.guests.segments', $r->id), ['Authorization' => 'Bearer ' . $raw])
            ->assertOk()->json();

        $this->assertSame($r->id, $json['store_id']);
        $this->assertNotEmpty((array) $json['segments']);
    }

    public function test_segment_audience_membership_matches_the_segment_count(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        // A spread so RFM produces more than one segment.
        $this->guest($r, 'A', '551', 9, 9000, 1);
        $this->guest($r, 'B', '552', 8, 8000, 2);
        $this->guest($r, 'C', '553', 1, 30, 400);

        $rfm = new RfmService()->compute($r->id);
        $svc = new AudienceService();
        foreach ($rfm['segments'] as $seg => $count) {
            $rules = ['match' => 'all', 'conditions' => [['field' => 'segment', 'op' => 'eq', 'value' => $seg]]];
            $this->assertSame($count, $svc->count($r->id, $rules), "audience count for segment {$seg} must match RFM");
        }
    }
}
