<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Console\Commands;

use App\Models\Ingredient;
use App\Models\StoreOption;
use App\Support\OutboundText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Inventory\Mail\LowStockAlertMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * HPMS E8: daily per-store low-stock digest. For every store with
 * `low_stock_alerts` on (default '1'), collect the menu items and ingredients at
 * or below their threshold and, IF something has NEWLY crossed since the last
 * digest, fire ONE owner notification (title escaped, link to the inventory
 * report) plus — when `low_stock_whatsapp`='1' — a WhatsApp to the store number.
 * Dedup: the current low set is persisted every run, so a stable/shrinking set
 * never re-notifies, while a fresh crossing (including a re-cross after restock)
 * does.
 */
class LowStockDigest extends Command
{
    protected $signature = 'inventory:low-stock-digest';

    protected $description = 'Daily per-store low-stock digest (notification + optional WhatsApp) (E8)';

    public function handle(): int
    {
        $sent = 0;
        Store::query()->select(['id'])->chunkById(200, function ($stores) use (&$sent) {
            foreach ($stores as $store) {
                try {
                    if ($this->digestForStore((int) $store->id)) {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        $this->info("Low-stock digests sent: {$sent}");

        return self::SUCCESS;
    }

    private function digestForStore(int $storeId): bool
    {
        if ((string) store_options($storeId, 'low_stock_alerts', '1') === '0') {
            return false;
        }

        $lowItems = Menu::where('store_id', $storeId)
            ->lowStock()
            ->get(['id', 'name']);

        $lowIngredients = Ingredient::where('store_id', $storeId)
            ->where('active', 1)
            ->where('par_level', '>', 0)
            ->whereColumn('current_stock', '<=', 'par_level')
            ->get(['id', 'name']);

        $ids = array_merge(
            $lowItems->map(fn ($m) => 'm:'.$m->id)->all(),
            $lowIngredients->map(fn ($i) => 'i:'.$i->id)->all()
        );
        sort($ids);

        $prev = $this->previousState($storeId);
        $newlyCrossed = array_diff($ids, $prev);

        // Always persist the current low-id set when nothing is newly crossed
        // — restocks drop out so a future re-cross fires again, the case the
        // dedup hinges on. No channels attempted, so no state-advance gate.
        if (empty($ids) || empty($newlyCrossed)) {
            StoreOption::updateStoreOption($storeId, 'low_stock_digest_state', json_encode($ids));

            return false;
        }

        $count = count($ids);
        $names = $lowItems->pluck('name')->merge($lowIngredients->pluck('name'))->filter()->take(5)->all();

        // Notification (title escaped — a name never lands in the title, but escape
        // the []() control chars defensively, per the platform rule).
        $title = trans_choice(':count item is low on stock|:count items are low on stock', $count, ['count' => $count]);
        $title = str_replace(['[', ']', '(', ')'], ['\\[', '\\]', '\\(', '\\)'], $title);

        // P6-280: state advances ONLY when at least one channel accepted the
        // message. If every attempted channel threw, leave the previous state
        // intact so the next run re-attempts with the same newly-crossed set.
        $anySent = false;

        try {
            CreateNotificationJob::dispatch(
                $title,
                'low_stock',
                Route::has('inventory.reports.index') ? route('inventory.reports.index', [], false) : null
            );
            $anySent = true;
        } catch (\Throwable $e) {
            report($e);
        }

        // The store OWNER's e-mail (the platform notification above is the admin panel's, with no store attached): only
        // this store's owner, only this store's items, in the owner's own language.
        if ($this->emailOwner($storeId, $count, $names)) {
            $anySent = true;
        }

        // WhatsApp to the store number (opt-in).
        if ((string) store_options($storeId, 'low_stock_whatsapp', '0') === '1') {
            $store = Store::find($storeId);
            $to = $store ? whatsapp_normalize_number(storefront_whatsapp_number($store, null, false)) : '';
            if ($to !== '') {
                $msg = $title.($names ? ': '.implode(', ', array_map(fn ($n) => OutboundText::name((string) $n, 80), $names)) : '');
                try {
                    app(MessageQueue::class)->whatsApp($to, $msg, $storeId, 'low_stock');
                    $anySent = true;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        if ($anySent) {
            StoreOption::updateStoreOption($storeId, 'low_stock_digest_state', json_encode($ids));

            return true;
        }

        // Every channel threw — leave the previous state intact; the next run
        // re-attempts with the same newly-crossed set.
        return false;
    }

    /**
     * P6-280: return true when the e-mail was sent/queued without throwing, so
     * the digest state-advance gate can see this channel's outcome. False on
     * throw (reported) and on the "no owner / no e-mail" no-op.
     *
     * @param  list<string>  $names
     */
    private function emailOwner(int $storeId, int $count, array $names): bool
    {
        try {
            $store = Store::query()->with('user')->find($storeId);
            $owner = $store?->user;
            if (! $store || ! $owner || (string) $owner->email === '') {
                return false;
            }
            $mail = new LowStockAlertMail(
                (string) $owner->name,
                (string) $store->title,
                $count,
                array_map('strval', $names),
                Route::has('inventory.reports.index') ? route('inventory.reports.index') : null,
            );
            if (! empty($owner->preferred_locale)) {
                $mail->locale((string) $owner->preferred_locale);
            }
            Mail::to($owner->email)->send($mail);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The low-id set persisted at the last run. Read DIRECTLY from the DB (not the
     * memoized store_options) so a read-after-write within one process — the dedup's
     * whole point — sees the value this run just wrote.
     *
     * @return array<int,string>
     */
    private function previousState(int $storeId): array
    {
        $raw = StoreSettings::legacy($storeId, 'low_stock_digest_state');
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
