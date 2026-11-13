<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\StaffPush;

use Juwri\Modules\Messaging\Contracts\StaffPush;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;

/**
 * Fans a new-order push out to a restaurant's staff. SCOPED: only subscriptions of
 * that restaurant are ever touched — never another restaurant's. Respects the
 * per-subscription notify_new_order pref and prunes dead endpoints.
 *
 * PARITY-RUN T9. Two changes, both deliberately small:
 *
 *  1. The constructor's container lookup is unchanged, but the container now
 *     actually HAS a binding (AppServiceProvider). Before T9 nothing bound
 *     {@see WebPushSender}, so every install silently ran {@see NullWebPushSender}
 *     and staff push never left the server. Tests\Modules\Messaging\MobilePushBindingTest
 *     is the regression guard.
 *  2. The NATIVE lane honours the `mobile_app_push` kill-switch. The WEB lane
 *     deliberately does not: web push predates that key, and gating it would
 *     remove a capability stores already have (the same call T4 made for order
 *     transfer).
 */
class StaffPushService implements StaffPush
{
    private WebPushSender $sender;

    public function __construct(?WebPushSender $sender = null)
    {
        $this->sender = $sender
            ?? (app()->bound(WebPushSender::class) ? app(WebPushSender::class) : new NullWebPushSender);
    }

    /** The sender actually in use — the binding regression test asserts on this. */
    public function sender(): WebPushSender
    {
        return $this->sender;
    }

    /** Deliver one payload to one subscription. False ⇒ the caller should prune. */
    public function sendTo(PushSubscription $sub, array $payload): bool
    {
        return $this->sender->send($sub, $payload);
    }

    /** @return int number of subscriptions a push was sent to */
    public function sendNewOrder(int $storeId, array $payload): int
    {
        $subs = PushSubscription::where('store_id', $storeId)
            ->where('notify_new_order', true)
            ->whereNull('failed_at')
            ->get();

        // T9: the OWNER lane — the app, and the owner's installed dashboard PWA (web
        // rows carrying user_id) — follows the store's new-order toggle (and, for the
        // app, the `mobile_app_push` kill-switch): MobilePushService::laneAllows().
        // The staff PWA's pre-existing web subscriptions are not gated.
        $sent = 0;
        foreach ($subs as $sub) {
            $isNative = in_array((string) ($sub->platform ?? 'web'), MobilePushService::NATIVE_PLATFORMS, true);
            $ownerLane = $isNative || $sub->user_id !== null;
            if ($ownerLane && ! MobilePushService::laneAllows($sub, $storeId, MobilePushService::EVENT_NEW_ORDER)) {
                continue;
            }

            $ok = $this->sender->send($sub, $payload);
            if ($ok) {
                $sent++;
            } else {
                $this->prune($sub); // dead endpoint (410/404)
            }
        }

        return $sent;
    }

    /** Mark a subscription dead (kept for audit) — a later job can hard-delete. */
    public function prune(PushSubscription $sub): void
    {
        $sub->failed_at = now();
        $sub->save();
    }
}
