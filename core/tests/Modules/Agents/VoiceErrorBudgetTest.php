<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceErrorBudget;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-33: phone-agent error budget. Each threshold, the failed-call minimum count, once-per-store/kind/hour
 * idempotency, store isolation, the webhook signature-reject counter and the hourly schedule registration.
 */
class VoiceErrorBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private Store $store;

    private Store $otherStore;

    /** @var list<array{int,string,string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->now = Carbon::parse('2026-06-15 12:05:00', 'UTC');
        Carbon::setTestNow($this->now);
        $this->store = $this->makeStore();
        $this->otherStore = $this->makeStore();
        $test = $this;
        $this->app->instance(MessageQueue::class, new class($test) implements MessageQueue
        {
            public function __construct(private VoiceErrorBudgetTest $t) {}

            public function staffPush(int $storeId, string $title, string $body, string $url = '/staff-app', ?int $staffId = null): void
            {
                $this->t->recordPush($storeId, $title, $body);
            }

            public function sms(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): void {}

            public function whatsApp(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = [], bool $afterCommit = false): void {}
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function recordPush(int $storeId, string $title, string $body): void
    {
        $this->pushes[] = [$storeId, $title, $body];
    }

    private function makeStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** $n calls in the previous hour (11:00-12:00 UTC), $failed of them failed. */
    private function calls(Store $store, int $n, int $failed, string $at = '2026-06-15 11:30:00'): void
    {
        for ($i = 0; $i < $n; $i++) {
            CallLog::create([
                'store_id' => $store->id, 'provider' => 'twilio', 'call_sid' => 'CA-'.Str::random(16),
                'outcome' => $i < $failed ? 'failed' : 'hangup', 'duration_seconds' => 30,
                'created_at' => Carbon::parse($at, 'UTC'), 'updated_at' => Carbon::parse($at, 'UTC'),
            ]);
        }
    }

    /** Run $fn as if it were 11:30 UTC (the previous hour's bucket). */
    private function lastHour(callable $fn): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 11:30:00', 'UTC'));
        $fn();
        Carbon::setTestNow($this->now);
    }

    /**
     * @param  list<array{store_id:int,kind:string,detail:string}>  $breaches
     * @return list<string>
     */
    private function kinds(array $breaches): array
    {
        return array_map(fn ($b) => $b['store_id'].':'.$b['kind'], $breaches);
    }

    public function test_failed_call_rate_over_budget_alerts(): void
    {
        $this->calls($this->store, 20, 2); // 10% of 20

        $found = app(VoiceErrorBudget::class)->check();

        $this->assertSame([$this->store->id.':failed_calls'], $this->kinds($found));
        $this->assertSame(1, PlatformAlert::where('kind', 'voice_error_budget')->count());
        $this->assertCount(1, $this->pushes);
        $this->assertSame($this->store->id, $this->pushes[0][0]);
    }

    public function test_failed_rate_at_budget_or_under_min_count_is_quiet(): void
    {
        $this->calls($this->store, 20, 1);       // exactly 5% -> not over
        $this->calls($this->otherStore, 19, 10); // 53% but under the 20-call minimum

        $this->assertSame([], app(VoiceErrorBudget::class)->check());
        $this->assertSame(0, PlatformAlert::count());
        $this->assertSame([], $this->pushes);
    }

    public function test_calls_outside_the_previous_hour_are_ignored(): void
    {
        $this->calls($this->store, 20, 20, '2026-06-15 10:59:00');
        $this->calls($this->store, 20, 20, '2026-06-15 12:01:00');

        $this->assertSame([], app(VoiceErrorBudget::class)->check());
    }

    public function test_any_handoff_failure_alerts(): void
    {
        $this->lastHour(fn () => VoiceErrorBudget::handoffFailed($this->store->id));

        $this->assertSame([$this->store->id.':handoff_failures'], $this->kinds(app(VoiceErrorBudget::class)->check()));
    }

    public function test_signature_reject_spike_alerts_only_above_threshold(): void
    {
        $this->lastHour(function () {
            for ($i = 0; $i < 10; $i++) {
                VoiceErrorBudget::signatureReject($this->store->id);
            }
            for ($i = 0; $i < 11; $i++) {
                VoiceErrorBudget::signatureReject($this->otherStore->id);
            }
        });

        $this->assertSame([$this->otherStore->id.':signature_rejects'], $this->kinds(app(VoiceErrorBudget::class)->check()));
    }

    public function test_callback_errors_and_latency_p95_alert(): void
    {
        $this->lastHour(function () {
            for ($i = 0; $i < 6; $i++) {
                VoiceErrorBudget::callbackError($this->store->id);
            }
            for ($i = 0; $i < 20; $i++) {
                VoiceErrorBudget::answerLatency($this->otherStore->id, $i < 18 ? 200 : 5000); // p95 = 5000
                VoiceErrorBudget::answerLatency($this->store->id, $i < 19 ? 200 : 9000);      // p95 = 200
            }
        });

        $budget = app(VoiceErrorBudget::class);
        $m = $budget->metrics(Carbon::parse('2026-06-15 11:00:00', 'UTC'));
        $this->assertSame(200, $m[$this->store->id]['latency_p95_ms']);
        $this->assertSame(5000, $m[$this->otherStore->id]['latency_p95_ms']);

        $this->assertEqualsCanonicalizing(
            [$this->store->id.':callback_errors', $this->otherStore->id.':answer_latency'],
            $this->kinds($budget->check())
        );
    }

    public function test_check_is_idempotent_per_store_kind_hour(): void
    {
        $this->calls($this->store, 20, 5);
        $this->lastHour(fn () => VoiceErrorBudget::handoffFailed($this->store->id));

        $budget = app(VoiceErrorBudget::class);
        $this->assertCount(2, $budget->check());
        $this->assertSame([], $budget->check());
        $this->artisan('voice:error-budget-check')->assertSuccessful();

        $this->assertSame(2, PlatformAlert::where('kind', 'voice_error_budget')->count());
        $this->assertCount(2, $this->pushes);
    }

    public function test_store_isolation(): void
    {
        $this->calls($this->store, 20, 10);
        $this->calls($this->otherStore, 40, 0);
        $this->lastHour(fn () => VoiceErrorBudget::handoffFailed($this->store->id));

        $found = app(VoiceErrorBudget::class)->check();

        $this->assertSame([$this->store->id], array_values(array_unique(array_column($found, 'store_id'))));
        foreach ($this->pushes as [$sid]) {
            $this->assertSame($this->store->id, $sid);
        }
        $this->assertSame(0, PlatformAlert::where('message', 'like', "%store {$this->otherStore->id} %")->count());
    }

    public function test_alert_text_carries_no_secrets_or_caller_numbers(): void
    {
        CallLog::create([
            'store_id' => $this->store->id, 'provider' => 'twilio', 'call_sid' => 'CA-SECRETSID', 'from_number' => '+15550001111',
            'outcome' => 'failed', 'created_at' => Carbon::parse('2026-06-15 11:10:00', 'UTC'), 'updated_at' => now(),
        ]);
        $this->calls($this->store, 19, 1);

        app(VoiceErrorBudget::class)->check();

        $msg = (string) PlatformAlert::where('kind', 'voice_error_budget')->value('message');
        $this->assertStringContainsString('failed_calls', $msg);
        $this->assertStringNotContainsString('CA-SECRETSID', $msg);
        $this->assertStringNotContainsString('+15550001111', $msg);
    }

    public function test_owner_push_is_localized_in_arabic(): void
    {
        app()->setLocale('ar');
        $this->lastHour(fn () => VoiceErrorBudget::handoffFailed($this->store->id));

        app(VoiceErrorBudget::class)->check();

        $this->assertSame('وكيل الهاتف يحتاج إلى انتباه', $this->pushes[0][1]);
    }

    public function test_status_webhook_signature_reject_is_counted(): void
    {
        $this->withoutMiddleware();
        $line = PhoneLine::create([
            'store_id' => $this->store->id, 'provider' => 'twilio', 'number' => '+15551234',
            'active' => true, 'orders_enabled' => true,
        ]);

        $this->lastHour(function () use ($line) {
            $this->post(route('voice.status', 'twilio'), ['To' => $line->number, 'CallSid' => 'CA-X', 'CallStatus' => 'completed', 'CallDuration' => 5])
                ->assertStatus(401);
        });

        $m = app(VoiceErrorBudget::class)->metrics(Carbon::parse('2026-06-15 11:00:00', 'UTC'));
        $this->assertSame(1, $m[$this->store->id]['signature_rejects']);
    }

    public function test_hourly_schedule_is_registered(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'voice:error-budget-check'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }
}
