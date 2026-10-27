<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\GbpReview;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpEngagementClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane B / B3: reviews inbox render, unreplied filter, reply
 * (API + local echo), and cross-tenant 403.
 */
class GbpReviewsInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_gbp_link' => true]);
        FakeGbpEngagementClient::reset();
        app()->instance(GbpEngagementClientInterface::class, new FakeGbpEngagementClient);
        $this->shareLayoutGlobals();
    }

    private function connectedStore(string $locationId = 'LOC-1'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['location_id' => $locationId, 'account_id' => 'ACC-1', 'access_token' => 'AT'],
        ]);

        return [$owner, $r];
    }

    private function seedReview(Store $r, string $locationId, string $externalId, int $stars, ?string $reply = null): GbpReview
    {
        return GbpReview::create([
            'store_id' => $r->id, 'location_id' => $locationId, 'external_id' => $externalId,
            'reviewer_name' => 'Reviewer '.$externalId, 'reviewer_is_anonymous' => false,
            'star_rating' => $stars, 'comment' => 'Comment '.$externalId,
            'reply_comment' => $reply, 'review_created_at' => now()->subDays(2),
        ]);
    }

    public function test_index_renders_reviews_labelled_google(): void
    {
        [$owner, $r] = $this->connectedStore();
        $this->seedReview($r, 'LOC-1', 'R1', 4);
        $this->seedReview($r, 'LOC-1', 'R2', 2, 'Thanks');
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.gbp.reviews.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee('Google reviews');
        $res->assertSee('Reviewer R1');
        $res->assertSee('Reviewer R2');
    }

    public function test_unreplied_filter_hides_replied(): void
    {
        [$owner, $r] = $this->connectedStore();
        $this->seedReview($r, 'LOC-1', 'R1', 4);           // unreplied
        $this->seedReview($r, 'LOC-1', 'R2', 2, 'Thanks'); // replied
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.gbp.reviews.index').'?store_id='.$r->id.'&unreplied=1')->assertOk();
        $res->assertSee('Reviewer R1');
        $res->assertDontSee('Comment R2'); // replied review filtered out
    }

    public function test_reply_posts_and_echoes_locally(): void
    {
        [$owner, $r] = $this->connectedStore();
        $review = $this->seedReview($r, 'LOC-1', 'R1', 5);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.reviews.reply').'?store_id='.$r->id, [
            'review_id' => $review->id, 'text' => 'Thank you for visiting!',
        ])->assertRedirect();

        $review->refresh();
        $this->assertSame('Thank you for visiting!', $review->reply_comment);
        $this->assertNotNull($review->reply_updated_at);
    }

    public function test_reply_rejects_too_long_text(): void
    {
        [$owner, $r] = $this->connectedStore();
        $review = $this->seedReview($r, 'LOC-1', 'R1', 5);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.reviews.reply').'?store_id='.$r->id, [
            'review_id' => $review->id, 'text' => str_repeat('x', 4097),
        ])->assertSessionHasErrors('text');

        $review->refresh();
        $this->assertNull($review->reply_comment);
    }

    public function test_index_cross_tenant_403(): void
    {
        [$owner, $r] = $this->connectedStore();
        [$other, $otherR] = $this->connectedStore('LOC-2');
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.reviews.index').'?store_id='.$otherR->id)->assertForbidden();
    }

    public function test_reply_cross_tenant_403(): void
    {
        [$owner, $r] = $this->connectedStore();
        [$other, $otherR] = $this->connectedStore('LOC-2');
        $foreignReview = $this->seedReview($otherR, 'LOC-2', 'RX', 3);
        $this->withoutMiddleware();

        // Attacker (owner) tries to act on another store via its store_id → 403.
        $this->actingAs($owner)->post(route('stores.gbp.reviews.reply').'?store_id='.$otherR->id, [
            'review_id' => $foreignReview->id, 'text' => 'hijack',
        ])->assertForbidden();

        $foreignReview->refresh();
        $this->assertNull($foreignReview->reply_comment);
    }
}
