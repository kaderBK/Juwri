<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\MessageDiscretion;
use Juwri\Modules\Menu\Mail\BackInStockMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Menu\Services\Retail\StockAlerts;
use Juwri\Modules\Menu\Services\Retail\StockState;
use Juwri\Modules\Menu\Services\Retail\VariantMatrix;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * P3-08 / N5: send the waiting back-in-stock requests of an item (variant null: the item's own stock came back) or
 * of one cell (the cell's requests and the item-level ones). Each request is claimed with one conditional UPDATE
 * (`waiting` → `sent`) before it is sent, so a second restock or a retried job never sends twice. Nothing is sent
 * while the store has the messages switched off or the stock is gone again (the requests keep waiting). WhatsApp
 * and SMS go out as marketing sends (consent / opt-outs honoured by Messaging); no contact is ever logged.
 */
class SendStockAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(public int $menuId, public ?int $variantId = null) {}

    public function handle(MessageQueue $queue): void
    {
        $item = Menu::query()->find($this->menuId);
        $store = $item !== null ? Store::query()->find($item->store_id) : null;
        if ($item === null || $store === null || ! StockAlerts::enabled($store)) {
            return;
        }
        $variant = $this->variantId !== null ? MenuVariant::query()->where('menu_id', $item->id)->find($this->variantId) : null;
        $available = $variant !== null
            ? StockState::forVariant($item, $variant)['state'] !== 'out'
            : StockState::forItem($item)['state'] !== 'out';
        if (! $available) {
            return;
        }

        $targets = [StockAlert::target((int) $item->id, null)];
        if ($variant !== null) {
            $targets[] = StockAlert::target((int) $item->id, (int) $variant->id);
        }
        $alerts = StockAlert::query()->where('store_id', $store->id)->whereIn('target', $targets)->where('status', 'waiting')->orderBy('id')->limit(500)->get();
        $url = route('publicView', ['slug' => $store->slug]).'#item-'.$item->public_id;
        $locale = App::getLocale();
        $sent = 0;
        foreach ($alerts as $alert) {
            $claimed = StockAlert::query()->whereKey($alert->id)->where('status', 'waiting')->update(['status' => 'sent', 'notified_at' => now()]);
            if ($claimed !== 1) {
                continue;
            }
            try {
                App::setLocale($alert->locale ?: $locale);
                $name = (string) $item->getRawOriginal('name');
                $cell = $alert->variant_id !== null && $variant !== null && (int) $alert->variant_id === (int) $variant->id
                    ? VariantMatrix::label($item, (array) $variant->cell()) : '';
                // P6-46: a discreet pharmacy never names the product in the message.
                $what = MessageDiscretion::itemLabel($store, $cell !== '' ? $name.' ('.$cell.')' : $name);
                $text = ___(':item is back in stock at :store: :url', ['item' => $what, 'store' => \App\Support\OutboundText::name((string) $store->title), 'url' => $url]);
                match ($alert->channel) {
                    'email' => Mail::to($alert->contact)->queue(new BackInStockMail((string) $store->title, $what, $url, $alert->locale ?: $locale)),
                    'whatsapp' => $queue->whatsApp($alert->contact, $text, (int) $store->id, 'campaign', true),
                    default => $queue->sms($alert->contact, $text, (int) $store->id, 'campaign', true),
                };
                $sent++;
            } catch (\Throwable $e) {
                StockAlert::query()->whereKey($alert->id)->update(['status' => 'failed']);
                Log::warning('stock_alert.failed', ['store_id' => $store->id, 'stock_alert_id' => $alert->id, 'error' => $e->getMessage()]);
            } finally {
                App::setLocale($locale);
            }
        }
        if ($sent > 0) {
            Log::info('stock_alert.sent', ['store_id' => $store->id, 'menu_id' => $item->id, 'count' => $sent]);
        }
    }
}
