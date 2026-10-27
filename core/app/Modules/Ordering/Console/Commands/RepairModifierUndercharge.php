<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Console\Commands;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\LineOptionsSnapshot;

/**
 * PARITY-RUN follow-up #2 — repair historical orders that under-charged a
 * modifier.
 *
 * This rewrites a placed order's total. It is a business action with accounting,
 * refund and dispute consequences, and it is LOSSY, so it is built to be as
 * conservative as a repair can be:
 *
 *   • DRY-RUN by default. It writes nothing without `--commit`.
 *   • ONE store at a time (`--store` required). No blanket sweep.
 *   • LOST lane only. It never touches an order paid through a gateway — those
 *     customers were charged the quoted amount, so the money arrived and only
 *     the record understates it; "repairing" it would invent a second charge.
 *   • The `order_note` is NEVER modified. It is the only surviving record of the
 *     original pick and stays as the audit trail.
 *   • It REFUSES to guess. A note line whose `(+N)` cannot be parsed into a clean
 *     `Group: Option (+price)` is SKIPPED and reported, not approximated.
 *   • It skips any line that already carries an `options` snapshot, so a line the
 *     forward fix (or a prior run) already covered is never double-counted.
 *
 * What it writes: the modifier picks re-derived from the note, into
 * `order_items.options` in the exact shape `Order::getTotalAttribute()` sums —
 * so the order re-totals to include the upcharge. The reconstructed snapshot
 * carries `group_id => null` and `repaired => true`, because the note holds a
 * formatted LABEL, not the group id + option index — that is the lossy part, and
 * it is marked so nothing downstream mistakes it for an original snapshot.
 *
 * ── Consequence the owner must accept before running --commit ──────────────────
 * Raising a settled order's total makes it report as worth more than was
 * collected: `amount_due` on a cash order becomes the shortfall. That is the
 * point (it surfaces revenue that was never taken) but it changes historical
 * financials. Run `parity:modifier-undercharge` first, decide, then repair one
 * store and reconcile it before doing more.
 */
class RepairModifierUndercharge extends Command
{
    protected $signature = 'parity:modifier-repair
        {--store=  : Restaurant id to repair (REQUIRED — no blanket sweep)}
        {--commit  : Actually write. Without this it is a dry run.}
        {--since=  : Only orders created on/after this date (Y-m-d)}
        {--until=  : Only orders created before this date (Y-m-d)}
        {--limit=0 : Cap the number of orders touched (0 = no cap)}';

    protected $description = 'Repair historical LOST-lane orders whose modifier upcharge never reached Order::total. Dry-run unless --commit.';

    /** `Group: Option (+1,234.56)` — the exact line the pre-fix code wrote. */
    private const LINE_RE = '/^(?<group>.+?):\s*(?<option>.+?)\s*\(\+\s*(?<price>[0-9][0-9,\.\s]*)\)\s*$/u';

    /** A bare `(+N)` with no surrounding `Group: Option` — parse is ambiguous. */
    private const BARE_UPCHARGE_RE = '/\(\+\s*[0-9]/u';

