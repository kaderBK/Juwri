<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\PullGbpReviewsJob;
use Juwri\Modules\Integrations\Models\GbpReview;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpEngagementClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane B / B2: PullGbpReviewsJob upsert idempotence + dark no-ops +
 * the gbp:pull-reviews dispatch command.
 */
class GbpReviewsPullTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_gbp_link' => true]); // kill-switch on
        FakeGbpEngagementClient::reset();
        app()->instance(GbpEngagementClientInterface::class, new FakeGbpEngagementClient);
    }

    private function connectedStore(string $locationId): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $store->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['location_id' => $locationId, 'access_token' => 'AT'],
        ]);

        return $store;
    }

    public function test_pull_upserts_and_is_idempotent(): void
    {
        $store = $this->connectedStore('LOC-7');
        FakeGbpEngagementClient::seedReview('LOC-7', [
            'name' => 'accounts/A/locations/LOC-7/reviews/R1', 'reviewId' => 'R1',
            'reviewer' => ['displayName' => 'Sam', 'isAnonymous' => false, 'profilePhotoUrl' => 'p.png'],
            'starRating' => 'FOUR', 'comment' => 'Great', 'createTime' => '2026-01-02T10:00:00Z',
            'updateTime' => '2026-01-02T10:00:00Z', 'reviewReply' => null,
        ]);

        new PullGbpReviewsJob($store->id)->handle();

        $this->assertDatabaseCount('gbp_reviews', 1);
        $row = GbpReview::first();
        $this->assertSame('R1', $row->external_id);
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame(4, $row->star_rating);
        $this->assertSame('Sam', $row->reviewer_name);
        $this->assertNull($row->reply_comment);

        // Re-pull with a mutated comment + a reply → same row updated, no duplicate.
        FakeGbpEngagementClient::$reviews['LOC-7'][0]['comment'] = 'Actually amazing';
        FakeGbpEngagementClient::$reviews['LOC-7'][0]['reviewReply'] = ['comment' => 'Thanks!', 'updateTime' => '2026-01-05T00:00:00Z'];

        new PullGbpReviewsJob($store->id)->handle();

        $this->assertDatabaseCount('gbp_reviews', 1);
        $row->refresh();
        $this->assertSame('Actually amazing', $row->comment);
        $this->assertSame('Thanks!', $row->reply_comment);
    }

    public function test_dark_no_op_when_store_not_connected(): void
    {
        // Store exists but has no gbp credential/location.
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        new PullGbpReviewsJob($store->id)->handle();

        $this->assertDatabaseCount('gbp_reviews', 0);
    }

    public function test_dark_no_op_when_kill_switch_off(): void
    {
        config(['settings.admin_allow_gbp_link' => false]);
        $store = $this->connectedStore('LOC-9');
        FakeGbpEngagementClient::seedReview('LOC-9', [
            'reviewId' => 'RX', 'starRating' => 'FIVE', 'reviewReply' => null,
        ]);

        new PullGbpReviewsJob($store->id)->handle();

        $this->assertDatabaseCount('gbp_reviews', 0);
    }

    public function test_command_dispatches_only_for_connected_stores(): void
    {
        $connected = $this->connectedStore('LOC-CMD');
        // A credential with no location_id (connected-but-not-located) is skipped.
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner2 = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $post2 = Store::create([
            'user_id' => $owner2->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R2', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $post2->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT'], // no location_id
        ]);

        Queue::fake();
        $this->artisan('gbp:pull-reviews')->assertSuccessful();

        Queue::assertPushed(PullGbpReviewsJob::class, 1);
        Queue::assertPushed(PullGbpReviewsJob::class, fn (PullGbpReviewsJob $j) => $j->storeId === (int) $connected->id);
    }
}
