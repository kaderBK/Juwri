<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\Order;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Analytics\Mail\WeeklyDigestMail;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * DG — scheduled email digest. Verifies the existing analytics:send-weekly-digest
 * command emails opted-in merchants their weekly KPIs and skips opted-out ones.
 */
class WeeklyDigestSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function seedLastWeek(int $storeId): void
    {
        $when = Carbon::now()->subWeek()->startOfWeek()->addDay();
        StoreView::create(['store_id' => $storeId, 'ip' => '10.0.0.1', 'date' => $when, 'device' => 'Mobile', 'lang' => 'en']);
        Order::insert([[
            'store_id' => $storeId, 'status' => 'completed', 'type' => 'dine_in',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => $when,
        ]]);
    }

    public function test_digest_emailed_to_opted_in_owner(): void
    {
        Mail::fake();
        [$owner, $store] = $this->makeOwnerWithStore();
        $this->seedLastWeek($store->id);
        UserOption::updateUserOption($owner->id, 'weekly_digest_email', '1');

        Artisan::call('analytics:send-weekly-digest');

        Mail::assertQueued(WeeklyDigestMail::class, fn ($mail) => $mail->hasTo($owner->email));
    }

    public function test_digest_skips_opted_out_owner(): void
    {
        Mail::fake();
        [$owner, $store] = $this->makeOwnerWithStore();
        $this->seedLastWeek($store->id);
        // no weekly_digest_email option → opted out

        Artisan::call('analytics:send-weekly-digest');

        Mail::assertNotQueued(WeeklyDigestMail::class);
    }
}
