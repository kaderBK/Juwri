<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models\Concerns;

use App\Services\WebhookService;
use Juwri\Core\Models\Store;

/**
 * MCP-expansion P0-4: the single choke point for the `menu.updated` webhook.
 *
 * Applied to the Menu and MenuCategory models so ANY write path — dashboard,
 * REST/MCP tools, CSV import, POS — fires exactly one event per change, without
 * scattering dispatch calls across every controller. The consuming model must
 * declare `protected string $menuWebhookEntity = 'item'|'category';`.
 *
 * Payload: {store_id, change: created|updated|deleted, entity, id}. The
 * owner (webhook subscriber) is resolved from the restaurant, never trusted from
 * input. Delivery is queued + best-effort inside WebhookService, and a dispatch
 * failure can never break the write (guarded there). Debounce is intentionally
 * out of scope for v1 (see spec §2 P0-4).
 */
trait FiresMenuWebhook
{
    /** When true, model writes do NOT fire menu.updated (see withoutMenuWebhook). */
    protected static bool $suppressMenuWebhook = false;

    public static function bootFiresMenuWebhook(): void
    {
        static::created(fn ($model) => $model->fireMenuWebhook('created'));
        static::updated(fn ($model) => $model->fireMenuWebhook('updated'));
        static::deleted(fn ($model) => $model->fireMenuWebhook('deleted'));
    }

    /**
     * Run $callback with menu.updated suppressed. Used by the order pipeline's
     * automatic stock deduction (Menu::deductStock) — those availability writes
     * already surface through order.* events, so re-broadcasting them as
     * menu.updated is noise for a busy store. Deliberate owner/staff edits
     * (dashboard, MCP, sold-out toggle) still fire normally.
     */
    public static function withoutMenuWebhook(callable $callback)
    {
        $previous = static::$suppressMenuWebhook;
        static::$suppressMenuWebhook = true;
        try {
            return $callback();
        } finally {
            static::$suppressMenuWebhook = $previous;
        }
    }

    protected function fireMenuWebhook(string $change): void
    {
        if (static::$suppressMenuWebhook) {
            return;
        }
        $storeId = (int) ($this->store_id ?? 0);
        if ($storeId <= 0) {
            return;
        }

        $ownerId = (int) Store::where('id', $storeId)->value('user_id');
        if ($ownerId <= 0) {
            return;
        }

        WebhookService::dispatch($ownerId, 'menu.updated', [
            'store_id' => $storeId,
            'change' => $change,
            'entity' => $this->menuWebhookEntity ?? 'item',
            'id' => (int) $this->getKey(),
            // HPMS E10: additive — the changed item id(s) as a list, so consumers can
            // diff without special-casing the singular `id`.
            'item_ids' => [(int) $this->getKey()],
        ]);
    }
}