    public function handle(): int
    {
        $store = $this->option('store');
        if ($store === null || $store === '') {
            $this->error('--store is required. This command never sweeps every store at once.');

            return self::INVALID;
        }
        $store = (int) $store;
        if (! Store::whereKey($store)->exists()) {
            $this->error("Store {$store} not found.");

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $limit = (int) $this->option('limit');

        $this->line('');
        $this->info(($commit ? 'REPAIR' : 'DRY RUN')." · store {$store}".($commit ? '' : ' · nothing will be written'));
        $this->line('');

        $repaired = [];
        $skipped = [];
        $touched = 0;

        $query = OrderItem::query()
            ->whereNull('options')
            ->whereLike('order_note', '%(+%')
            ->whereHas('order', fn ($q) => $q->where('store_id', $store))
            ->with(['order:id,store_id,created_at,payment_gateway']);

        foreach ($query->cursor() as $item) {
            $order = $item->order;
            if (! $order || ! $this->inWindow($order)) {
                continue;
            }
            // LOST lane only — a gateway means the money already arrived.
            if (trim((string) $order->payment_gateway) !== '') {
                continue;
            }

            [$snapshot, $unit, $reason] = $this->reconstruct($item);
            if ($reason !== null) {
                $skipped[] = ['order' => $order->id, 'line' => $item->id, 'reason' => $reason];

                continue;
            }
            if ($unit <= 0) {
                continue; // only free options on this line — nothing to recover
            }

            $before = (float) $order->total;
            $repaired[] = [
                'order' => $order->id,
                'line' => $item->id,
                'qty' => (int) $item->quantity,
                'recovered' => $unit * max(1, (int) $item->quantity),
                'before' => $before,
                'snapshot' => $snapshot,
                'currency' => $item->minorMoneyCurrency(),
            ];

            if ($limit > 0 && count($repaired) >= $limit) {
                break;
            }
        }

        if ($commit && $repaired) {
            DB::transaction(function () use ($repaired, &$touched) {
                foreach ($repaired as $r) {
                    // order_note is left untouched — it is the audit trail.
                    // P1-06: the snapshot is stored with minor-unit prices in the line's currency.
                    OrderItem::whereKey($r['line'])->update([
                        'options' => json_encode(LineOptionsSnapshot::toStorage($r['snapshot'], $r['currency'])),
                    ]);
                    $touched++;
                }
            });
        }

        $this->report($store, $repaired, $skipped, $commit, $touched);

        // A skip is not a failure (it is reported for a human), but if EVERY
        // candidate was unparseable the operator should know the run did nothing.
        return self::SUCCESS;
    }

    /**
     * Re-derive the modifier picks from the note text.
     *
     * @return array{0: array<int,array>, 1: float, 2: ?string} [snapshot, unit total, skip reason|null]
     */
    private function reconstruct(OrderItem $item): array
    {
        $groups = [];
        $unit = 0.0;

        foreach (preg_split('/\r?\n/', (string) $item->order_note) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // A line that carries an upcharge marker MUST parse cleanly, or we
            // refuse the whole line item rather than guess a total.
            if (preg_match(self::BARE_UPCHARGE_RE, $line) && ! preg_match(self::LINE_RE, $line)) {
                return [[], 0.0, 'unparseable upcharge line: "'.$this->clip($line).'"'];
            }
            if (! preg_match(self::LINE_RE, $line, $m)) {
                continue; // a plain note line or a free option — no money
            }

            $price = (float) str_replace([',', ' '], '', $m['price']);
            if ($price <= 0) {
                continue;
            }
            $title = trim($m['group']);
            $groups[$title] ??= ['group_id' => null, 'title' => $title, 'selected' => [], 'repaired' => true];
            $groups[$title]['selected'][] = ['option' => trim($m['option']), 'price' => $price];
            $unit += $price;
        }

        return [array_values($groups), $unit, null];
    }

    private function inWindow(Order $order): bool
    {
        if (($since = $this->option('since')) && (string) $order->created_at < $since) {
            return false;
        }
        if (($until = $this->option('until')) && (string) $order->created_at >= $until) {
            return false;
        }

        return true;
    }

    private function clip(string $v): string
    {
        return mb_strlen($v) > 40 ? mb_substr($v, 0, 39).'…' : $v;
    }

    private function report(int $store, array $repaired, array $skipped, bool $commit, int $touched): void
    {
        $ccy = (string) (store_options($store, 'currency_code') ?: '');

        if ($repaired) {
            $rows = array_map(fn ($r) => [
                '#'.$r['order'],
                $r['line'],
                $r['qty'],
                number_format($r['recovered'], 2),
                number_format($r['before'], 2).' → '.number_format($r['before'] + $r['recovered'], 2),
            ], array_slice($repaired, 0, 40));
            $this->table(['order', 'line', 'qty', 'recovered', 'total before → after'], $rows);
            if (count($repaired) > 40) {
                $this->line('  … and '.(count($repaired) - 40).' more');
            }
        }

        $total = array_sum(array_column($repaired, 'recovered'));
        $this->line('');
        $this->line('  Orders to repair : '.count($repaired));
        $this->line('  Total recovered  : '.number_format($total, 2).' '.$ccy);
        if ($skipped) {
            $this->warn('  Skipped (unparseable, NOT guessed): '.count($skipped));
            foreach (array_slice($skipped, 0, 10) as $s) {
                $this->line("    order #{$s['order']} line {$s['line']}: {$s['reason']}");
            }
        }
        $this->line('');

        if (! $commit) {
            $this->comment('  DRY RUN — nothing was written. Re-run with --commit to apply.');
            $this->comment('  order_note is preserved as the audit trail; the repair marks each snapshot repaired=true.');
        } else {
            $this->info("  COMMITTED — {$touched} line(s) updated. order_note left intact.");
            $this->comment('  Reconcile this store before repairing another: a raised total makes a cash order');
            $this->comment('  report a positive amount_due (the shortfall it never collected).');
        }
        $this->line('');
    }
}
