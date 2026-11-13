<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Juwri\Core\Models\Store;

/**
 * PARITY-RUN follow-up #1 — quantify the storefront modifier under-charge.
 *
 * THIS COMMAND IS STRICTLY READ-ONLY. It opens no transaction, writes no row and
 * repairs nothing. Repairing history is a separate, owner-approved decision (and
 * a lossy one), so this only measures.
 *
 * ── The defect ────────────────────────────────────────────────────────────────
 * Before the WV.V fix, `StoreController@submitOrder` added a global-modifier option
 * price to a LOCAL `$amount` and wrote the picks to `order_items.order_note` as
 * text. It never wrote `order_items.options`, which is the only place
 * `Order::getTotalAttribute()` looks. So:
 *
 *   • `$amount` (WITH the upcharge) fed the confirmation screen, the WhatsApp/SMS
 *     message and the pay-online Transaction;
 *   • `Order::total` (WITHOUT it) is what the order record, the owner's Orders
 *     screen, the POS settle screen and the printed bill all show.
 *
 * ── Why the money is recoverable at all ───────────────────────────────────────
 * The note line was formatted as `Group: Option (+12.00)` — the upcharge is
 * embedded, and only when it was greater than zero. That is what makes THIS
 * measurement exact per line. It is NOT enough to repair the row automatically,
 * because the note records a formatted label rather than a group id + option
 * index, and a label can be renamed or duplicated.
 *
 * The pre-fix total multiplied by line quantity (`$amount += $optPrice * $qty`)
 * while the note carries the UNIT price, so exposure per line is parsed × qty.
 *
 * ── Reading the output ────────────────────────────────────────────────────────
 * The two lanes mean different things and must not be added together:
 *
 *   LOST      cash / counter / POS-settle / charge-to-room. Staff collected the
 *             displayed bill, which omitted the upcharge. This is real revenue
 *             that was never taken.
 *   MISMATCH  paid through a gateway. The customer WAS charged the quoted amount
 *             including the upcharge, so no revenue was lost — but the order
 *             record understates what the gateway took, which is a reconciliation
 *             and reporting discrepancy (and will not match a payout report).
 *
 * Usage:
 *   php artisan parity:modifier-undercharge
 *   php artisan parity:modifier-undercharge --since=2025-01-01 --csv=/tmp/exposure.csv
 */
class MeasureModifierUndercharge extends Command
{
    protected $signature = 'parity:modifier-undercharge
        {--since= : Only orders created on/after this date (Y-m-d)}
        {--until= : Only orders created before this date (Y-m-d)}
        {--store= : Restrict to one restaurant id}
        {--csv=   : Also write a per-order breakdown to this path}
        {--sample=5 : How many example orders to print per lane}';

    protected $description = 'READ-ONLY: quantify orders whose storefront modifier upcharges never reached Order::total.';

    /** `Group: Option (+1,234.56)` — the upcharge the pre-fix code formatted into the note. */
    private const UPCHARGE_RE = '/\(\+\s*([0-9][0-9,\.\s]*)\)/u';

    public function handle(): int
    {
        $this->line('');
        $this->info('Modifier under-charge — measurement only. Nothing will be written.');
        $this->line('');

        $rows = [];          // per order: [order_id, store_id, missing, lane, created_at]
        $scanned = 0;

        $q = OrderItem::query()
            ->whereNull('options')                 // post-fix lines carry the snapshot
            ->whereLike('order_note', '%(+%'); // cheap prefilter; parsing happens in PHP

        // Parsing in PHP rather than SQL is deliberate: no raw SQL means no
        // hardcoded table name (production prefixes them) and no MySQL-vs-SQLite
        // regex divergence — the exact trap that has bitten this codebase before.
        $q->with(['order:id,store_id,created_at,payment_gateway,payment_method,is_paid'])
            ->chunkById(500, function ($items) use (&$rows, &$scanned) {
                foreach ($items as $item) {
                    $scanned++;
                    $missing = $this->missingFor($item);
                    if ($missing <= 0) {
                        continue;
                    }

                    $order = $item->order;
                    if (! $order || ! $this->inWindow($order)) {
                        continue;
                    }

                    $id = (int) $order->id;
                    if (! isset($rows[$id])) {
                        $rows[$id] = [
                            'order_id' => $id,
                            'store_id' => (int) $order->store_id,
                            'missing' => 0.0,
                            'lane' => $this->lane($order),
                            'created' => (string) $order->created_at,
                        ];
                    }
                    $rows[$id]['missing'] += $missing;
                }
            });

        if (! $rows) {
            $this->line("Scanned {$scanned} candidate line(s). <info>No affected orders found.</info>");
            $this->line('');

            return self::SUCCESS;
        }

        $this->report($rows, $scanned);

        if ($path = $this->option('csv')) {
            $this->writeCsv($rows, $path);
        }

        return self::SUCCESS;
    }

