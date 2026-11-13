<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Mail\BookingReminderMail;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Throwable;

/**
 * P3-10: the 24-hour reminder (book §8 timeline). Opt-in rules: a channel is used only when the store turned it on
 * (email on by default; SMS and WhatsApp off) AND the customer ticked it for this booking. WhatsApp also needs the
 * store's plan to include `whatsapp_order_alerts` (the plan-gate rule: no value = allowed). A reminder is a utility
 * message (feature `status`), never marketing. Each booking is claimed once (reminder_sent_at), so two schedulers
 * cannot both send it; a reschedule clears the claim.
 *
 * P6-195: `sendDue()` claims the row before sending (kept, so two schedulers cannot double-send). On send failure
 * the claim is released (reminder_sent_at reset to null) and the exception is reported, so the next run retries
 * that booking while the rest of the batch still goes out; only successes are counted.
 *
 * P6-335: each channel is delivered inside its own try/catch, so a failing channel no longer takes the others down
 * with it (before, the queued e-mail was re-sent every 10 minutes — up to ~144 times — because an SMS failure
 * released the claim and the next run re-queued the mail). The claim is kept as soon as at least one channel
 * succeeds; failed channels are not retried. Only when every attempted channel fails is the claim released for a
 * retry, capped at 3 scheduler attempts per booking (cache key `booking-reminder-attempts:{id}`, 2-day TTL); the
 * 3rd consecutive all-channel failure keeps the claim and logs a warning.
 */
final class ReminderService
{
    public const HOURS_BEFORE = 24;

    /** Max scheduler attempts (first one included) to deliver a reminder before the claim is kept for good. */
    public const MAX_REMINDER_ATTEMPTS = 3;

    /** How long the per-booking retry counter is remembered (2 days, in seconds). */
    private const REMINDER_ATTEMPT_TTL = 172800;

    /** @return int bookings reminded */
    public function sendDue(): int
    {
        $ids = Booking::query()->where('status', 'confirmed')->whereNull('reminder_sent_at')
            ->where('starts_at', '>', now('UTC'))
            ->where('starts_at', '<=', now('UTC')->addHours(self::HOURS_BEFORE))
            ->where(fn ($q) => $q->where('remind_email', true)->orWhere('remind_sms', true)->orWhere('remind_whatsapp', true))
            ->orderBy('starts_at')->limit(500)->pluck('id');

        $n = 0;
        foreach ($ids as $id) {
            $claimed = Booking::query()->whereKey($id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now('UTC')]);
            if ($claimed !== 1) {
                continue;
            }
            try {
                $booking = Booking::query()->with(['lines', 'store'])->find($id);
                if (! ($booking instanceof Booking && $booking->store instanceof Store)) {
                    continue;
                }
                $result = $this->deliverChannels($booking, $booking->store);
                if ($result['used'] === []) {
                    if ($result['failed'] !== []) {
                        $this->releaseOrGiveUp((int) $id, $result['failed']);
                    }

                    continue;
                }
                $n++;
            } catch (Throwable $e) {
                $this->releaseOrGiveUp((int) $id, [get_class($e)]);
                report($e);

                continue;
            }
        }

        return $n;
    }

    /** @return list<string> channels used */
    public function send(Booking $booking, Store $store): array
    {
        return $this->deliver($booking, $store);
    }

    /** @return list<string> channels used */
    private function deliver(Booking $booking, Store $store): array
    {
        return $this->deliverChannels($booking, $store)['used'];
    }

    /**
     * Deliver every opted-in channel, isolating each channel's failure so the rest still go out.
     *
     * @return array{used: list<string>, failed: list<string>} channels that succeeded / channels that threw
     */
    private function deliverChannels(Booking $booking, Store $store): array
    {
        // P6-265: cron has no request locale; speak the store's default language (text, date, mail frame dir).
        $locale = (string) (store_options((int) $store->id)->default_language ?? '') ?: (string) config('app.locale');

        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $settings = BookingSettings::for((int) $store->id);
            $when = $booking->starts_at->copy()->setTimezone($settings->timezone)->translatedFormat('D j M, H:i');
            $services = $booking->lines->pluck('name')->implode(', ');
            $url = ManageLink::url($booking, $store);
            $text = ___('Reminder: :services at :store on :when. Change or cancel: :url', [
                'services' => $services, 'store' => (string) $store->title, 'when' => $when, 'url' => $url,
            ]);

            $used = [];
            $failed = [];
            if ($settings->reminderEmail && $booking->remind_email && $booking->email) {
                try {
                    Mail::to($booking->email)->queue(new BookingReminderMail((string) $store->title, $booking->customer_name, $when, $services, $url, $locale));
                    $used[] = 'email';
                } catch (Throwable $e) {
                    $failed[] = 'email';
                    report($e);
                }
            }
            if ($settings->reminderSms && $booking->remind_sms && $booking->phone) {
                try {
                    app(Sms::class)->send($booking->phone, $text, (int) $store->id, 'status');
                    $used[] = 'sms';
                } catch (Throwable $e) {
                    $failed[] = 'sms';
                    report($e);
                }
            }
            if ($settings->reminderWhatsApp && $booking->remind_whatsapp && $booking->phone
                && BookingPlan::storeAllows($store, BookingPlan::WHATSAPP_ALERTS)) {
                try {
                    app(WhatsApp::class)->send($booking->phone, $text, (int) $store->id, 'status');
                    $used[] = 'whatsapp';
                } catch (Throwable $e) {
                    $failed[] = 'whatsapp';
                    report($e);
                }
            }

            return ['used' => $used, 'failed' => $failed];
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * Release the claim for a retry, or keep it once the per-booking retry cap is exhausted.
     *
     * @param  list<string>  $failed
     */
    private function releaseOrGiveUp(int $bookingId, array $failed): void
    {
        $key = 'booking-reminder-attempts:'.$bookingId;
        Cache::add($key, 0, self::REMINDER_ATTEMPT_TTL);
        $attempts = (int) Cache::increment($key);

        if ($attempts >= self::MAX_REMINDER_ATTEMPTS) {
            Log::warning('Booking reminder attempts exhausted; keeping the claim.', [
                'booking_id' => $bookingId, 'channels' => $failed, 'attempts' => $attempts,
            ]);

            return;
        }

        Booking::query()->whereKey($bookingId)->update(['reminder_sent_at' => null]);
    }
}
