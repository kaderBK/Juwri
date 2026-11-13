<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\StoreFollow;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Jobs\NotifyFollowersJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Tests\TestCase;

/**
 * BRAND-ROW B3 — follower notifications are DARK, queued, throttled and opt-in.
 * Observer side: a new active dish/offer queues NotifyFollowersJob only when the
 * owner flag is on. Job side: fans out to opted-in followers only, once per day.
 */
class BrandRowFollowerNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function store(bool $notifyEnabled): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Crave', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'follower_notify_enabled', $notifyEnabled ? 1 : 0);

        return $store;
    }

    private function follower(Store $store, bool $notify, string $phone): void
    {
        $c = Customer::create(['store_id' => $store->id, 'name' => 'C'.Str::random(4), 'phone' => $phone]);
        StoreFollow::create(['store_id' => $store->id, 'customer_id' => $c->id, 'notify' => $notify]);
    }

    private function addActiveItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => '1']);
    }

    // ---- observer (trigger) side ----

    public function test_new_active_item_queues_notify_when_flag_on(): void
    {
        Queue::fake();
        $store = $this->store(true);

        $this->addActiveItem($store);

        Queue::assertPushed(NotifyFollowersJob::class, fn ($job) => $job->storeId === $store->id && $job->event === NotifyFollowersJob::EVENT_NEW_ITEM);
    }

    public function test_no_notify_when_flag_off(): void
    {
        Queue::fake();
        $store = $this->store(false);

        $this->addActiveItem($store);

        Queue::assertNotPushed(NotifyFollowersJob::class);
    }

    public function test_inactive_item_does_not_notify(): void
    {
        Queue::fake();
        $store = $this->store(true);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);

        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Draft', 'price' => 10, 'active' => '0']);

        Queue::assertNotPushed(NotifyFollowersJob::class);
    }

    public function test_new_active_banner_queues_new_offer(): void
    {
        Queue::fake();
        $store = $this->store(true);

        MenuBanner::create(['store_id' => $store->id, 'image' => 'b.png', 'title' => 'Deal', 'active' => true]);

        Queue::assertPushed(NotifyFollowersJob::class, fn ($job) => $job->event === NotifyFollowersJob::EVENT_NEW_OFFER);
    }

    // ---- job (fan-out) side ----

    public function test_job_fans_out_to_opted_in_followers_only(): void
    {
        Queue::fake();
        $store = $this->store(true);
        $this->follower($store, true, '900001');   // opted in
        $this->follower($store, false, '900002');  // opted OUT
        $this->follower($store, true, '900003');   // opted in

        new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_ITEM)->handle();

        Queue::assertPushed(SendSmsJob::class, 2); // only the two opted-in followers
    }

    public function test_job_throttles_to_one_fanout_per_day(): void
    {
        Queue::fake();
        $store = $this->store(true);
        $this->follower($store, true, '900001');

        $job = new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_ITEM);
        $job->handle();
        new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_OFFER)->handle(); // same day → throttled

        Queue::assertPushed(SendSmsJob::class, 1); // second fan-out suppressed
    }

    public function test_job_does_nothing_when_flag_off(): void
    {
        Queue::fake();
        $store = $this->store(false);
        $this->follower($store, true, '900001');

        new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_ITEM)->handle();

        Queue::assertNotPushed(SendSmsJob::class);
    }

    public function test_rerun_of_same_job_sends_nothing_extra(): void
    {
        Queue::fake();
        $store = $this->store(true);
        $this->follower($store, true, '900001');
        $this->follower($store, true, '900002');

        $job = new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_ITEM);
        $job->handle();
        $job->handle(); // queue redelivery of the same job

        Queue::assertPushed(SendSmsJob::class, 2);
    }

    public function test_failure_mid_run_resumes_without_resending(): void
    {
        $store = $this->store(true);
        foreach (['900001', '900002', '900003'] as $p) {
            $this->follower($store, true, $p);
        }

        $sent = [];
        $calls = 0;
        $fake = \Mockery::mock(MessageQueue::class);
        $fake->shouldReceive('sms')->andReturnUsing(function ($phone) use (&$sent, &$calls) {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('provider down');
            }
            $sent[] = $phone;

            return null;
        });
        $this->app->instance(MessageQueue::class, $fake);

        $job = new NotifyFollowersJob($store->id, NotifyFollowersJob::EVENT_NEW_ITEM);
        try {
            $job->handle();
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }
        $job->handle(); // retry resumes: not throttled, skips the one already sent

        $this->assertCount(3, $sent);
        $this->assertCount(3, array_unique($sent)); // everyone exactly once
    }

    public function test_job_has_bounded_tries_and_timeout_below_retry_after(): void
    {
        $job = new NotifyFollowersJob(1, NotifyFollowersJob::EVENT_NEW_ITEM);
        $this->assertSame(3, $job->tries);
        $this->assertLessThan(90, $job->timeout);
        $this->assertNotEmpty($job->backoff);
    }
}
