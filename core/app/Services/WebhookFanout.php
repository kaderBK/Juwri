<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Juwri\Core\Models\Store;

/**
 * HPMS E10: the ONE-LINE webhook dispatch helper — the pinned cross-lane call
 * (§5). It copies the exact idiom every `order.created` site already uses
 * (WebhookService::dispatch wrapped in a self-guard) so a lane can fire a new
 * event in a single line without re-implementing the try/catch, and lane D can
 * call it from PosController/PosService for the shift + refund events.
 */
class WebhookFanout
{
    /**
     * Fire an event to a store owner's subscribed endpoints. Never throws.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function dispatch(int $userId, string $event, array $payload): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            WebhookService::dispatch($userId, $event, $payload);
        } catch (\Throwable $e) {
            // best-effort — webhook delivery must never break the caller
        }
    }

    /**
     * Fire an event for a store, resolving the owner user id from the restaurant.
     * Convenience for call sites that hold a store_id, not a user_id.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function forRestaurant(int $storeId, string $event, array $payload): void
    {
        if ($storeId <= 0) {
            return;
        }
        try {
            $ownerId = (int) Store::where('id', $storeId)->value('user_id');
            if ($ownerId > 0) {
                WebhookService::dispatch($ownerId, $event, $payload + ['store_id' => $storeId]);
            }
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
