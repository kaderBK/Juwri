<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Retail;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\StockAlertRequests;
use Juwri\Modules\Menu\Jobs\SendStockAlertsJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Messaging\Contracts\WhatsApp;

/**
 * P3-08 / N5: back-in-stock messages.
 *
 *  - {@see subscribe()}: a shopper asks to be told when an out-of-stock item (or one variant) is back. Only while the
 *    store has back-in-stock messages on (`back_in_stock_alerts`, on by default, the store can switch it off), only
 *    for something that is out now, and only with the shopper's marketing opt-in — it is a marketing message (N5);
 *    for WhatsApp the opt-in is recorded with the store's WhatsApp consent too. One request per contact and item /
 *    variant (asking again keeps the first).
 *  - {@see restocked()}: stock came back (a restore, an owner edit, a sold-out flag lifted) — the waiting requests of
 *    that item / variant are sent by a queued job after the transaction commits ({@see SendStockAlertsJob}), each
 *    claimed once (`waiting` → `sent`), so two restocks never send twice.
 */
final class StockAlerts implements StockAlertRequests
{
    /** Waiting requests one contact may hold at one store. */
    public const MAX_PER_CONTACT = 20;

    /**
     * @return StockAlert the request (the existing one when the contact already asked)
     *
     * @throws \DomainException alerts_off | in_stock | consent_required | too_many | invalid_contact
     */
    public function subscribe(Store $store, Menu $item, ?MenuVariant $variant, string $channel, string $contact, bool $marketingOptIn, ?int $customerId = null, ?string $locale = null): StockAlert
    {
        if ((int) $item->store_id !== (int) $store->id || ($variant !== null && (int) $variant->menu_id !== (int) $item->id)) {
            throw new \DomainException('not_found');
        }
        if (! self::enabled($store)) {
            throw new \DomainException('alerts_off');
        }
        if (! $marketingOptIn) {
            throw new \DomainException('consent_required');
        }
        $state = $variant !== null ? StockState::forVariant($item, $variant) : StockState::forItem($item);
        if ($state['state'] !== 'out' && ! ($variant === null && $this->allCellsOut($item))) {
            throw new \DomainException('in_stock');
        }
        if (! in_array($channel, StockAlert::CHANNELS, true)) {
            throw new \DomainException('invalid_contact');
        }
        $contact = trim($contact);
        if ($channel === 'email' ? filter_var($contact, FILTER_VALIDATE_EMAIL) === false : preg_match('/^\+?[0-9 ()-]{6,24}$/', $contact) !== 1) {
            throw new \DomainException('invalid_contact');
        }

        $hash = StockAlert::hashContact($channel, $contact);
        $target = StockAlert::target((int) $item->id, $variant?->id);
        $existing = StockAlert::query()->where('store_id', $store->id)->where('target', $target)->where('contact_hash', $hash)->first();
        if ($existing !== null && $existing->status === 'waiting') {
            return $existing;
        }
        if (StockAlert::query()->where('store_id', $store->id)->where('contact_hash', $hash)->where('status', 'waiting')->count() >= self::MAX_PER_CONTACT) {
            throw new \DomainException('too_many');
        }

        $values = [
            'menu_id' => $item->id, 'variant_id' => $variant?->id, 'customer_id' => $customerId, 'channel' => $channel,
            'contact' => $contact, 'locale' => $locale !== null ? mb_substr($locale, 0, 8) : null, 'status' => 'waiting',
            'opted_in_at' => now(), 'notified_at' => null,
        ];
        if ($existing !== null) {
            $existing->forceFill($values)->save(); // asked again after an earlier message: wait for the next restock

            return $existing;
        }
        try {
            $alert = DB::transaction(fn () => StockAlert::create($values + ['store_id' => $store->id, 'target' => $target, 'contact_hash' => $hash]));
        } catch (QueryException) {
            // a concurrent twin with the same contact won the unique index: that one is the request
            return StockAlert::query()->where('store_id', $store->id)->where('target', $target)->where('contact_hash', $hash)->firstOrFail();
        }
        if ($channel === 'whatsapp') {
            app(WhatsApp::class)->optInMarketing($contact, (int) $store->id, 'restock');
        }

        return $alert;
    }

    public static function enabled(Store $store): bool
    {
        return StoreSettings::bool((int) $store->id, 'back_in_stock_alerts', true);
    }

    /** Stock of the item (variant null) or one cell came back: send its waiting requests once this commits. */
    public static function restocked(int $menuId, ?int $variantId): void
    {
        if (! StockAlert::query()->where('menu_id', $menuId)->where('status', 'waiting')->exists()) {
            return;
        }
        SendStockAlertsJob::dispatch($menuId, $variantId)->afterCommit();
    }

    /** An item whose every cell is out counts as out for an item-level request. */
    private function allCellsOut(Menu $item): bool
    {
        $cells = VariantMatrix::cells($item)->filter(fn (MenuVariant $v) => $v->active);
        if ($cells->isEmpty()) {
            return false;
        }

        return $cells->every(fn (MenuVariant $v) => StockState::forVariant($item, $v)['state'] === 'out');
    }
}
