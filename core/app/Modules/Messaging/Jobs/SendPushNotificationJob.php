<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * #100: Web Push a notification to every staff subscription for a restaurant.
 * No-op (logged) when VAPID keys or the web-push library are unavailable, so it
 * never breaks order placement.
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(
        public int $storeId,
        public string $title,
        public string $body,
        public string $url = '/staff-app',
        public ?int $staffId = null,
    ) {}

    /**
     * Staff browsers only. The owner's dashboard PWA (web rows with user_id) and
     * native app devices are the OWNER lane: MobilePushService addresses them with
     * their own payload (order link) and toggles, so they are never sent this
     * staff-app payload — nor staff-only pushes like team announcements.
     * `$staffId` narrows it to one staff member's browsers (shift reminders).
     *
     * @return Collection<int, PushSubscription>
     */
    public static function recipients(int $storeId, ?int $staffId = null)
    {
        return PushSubscription::where('store_id', $storeId)
            ->whereNull('user_id')
            ->where(fn ($q) => $q->where('platform', 'web')->orWhereNull('platform'))
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId))
            ->get();
    }

    public function handle(): void
    {
        $public = (string) config('webpush.public_key');
        $private = (string) config('webpush.private_key');

        if ($public === '' || $private === '' || ! class_exists(WebPush::class)) {
            Log::info('[push] skipped — web push not configured', ['store' => $this->storeId]);

            return;
        }

        $subs = self::recipients($this->storeId, $this->staffId);
        if ($subs->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ]]);
        } catch (\Throwable $e) {
            Log::warning('[push] init failed: '.$e->getMessage());

            return;
        }

        $payload = json_encode(['title' => $this->title, 'body' => $this->body, 'url' => $this->url]);

        foreach ($subs as $sub) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $sub->endpoint,
                        'publicKey' => $sub->public_key,
                        'authToken' => $sub->auth_token,
                        'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                    ]),
                    $payload
                );
            } catch (\Throwable $e) {
                Log::warning("[push] queue failed for sub {$sub->id}: ".$e->getMessage());
            }
        }

        // Flush; prune subscriptions the push service reports as gone (404/410).
        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                $endpoint = $report->getEndpoint();
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->delete();
                }
                Log::info('[push] delivery failed', ['reason' => $report->getReason()]);
            }
        }
    }
}
