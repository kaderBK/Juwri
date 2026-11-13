<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\StaffPush;

use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * Delivers a web-push message to one subscription. Return false when the endpoint is
 * gone (410/404) so the caller prunes it.
 *
 * The real sender (VAPID + minishlink/web-push) is DEFERRED — it needs VAPID keys and
 * a device to receive. The Null sender (default) records nothing and reports success so
 * the scoped fan-out, prefs, and prune logic run + test without a live push.
 */
interface WebPushSender
{
    public function send(PushSubscription $sub, array $payload): bool;
}
