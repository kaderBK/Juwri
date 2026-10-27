<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * `shifts:remind` pushes the reminder to the shift's STORE, narrowed to that
 * staff member's browsers — it used to pass the staff id as the store id, so the
 * reminder went to whichever store had that id. Hourly runs overlap the window,
 * so a shift is reminded once.
 */
class ShiftRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: StaffMember, 2: StaffMember} */
    private function storeWithTwoStaff(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = fn (string $n) => StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => $n,
            'email' => $n.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true,
        ]);

        $staff('filler'); // staff ids must not equal the store id, or the old bug would pass

        return [$store, $staff('a'), $staff('b')];
    }

    public function test_the_reminder_goes_to_the_shifts_store_and_staff_member_once(): void
    {
        Queue::fake();
        [$store, $a] = $this->storeWithTwoStaff();
        StaffShift::create([
            'store_id' => $store->id, 'staff_id' => $a->id, 'published' => true,
            'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(6),
        ]);

        $this->assertNotSame((int) $store->id, (int) $a->id);

        $this->artisan('shifts:remind', ['--hours' => 2])->assertExitCode(0);
        $this->artisan('shifts:remind', ['--hours' => 2])->assertExitCode(0);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
        Queue::assertPushed(SendPushNotificationJob::class, fn (SendPushNotificationJob $job) => $job->storeId === (int) $store->id && $job->staffId === (int) $a->id);
    }

    public function test_a_staff_scoped_push_reaches_only_that_staff_members_browsers(): void
    {
        [$store, $a, $b] = $this->storeWithTwoStaff();
        $sub = fn (string $e) => ['endpoint' => 'https://push.example.com/'.$e, 'keys' => ['p256dh' => 'k', 'auth' => 'a']];
        PushSubscription::store($sub('a'), $store->id, $a->id);
        PushSubscription::store($sub('b'), $store->id, $b->id);

        $this->assertSame(['https://push.example.com/a'], SendPushNotificationJob::recipients((int) $store->id, (int) $a->id)->pluck('endpoint')->all());
        $this->assertCount(2, SendPushNotificationJob::recipients((int) $store->id));
    }
}
