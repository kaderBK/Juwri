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
use Juwri\Modules\Integrations\Jobs\CreateGbpPostJob;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpEngagementClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpEngagementClientInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane B / B4: Local Posts composer render, CTA validation, job
 * dispatch/handle, and cross-tenant 403.
 */
class GbpPostsComposerTest extends TestCase
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

    public function test_index_renders_composer_and_recent_posts(): void
    {
        [$owner, $r] = $this->connectedStore();
        FakeGbpEngagementClient::$gbpPosts['LOC-1'][] = ['name' => 'x/1', 'summary' => 'Old news', 'state' => 'LIVE'];
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.gbp.posts.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee('New post');
        $res->assertSee('Old news');
        $res->assertSee('LEARN_MORE'); // a CTA option is rendered
    }

    public function test_store_dispatches_job_with_cta_body(): void
    {
        [$owner, $r] = $this->connectedStore();
        Queue::fake();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.posts.store').'?store_id='.$r->id, [
            'summary' => 'Weekend special', 'cta_type' => 'ORDER', 'cta_url' => 'https://shop.test/order',
        ])->assertRedirect();

        Queue::assertPushed(CreateGbpPostJob::class, function (CreateGbpPostJob $job) use ($r) {
            return $job->storeId === (int) $r->id
                && $job->body['summary'] === 'Weekend special'
                && $job->body['callToAction']['actionType'] === 'ORDER'
                && $job->body['callToAction']['url'] === 'https://shop.test/order'
                && $job->body['topicType'] === 'STANDARD';
        });
    }

    public function test_store_requires_url_for_non_call_cta(): void
    {
        [$owner, $r] = $this->connectedStore();
        Queue::fake();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.posts.store').'?store_id='.$r->id, [
            'summary' => 'Come visit', 'cta_type' => 'SHOP',
        ])->assertSessionHasErrors('cta_url');

        Queue::assertNothingPushed();
    }

    public function test_store_call_cta_needs_no_url(): void
    {
        [$owner, $r] = $this->connectedStore();
        Queue::fake();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.posts.store').'?store_id='.$r->id, [
            'summary' => 'Call us', 'cta_type' => 'CALL',
        ])->assertRedirect();

        Queue::assertPushed(CreateGbpPostJob::class, function (CreateGbpPostJob $job) {
            return $job->body['callToAction']['actionType'] === 'CALL'
                && ! array_key_exists('url', $job->body['callToAction']);
        });
    }

    public function test_store_rejects_summary_over_limit(): void
    {
        [$owner, $r] = $this->connectedStore();
        Queue::fake();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.posts.store').'?store_id='.$r->id, [
            'summary' => str_repeat('x', 1501),
        ])->assertSessionHasErrors('summary');

        Queue::assertNothingPushed();
    }

    public function test_job_creates_post_in_client(): void
    {
        [$owner, $r] = $this->connectedStore('LOC-JOB');

        new CreateGbpPostJob($r->id, ['summary' => 'Hi', 'topicType' => 'STANDARD'])->handle();

        $this->assertCount(1, FakeGbpEngagementClient::$gbpPosts['LOC-JOB'] ?? []);
        $this->assertSame('Hi', FakeGbpEngagementClient::$gbpPosts['LOC-JOB'][0]['summary']);
    }

    public function test_job_dark_no_op_when_not_connected(): void
    {
        // Store without a gbp credential.
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

        new CreateGbpPostJob($r->id, ['summary' => 'Hi'])->handle();

        $this->assertSame([], FakeGbpEngagementClient::$gbpPosts);
    }

    public function test_index_cross_tenant_403(): void
    {
        [$owner, $r] = $this->connectedStore();
        [$other, $otherR] = $this->connectedStore('LOC-2');
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.posts.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
