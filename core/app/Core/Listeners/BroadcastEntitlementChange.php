<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Listeners;

use Juwri\Core\Events\FeatureGranted;
use Juwri\Core\Events\FeatureRevoked;
use App\Services\FeatureAccess;
use App\Services\WebhookService;

/**
 * F6-3 (feature-platform gap audit): entitlement changes are invisible to
 * integrations — a partner app or an owner's own automation had no way to learn
 * that a feature was granted or revoked short of polling the license endpoint.
 *
 * This listener is the single reaction point for both entitlement events:
 *
 *  1. it emits the `entitlement.changed` webhook topic through the EXISTING
 *     {@see WebhookService} rail (which fans out to the owner's matching active
 *     endpoints as queued {@see \Juwri\Modules\Integrations\Jobs\DeliverWebhookJob}s) — there is
 *     deliberately no second delivery path;
 *  2. it busts the F6-4 serve-time decision cache for that owner's stores, so a
 *     revoke takes effect on kiosk/signage/print immediately instead of after
 *     the 60s TTL.
 *
 * Ordering note: `DeliverWebhookJob` no-ops when its endpoint row has gone away
 * by the time it runs (hard-delete cascade deletes endpoints in the same
 * request that revokes the features). That is the intended outcome — a deleted
 * tenant should not receive its own teardown events — so this listener does not
 * try to deliver synchronously to beat the delete.
 *
 * Nothing here may throw: entitlement writes are inside billing transactions.
 */
class BroadcastEntitlementChange
{
    public const TOPIC = 'entitlement.changed';

    public function handle(FeatureGranted|FeatureRevoked $event): void
    {
        $action = $event instanceof FeatureGranted ? 'granted' : 'revoked';

        // (2) Cache-bust first — correctness of the serve-time gate must not
        // depend on webhook delivery succeeding.
        try {
            FeatureAccess::bustUser($event->userId, $event->featureKey);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            WebhookService::dispatch($event->userId, self::TOPIC, [
                'action'      => $action,
                'feature_key' => $event->featureKey,
                'source_type' => $event->sourceType,
                'source_id'   => $event->sourceId,
                'user_id'     => $event->userId,
                'occurred_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            report($e); // never break the grant/revoke that fired us
        }
    }
}
