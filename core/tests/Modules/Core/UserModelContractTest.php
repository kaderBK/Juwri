<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\Upgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Mail\ResetPassword;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09g characterisation of Juwri\Core\Models\User: table, hidden/cast attributes,
 * username slug, the queued mail helpers, isAdmin, the recurring-subscription
 * reset and the posts / upgrade / transactions relations.
 */
class UserModelContractTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 1, 'email_verified_at' => now()], $attributes));
    }

    public function test_table_hidden_and_casts(): void
    {
        $user = new User;
        $this->assertSame('users', $user->getTable());
        $this->assertSame([], $user->getGuarded());
        $this->assertSame(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'], $user->getHidden());
        $casts = $user->getCasts();
        $this->assertSame('datetime', $casts['email_verified_at']);
        $this->assertSame('datetime', $casts['lastactive']);
        $this->assertSame('encrypted', $casts['two_factor_secret']);
        $this->assertSame('encrypted:array', $casts['two_factor_recovery_codes']);
        $this->assertSame('datetime', $casts['two_factor_confirmed_at']);
        $this->assertSame(['username' => ['source' => 'name']], $user->sluggable());
    }

    public function test_send_mail_queues_after_commit_and_returns_true(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->assertTrue($user->sendMail(new VerifyEmail($user)));
        Mail::assertQueued(VerifyEmail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->afterCommit === true);

        $user->sendPasswordResetNotification('tok123');
        Mail::assertQueued(ResetPassword::class, fn ($mail) => $mail->hasTo($user->email) && $mail->token === 'tok123');

        $user->sendEmailVerificationNotification();
        Mail::assertQueued(VerifyEmail::class, 2);
    }

    public function test_send_mail_returns_the_error_message_when_queueing_fails(): void
    {
        $user = $this->user();
        $broken = new class extends Mailable
        {
            public function afterCommit()
            {
                throw new \RuntimeException('queue down');
            }
        };

        $this->assertSame('queue down', $user->sendMail($broken));
    }

    public function test_is_admin_reads_the_user_type(): void
    {
        $this->assertTrue(new User(['user_type' => 'admin'])->isAdmin());
        $this->assertFalse(new User(['user_type' => 'user'])->isAdmin());
        $this->assertFalse((new User)->isAdmin());
    }

    public function test_cancel_recurring_subscription_resets_the_upgrade_row(): void
    {
        $user = $this->user();
        $user->cancelRecurringSubscription(); // no upgrade row: nothing happens

        $upgrade = Upgrade::create(['user_id' => $user->id, 'sub_id' => 1, 'pay_mode' => 'recurring', 'unique_id' => null]);
        $user->refresh()->cancelRecurringSubscription();
        $this->assertSame('recurring', $upgrade->fresh()->pay_mode);

        $upgrade->update(['unique_id' => 'someothergateway###sub_123']);
        $user->refresh()->cancelRecurringSubscription();
        $fresh = $upgrade->fresh();
        $this->assertNull($fresh->unique_id);
        $this->assertSame('one_time', $fresh->pay_mode);
    }

    public function test_relations(): void
    {
        $user = $this->user();
        $older = Store::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDay()]);
        $newer = Store::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $user->stores()->pluck('id')->all());
        $this->assertSame('user_id', $user->upgrade()->getForeignKeyName());
        $this->assertSame('user_id', $user->transactions()->getForeignKeyName());
        $this->assertInstanceOf(Transaction::class, $user->transactions()->getRelated());
    }

    public function test_plan_prefers_the_numeric_group_then_falls_back_to_the_free_plan(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 3], 'status' => 1,
        ]);
        $this->assertSame($plan->id, $this->user(['group_id' => (string) $plan->id])->plan()->id);

        config(['settings.free_membership_plan' => null]);
        $free = $this->user(['group_id' => 'free'])->plan();
        $this->assertSame('free', $free->id);
        $this->assertSame(2, $free->settings->restaurant_limit);
    }
}
