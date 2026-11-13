<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\Order;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
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
 * P6-196: a single bad recipient used to abort the weekly digest for everyone — and the
 * per-user log line said "Queued weekly digest for …" even though the call was actually
 * synchronous `Mail::send()`. Each iteration is now wrapped in try/catch (one failing
 * owner is reported + warned, the rest still run) and the mail runs through
 * `Mail::queue()`; the trailing info line counts queued and failed honestly.
 *
 *   - One opted-in owner whose queue() throws must not stop the second owner's digest
 *     from being queued (WeeklyDigestMail still gets queued for the survivor).
 *   - The trailing info line must report honest queued/failed counts and the bad
 *     recipient must get a `warn(...)` so it shows in operator output.
 */
class WeeklyDigestResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnerWithStore(string $email): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
            'email' => $email,
        ]);
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

    /**
     * Install a PendingMail substitute keyed on the recipient address. For the
     * `throwingEmail` the substitute throws on queue(); for any other email it records
     * the queued Mailable into `$queuedByAddress`. Mail::fake() never throws, so we
     * drive Mail::to() through `shouldReceive` instead — pending mail for non-throwing
     * addresses is still constructed by the same closure, so the command sees an
     * ordinary to()->queue() chain.
     */
    private function installFakeMailer(string $throwingEmail, array &$queuedByAddress): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function ($address) use ($throwingEmail, &$queuedByAddress) {
            return new class($address, $throwingEmail, $queuedByAddress)
            {
                public function __construct(
                    private string $address,
                    private string $throwingEmail,
                    private array &$queuedByAddress,
                ) {}

                public function queue(Mailable $mailable): mixed
                {
                    if ($this->address === $this->throwingEmail) {
                        throw new \RuntimeException('mailer intentionally down');
                    }

                    $this->queuedByAddress[$this->address] = $mailable;

                    return null;
                }
            };
        });
    }

    /**
     * P6-196: a single bad recipient must NOT abort the rest. The second (good) owner
     * still has their WeeklyDigestMail queued; the command exits 0; the throwing
     * owner's address never lands in the queued set.
     */
    public function test_one_failing_recipient_does_not_block_the_digest(): void
    {
        $throwingEmail = 'queue-throws-'.Str::lower(Str::random(6)).'@example.test';
        $survivingEmail = 'survives-'.Str::lower(Str::random(6)).'@example.test';

        [$survivor, $store1] = $this->makeOwnerWithStore($survivingEmail);
        [$throwsUser, $store2] = $this->makeOwnerWithStore($throwingEmail);
        $this->seedLastWeek($store1->id);
        $this->seedLastWeek($store2->id);
        UserOption::updateUserOption($survivor->id, 'weekly_digest_email', '1');
        UserOption::updateUserOption($throwsUser->id, 'weekly_digest_email', '1');

        $queuedByAddress = [];
        $this->installFakeMailer($throwingEmail, $queuedByAddress);

        $exitCode = Artisan::call('analytics:send-weekly-digest');

        $this->assertSame(0, $exitCode, 'command must exit 0 when one recipient fails');
        $this->assertArrayHasKey($survivingEmail, $queuedByAddress, 'the good owner still got a queued WeeklyDigestMail');
        $this->assertInstanceOf(WeeklyDigestMail::class, $queuedByAddress[$survivingEmail]);
        $this->assertArrayNotHasKey($throwingEmail, $queuedByAddress, 'the failing owner must NOT have a queued mail');
        $this->assertCount(1, $queuedByAddress, 'exactly one digest was queued');
    }

    /**
     * P6-196: the final info line must report honest `queued` and `failed` counts
     * (the old log said "Queued weekly digest" even though the call was a synchronous
     * send()). The failing recipient also emits a `warn()` so the operator sees who.
     */
    public function test_truthful_final_summary_and_warn_for_failing_recipient(): void
    {
        $throwingEmail = 'queue-throws-'.Str::lower(Str::random(6)).'@example.test';
        $survivingEmail = 'survives-'.Str::lower(Str::random(6)).'@example.test';

        [$survivor, $store1] = $this->makeOwnerWithStore($survivingEmail);
        [$throwsUser, $store2] = $this->makeOwnerWithStore($throwingEmail);
        $this->seedLastWeek($store1->id);
        $this->seedLastWeek($store2->id);
        UserOption::updateUserOption($survivor->id, 'weekly_digest_email', '1');
        UserOption::updateUserOption($throwsUser->id, 'weekly_digest_email', '1');

        $queuedByAddress = [];
        $this->installFakeMailer($throwingEmail, $queuedByAddress);

        // ExpectsOutputToContain proves both the truthful totals and the warn line —
        // the old "Queued weekly digest for …" per-user line is gone.
        $this->artisan('analytics:send-weekly-digest')
            ->expectsOutputToContain('1 queued, 1 failed')
            ->expectsOutputToContain('Failed weekly digest for user '.$throwingEmail)
            ->assertExitCode(0);
    }
}