    /** Sum every `(+N)` on the line's note, times the line quantity. */
    private function missingFor(OrderItem $item): float
    {
        if (! preg_match_all(self::UPCHARGE_RE, (string) $item->order_note, $m)) {
            return 0.0;
        }

        $unit = 0.0;
        foreach ($m[1] as $raw) {
            // number_format() output: strip thousands separators and stray space.
            $unit += (float) str_replace([',', ' '], '', $raw);
        }

        return $unit * max(1, (int) $item->quantity);
    }

    private function inWindow(Order $order): bool
    {
        if (($store = $this->option('store')) && (int) $order->store_id !== (int) $store) {
            return false;
        }
        if (($since = $this->option('since')) && (string) $order->created_at < $since) {
            return false;
        }
        if (($until = $this->option('until')) && (string) $order->created_at >= $until) {
            return false;
        }

        return true;
    }

    /**
     * A non-empty gateway means the customer was charged the quoted amount, so the
     * money arrived and only the record is wrong. Everything else was collected by
     * hand from the displayed bill, which is where the revenue actually went missing.
     */
    private function lane(Order $order): string
    {
        return trim((string) $order->payment_gateway) !== '' ? 'MISMATCH' : 'LOST';
    }

    private function report(array $rows, int $scanned): void
    {
        $byStore = [];
        foreach ($rows as $r) {
            $k = $r['store_id'].'|'.$r['lane'];
            $byStore[$k] ??= ['store_id' => $r['store_id'], 'lane' => $r['lane'], 'orders' => 0, 'missing' => 0.0];
            $byStore[$k]['orders']++;
            $byStore[$k]['missing'] += $r['missing'];
        }

        $names = Store::whereIn('id', array_column($rows, 'store_id'))->pluck('title', 'id');

        $table = [];
        foreach ($byStore as $g) {
            $table[] = [
                $g['store_id'],
                mb_strimwidth((string) ($names[$g['store_id']] ?? '?'), 0, 28, '…'),
                $g['lane'],
                $g['orders'],
                number_format($g['missing'], 2),
                (string) (store_options($g['store_id'], 'currency_code') ?: ''),
            ];
        }
        usort($table, fn ($a, $b) => (float) str_replace(',', '', $b[4]) <=> (float) str_replace(',', '', $a[4]));

        $this->table(['store', 'name', 'lane', 'orders', 'missing', 'ccy'], $table);

        $lost = array_sum(array_map(fn ($r) => $r['lane'] === 'LOST' ? $r['missing'] : 0, $rows));
        $mism = array_sum(array_map(fn ($r) => $r['lane'] === 'MISMATCH' ? $r['missing'] : 0, $rows));
        $lostN = count(array_filter($rows, fn ($r) => $r['lane'] === 'LOST'));
        $mismN = count($rows) - $lostN;
        $dates = array_column($rows, 'created');
        sort($dates);

        $this->line('');
        $this->line("  Candidate lines scanned : {$scanned}");
        $this->line('  Affected orders         : '.count($rows).'  ('.count(array_unique(array_column($rows, 'store_id'))).' store(s))');
        $this->line('  Date range              : '.($dates[0] ?? '?').'  →  '.(end($dates) ?: '?'));
        $this->line('');
        $this->line("  <fg=red>LOST      {$lostN} order(s), ".number_format($lost, 2).'</> — collected by hand from a bill that omitted the upcharge. Real revenue never taken.');
        $this->line("  <fg=yellow>MISMATCH  {$mismN} order(s), ".number_format($mism, 2).'</> — gateway charged the quoted amount; the money arrived, the ORDER RECORD understates it.');
        $this->line('');
        $this->comment('  Amounts are summed per store in that store\'s own currency — do NOT add the column across currencies.');
        $this->comment('  Repairing history is a separate owner decision and is lossy: the note records a formatted');
        $this->comment('  label, not a group id + option index, so a renamed or duplicated option cannot be resolved.');
        $this->line('');

        $sample = max(0, (int) $this->option('sample'));
        foreach (['LOST', 'MISMATCH'] as $lane) {
            $ex = array_slice(array_values(array_filter($rows, fn ($r) => $r['lane'] === $lane)), 0, $sample);
            if (! $ex) {
                continue;
            }
            $this->line("  example {$lane} orders: ".implode(', ', array_map(
                fn ($r) => '#'.$r['order_id'].' ('.number_format($r['missing'], 2).')',
                $ex
            )));
        }
        $this->line('');
    }

    private function writeCsv(array $rows, string $path): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['order_id', 'store_id', 'lane', 'missing', 'created_at'], escape: '\\');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['order_id'], $r['store_id'], $r['lane'], round($r['missing'], 3), $r['created']], escape: '\\');
        }
        fclose($fh);
        $this->info('  per-order breakdown written to '.$path);
        $this->line('');
    }
}
