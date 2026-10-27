<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\StaffPush;

use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * Default sender — no live push (VAPID delivery DEFERRED). Reports success so the
 * surrounding fan-out/prefs/prune pipeline is exercised. Swap for a real VAPID sender
 * by binding WebPushSender in the container once keys + a device are available.
 */
class NullWebPushSender implements WebPushSender
{
    public function send(PushSubscription $sub, array $payload): bool
    {
        return true;
    }
}
