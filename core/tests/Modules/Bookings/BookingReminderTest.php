<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use App\Models\StoreOption;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Mail\BookingReminderMail;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Services\BookingPlan;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Bookings\Services\ReminderService;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * P3-10: 24-hour reminders — opt-in rules (store channel on AND customer ticked it), WhatsApp also behind the
 * `whatsapp_order_alerts` plan gate (missing value = allowed), sent once (claim), re-armed by a reschedule.
 */
class BookingReminderTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $sms = [];

    /** @var list<array{0: string, 1: string}> */
    private array $wa = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC'));
        Mail::fake();
        Cache::flush();
        $test = $this;
        $this->app->instance(Sms::class, new class($test) implements Sms
        {
            public function __construct(private BookingReminderTest $t) {}

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                $this->t->recordSms($to, $message, $feature, $marketing);

                return true;
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });
        $this->app->instance(WhatsApp::class, new class($test) implements WhatsApp
        {
            public function __construct(private BookingReminderTest $t) {}

            public function send(string $to, string $body, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = [], ?int $userId = null): WhatsAppResult
            {
                $this->t->recordWa($to, $body, $feature, $marketing);

                return WhatsAppResult::sent('fake');
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }

            public function optInMarketing(string $phone, int $storeId, string $source): void {}
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function recordSms(string $to, string $message, string $feature, bool $marketing): void
    {
        $this->assertSame('status', $feature);
        $this->assertFalse($marketing);
        $this->sms[] = [$to, $message];
    }

    public function recordWa(string $to, string $body, string $feature, bool $marketing): void
    {
        $this->assertSame('status', $feature);
        $this->assertFalse($marketing);
        $this->wa[] = [$to, $body];
    }

    /** @return array{0: Booking, 1: Store} */
    private function booking(array $settings, array $optIn, string $at = '2026-10-05 05:00', string $phone = '+966500000001', string $email = 'n@example.com'): array
    {
        [$store, $service, $staff] = $this->readyStore(settings: $settings);
        BookingHour::query()->where('store_id', $store->id)->update(['starts_local' => '00:00', 'ends_local' => '24:00']);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, $at), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => $phone, 'email' => $email] + $optIn);

        return [$booking, $store];
    }

    public function test_only_the_channels_both_the_store_and_the_customer_chose(): void
    {
        // store: email on (default), SMS on, WhatsApp off; customer: email + WhatsApp
        [$booking] = $this->booking(['booking_reminder_sms' => true], ['remind_email' => true, 'remind_whatsapp' => true]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC')); // the visit is 23 h away (05:00 Riyadh = 02:00 UTC)

        $this->assertSame(1, app(ReminderService::class)->sendDue());
        Mail::assertQueued(BookingReminderMail::class, fn (BookingReminderMail $m) => $m->hasTo('n@example.com') && str_contains($m->manageUrl, '/appointments/m/'));
        $this->assertSame([], $this->sms); // customer did not tick SMS
        $this->assertSame([], $this->wa);  // store did not turn WhatsApp on
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    public function test_nothing_is_sent_without_a_customer_opt_in(): void
    {
        $this->booking(['booking_reminder_sms' => true, 'booking_reminder_whatsapp' => true], []);
        $this->assertSame(0, app(ReminderService::class)->sendDue());
        Mail::assertNothingQueued();
        $this->assertSame([], $this->sms);
    }

    public function test_sms_and_whatsapp_reminders_carry_the_manage_link_and_are_sent_once(): void
    {
        [$booking] = $this->booking(['booking_reminder_sms' => true, 'booking_reminder_whatsapp' => true], ['remind_sms' => true, 'remind_whatsapp' => true]);
        app(ReminderService::class)->sendDue();
        app(ReminderService::class)->sendDue();

        $this->assertCount(1, $this->sms);
        $this->assertCount(1, $this->wa);
        $this->assertStringContainsString('/appointments/m/'.$booking->public_id.'.', $this->sms[0][1]);
        $this->assertSame('+966500000001', $this->wa[0][0]);
    }

    public function test_a_booking_more_than_24_hours_away_waits(): void
    {
        $this->booking([], ['remind_email' => true], '2026-10-06 12:00');
        $this->assertSame(0, app(ReminderService::class)->sendDue());
    }

    public function test_cancelled_bookings_get_no_reminder(): void
    {
        [$booking] = $this->booking([], ['remind_email' => true]);
        app(BookingService::class)->cancel($booking);
        $this->assertSame(0, app(ReminderService::class)->sendDue());
    }

    public function test_whatsapp_respects_the_whatsapp_order_alerts_plan_gate(): void
    {
        $owner = $this->ownerWithPlan(['whatsapp_order_alerts' => '0']);
        if (BookingPlan::allows($owner, BookingPlan::WHATSAPP_ALERTS)) {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }
        [$store, $service, $staff] = $this->readyStore(settings: ['booking_reminder_whatsapp' => true, 'booking_reminder_sms' => true], owner: $owner);
        BookingHour::query()->where('store_id', $store->id)->update(['starts_local' => '00:00', 'ends_local' => '24:00']);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $this->local($store, '2026-10-05 05:00'), 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'N', 'phone' => '+966500000001', 'remind_whatsapp' => true, 'remind_sms' => true]);

        app(ReminderService::class)->sendDue();
        $this->assertSame([], $this->wa); // withheld by the plan
        $this->assertCount(1, $this->sms); // SMS is not behind that key
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    public function test_a_reschedule_re_arms_the_reminder(): void
    {
        [$booking, $store] = $this->booking(['booking_cancel_window_hours' => 1], ['remind_email' => true]);
        app(ReminderService::class)->sendDue();
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
        $made = app(HoldService::class)->hold($store, [], null, $this->local($store, '2026-10-05 08:00'), 'venue', null, [], $booking->fresh('lines'));
        app(BookingService::class)->reschedule($booking->fresh(), $made['hold']->public_id, $made['token']);

        $this->assertNull($booking->fresh()->reminder_sent_at);
    }

    /**
     * P6-195: when the per-booking send throws, the batch keeps going. The failing booking has its claim released
     * (reminder_sent_at reset to null) so the next scheduler run retries it; only successes are counted.
     */
    public function test_a_failing_send_releases_the_claim_and_lets_the_batch_continue(): void
    {
        Exceptions::fake(); // report() is captured, not re-logged by the handler

        // Three due bookings, one per store. A opted in to SMS only (the throw path); B and C get the full reminder.
        [$a] = $this->booking(['booking_reminder_sms' => true], ['remind_sms' => true], '2026-10-05 05:00', '+966500000001', 'a@example.com');
        [$b] = $this->booking(['booking_reminder_sms' => true], ['remind_email' => true, 'remind_sms' => true], '2026-10-05 05:00', '+966500000002', 'b@example.com');
        [$c] = $this->booking(['booking_reminder_sms' => true], ['remind_email' => true, 'remind_sms' => true], '2026-10-05 05:00', '+966500000003', 'c@example.com');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-04 03:00:00', 'UTC')); // 23 h before the visits (05:00 Riyadh = 02:00 UTC)

        // Re-bind the SMS fake to throw only for booking A's phone (B and C still pass through the recording fake).
        $test = $this;
        $this->app->instance(Sms::class, new class($test) implements Sms
        {
            public function __construct(private BookingReminderTest $t) {}

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                if ($to === '+966500000001') {
                    throw new \RuntimeException('sms provider down for A');
                }
                $this->t->recordSms($to, $message, $feature, $marketing);

                return true;
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });

        // B and C succeed; A's exception is swallowed, claim released, and reported.
        $this->assertSame(2, app(ReminderService::class)->sendDue());
        $this->assertNotNull($b->fresh()->reminder_sent_at);
        $this->assertNotNull($c->fresh()->reminder_sent_at);
        $this->assertNull($a->fresh()->reminder_sent_at); // released → retried next run
        Mail::assertQueued(BookingReminderMail::class, fn (BookingReminderMail $m) => $m->hasTo('b@example.com'));
        Mail::assertQueued(BookingReminderMail::class, fn (BookingReminderMail $m) => $m->hasTo('c@example.com'));
        Mail::assertNotQueued(BookingReminderMail::class, fn (BookingReminderMail $m) => $m->hasTo('a@example.com'));
        $this->assertCount(2, $this->sms); // A's throw never reached recordSms
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'sms provider down for A');
    }

    /**
     * P6-335: an SMS failure used to release the claim and re-queue the (already queued) e-mail every 10 minutes.
     * Now the e-mail channel succeeds independently, so the claim is kept and the mail is queued exactly once.
     */
    public function test_a_failing_sms_channel_does_not_re_queue_the_email(): void
    {
        Exceptions::fake();

        [$booking] = $this->booking(['booking_reminder_sms' => true], ['remind_email' => true, 'remind_sms' => true]);

        $this->app->instance(Sms::class, new class implements Sms
        {
            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                throw new \RuntimeException('sms provider down');
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });

        app(ReminderService::class)->sendDue();
        app(ReminderService::class)->sendDue();
        app(ReminderService::class)->sendDue();

        Mail::assertQueued(BookingReminderMail::class, 1);
        $this->assertNotNull($booking->fresh()->reminder_sent_at);
    }

    /**
     * P6-335: when every attempted channel fails the claim is released so the next run retries, but only up to
     * 3 attempts per booking; the 3rd consecutive failure keeps the claim and logs a warning.
     */
    public function test_when_every_channel_fails_the_claim_is_released_then_kept_after_three_attempts(): void
    {
        Exceptions::fake();

        [$booking] = $this->booking(['booking_reminder_sms' => true], ['remind_sms' => true]);

        $this->app->instance(Sms::class, new class implements Sms
        {
            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                throw new \RuntimeException('sms provider down');
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });

        Log::spy();

        app(ReminderService::class)->sendDue();
        $this->assertNull($booking->fresh()->reminder_sent_at);

        app(ReminderService::class)->sendDue();
        $this->assertNull($booking->fresh()->reminder_sent_at);

        app(ReminderService::class)->sendDue();
        $this->assertNotNull($booking->fresh()->reminder_sent_at);

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_arabic_store_gets_an_arabic_rtl_reminder_and_sms(): void
    {
        [$booking, $store] = $this->booking(['booking_reminder_sms' => true], ['remind_email' => true, 'remind_sms' => true]);
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        app()->setLocale('en'); // the cron worker's language

        app(ReminderService::class)->sendDue();

        Mail::assertQueued(BookingReminderMail::class, fn (BookingReminderMail $m) => $m->locale === 'ar');
        $this->assertStringContainsString('تذكير', $this->sms[0][1]);
        $this->assertSame('en', app()->getLocale()); // restored

        $mail = new BookingReminderMail('S', 'N', 'when', 'svc', 'https://x.test', 'ar');
        $html = $mail->render();
        $this->assertStringContainsString('dir="rtl"', $html);
    }
}
