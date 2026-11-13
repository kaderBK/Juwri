<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Jobs;

use App\Models\Customer;
use App\Models\StoreFollow;
use App\Support\OutboundText;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Throwable;

/**
 * BRAND-ROW B3 — real follower notifications, DARK by default.
 *
 * Fans a "new dish" / "new offer" alert out to a store's opted-in followers over
 * the store's existing SMS/WhatsApp machinery. Queued (database connection), never
 * sent inline in a request. Three gates, all enforced HERE so no trigger can bypass
 * them: (1) the owner flag `follower_notify_enabled` (absent = OFF), (2) a per-store
 * daily digest throttle (max one fan-out/store/day), (3) each follower's own `notify`
 * opt-out. PII rule: follower phone numbers never touch the log — only counts.
 */
class NotifyFollowersJob implements ShouldQueue
{
    use Queueable;

    // Must stay below queue retry_after (90s on the sync-safe connection) so a slow run is never re-delivered while still running.
    public int $tries = 3;

    public int $timeout = 80;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /** Stable across retries (serialized with the job): identifies this job's own throttle claim and sent-markers. */
    public string $token;

    public const EVENT_NEW_ITEM = 'new_item';

    public const EVENT_NEW_OFFER = 'new_offer';

    public function __construct(
        public int $storeId,
        public string $event,
        public ?int $subjectId = null,
    ) {
        $this->token = bin2hex(random_bytes(8));
    }

    public function handle(): void
    {
        $store = Store::find($this->storeId);
        if (! $store) {
            return;
        }

        // Gate 1 — dark by default. Absent option = OFF.
        if (! store_options($this->storeId, 'follower_notify_enabled')) {
            return;
        }

        // Gate 2 — one fan-out per store per day. Cache::add is atomic: the first
        // event of the day claims the key, later events no-op (logged, no PII).
        $throttleKey = 'follower_notify:'.$this->storeId.':'.now()->format('Ymd');
        // A retry of the SAME job owns the claim and resumes; any other job is throttled.
        if (! Cache::add($throttleKey, $this->token, now()->endOfDay()) && Cache::get($throttleKey) !== $this->token) {
            Log::info('follower-notify: throttled (already sent today)', ['store_id' => $this->storeId, 'event' => $this->event]);

            return;
        }

        // Gate 3 — only followers who did not opt out.
        $customerIds = StoreFollow::where('store_id', $this->storeId)
            ->where('notify', true)
            ->pluck('customer_id');
        if ($customerIds->isEmpty()) {
            return;
        }

        $message = $this->messageFor(OutboundText::name((string) $store->title));
        $waEnabled = (bool) store_options($this->storeId, 'customer_status_whatsapp');
        $waTemplate = store_options($this->storeId, 'whatsapp_status_template') ?: null;
        $sent = 0;

        Customer::whereIn('id', $customerIds)
            ->whereNotNull('phone')
            ->select('id', 'phone')
            ->chunkById(200, function ($customers) use ($message, $waEnabled, $waTemplate, &$sent) {
                foreach ($customers as $customer) {
                    $phone = trim((string) $customer->phone);
                    if ($phone === '') {
                        continue;
                    }
                    // Per-recipient idempotency: claim before sending; a retry skips whoever was already sent.
                    $sentKey = 'follower_notify:sent:'.$this->token.':'.$customer->id;
                    if (! Cache::add($sentKey, 1, now()->addDays(2))) {
                        continue;
                    }
                    try {
                        // SMS is the primary channel (log provider in dev, store provider in prod).
                        app(MessageQueue::class)->sms($phone, $message, $this->storeId, 'follower_notify', true);
                        if ($waEnabled) {
                            app(MessageQueue::class)->whatsApp($phone, $message, $this->storeId, 'follower_notify', true, $waTemplate, []);
                        }
                    } catch (Throwable $e) {
                        Cache::forget($sentKey); // not delivered: let the retry resume here
                        throw $e;
                    }
                    $sent++;
                }
            });

        // No phone numbers in the log — count only.
        Log::info('follower-notify: dispatched', ['store_id' => $this->storeId, 'event' => $this->event, 'recipients' => $sent]);
    }

    private function messageFor(string $storeTitle): string
    {
        return $this->event === self::EVENT_NEW_OFFER
            ? ___('New offer at :store — come take a look.', ['store' => $storeTitle])
            : ___('New dish at :store — come take a look.', ['store' => $storeTitle]);
    }
}
