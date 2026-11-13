<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use App\Models\Order;
use App\Models\PosShift;
use App\Services\KdsService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Tables\Services\TableMergeService;

/**
 * ESC/POS document renderer.
 *
 * N8-P1 shipped this for the Sunmi kiosk: one document (a 72 mm receipt), raw
 * UTF-8 passthrough for Arabic. PARITY-RUN T1-2 generalises it without moving
 * the kiosk's cheese — `__construct(int $width = 48)` and
 * `render(Order, ?bool $openDrawer)` keep their exact signatures and byte
 * output, and everything new hangs off them:
 *
 *   • documents — receipt · per-station KOT · shift-close (Z) summary · test page;
 *   • widths    — 80 mm (48 cols / 576 dots) and 57 mm (32 cols / 384 dots);
 *   • RTL       — {@see ArabicShaper} contextual forms + visual reorder, drawn
 *                 as a raster line when a TTF is configured (no Arabic font
 *                 ships with the repo) and emitted as shaped text otherwise;
 *   • logo      — the store's image rasterised to the paper width.
 *
 * MONEY CONTRACT: the totals block mirrors Order::getTotalAttribute() exactly —
 * subtotal includes snapshotted choice-group options, tax is computed on the
 * BARE subtotal, and the discount is subtracted last, after tax. A receipt that
 * prints a total the server never charged is the bug this codebase has paid for
 * repeatedly (rev/parity + theme-totals-contract); the printed lines must add up.
 */
class EscPosRenderer
{
    // ESC/POS command constants.
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private const INIT = self::ESC.'@';

    private const ALIGN_LEFT = self::ESC.'a0';

    private const ALIGN_CENTER = self::ESC.'a1';

    private const ALIGN_RIGHT = self::ESC.'a2';

    private const BOLD_ON = self::ESC.'E1';

    private const BOLD_OFF = self::ESC.'E0';

    private const CUT = self::GS.'V0';

    private const LF = "\x0A";

    /** Font-A columns → printable dot width of the head. */
    private const DOTS = [48 => 576, 32 => 384];

    private int $width; // characters per line at font A (72mm ≈ 48, 58mm ≈ 32)

    /** Render Arabic/Hebrew lines right-aligned and visually reordered. */
    private bool $rtl = false;

    /** Absolute path to a TTF used for raster lines (required for Arabic glyphs). */
    private ?string $fontPath = null;

    /** Absolute path to a logo image rasterised above the header. */
    private ?string $logoPath = null;

    public function __construct(int $width = 48)
    {
        $this->width = $width;
    }

    // ── construction ─────────────────────────────────────────────────────────

    /** Build for a paper width in millimetres (80 or 57). */
    public static function forWidthMm(int $mm): self
    {
        return new self((PrinterProfile::WIDTHS[$mm] ?? 48));
    }

    /**
     * Build for a configured profile, picking up the store's print options:
     *   print_rtl        — force RTL layout (else auto-detected from the text)
     *   print_rtl_font   — TTF path used to raster Arabic lines
     *   print_logo       — print the store image at the top of receipts
     */
    public static function forProfile(PrinterProfile $profile): self
    {
        $renderer = self::forWidthMm((int) $profile->width_mm);
        $rid = (int) $profile->store_id;

        if (store_options($rid, 'print_rtl')) {
            $renderer->rtl(true);
        }
        if ($font = (string) (store_options($rid, 'print_rtl_font') ?: '')) {
            $renderer->font($font);
        }
        if (store_options($rid, 'print_logo')) {
            $renderer->logo(self::storeLogoPath($rid));
        }

        return $renderer;
    }

    public function rtl(bool $rtl = true): self
    {
        $this->rtl = $rtl;

        return $this;
    }

    public function font(?string $path): self
    {
        $this->fontPath = $path && is_file($path) ? $path : null;

        return $this;
    }

    public function logo(?string $path): self
    {
        $this->logoPath = $path && is_file($path) ? $path : null;

        return $this;
    }

    /** Columns this renderer lays out to. */
    public function columns(): int
    {
        return $this->width;
    }

    /**
     * WV.V — the table line for a ticket, or null for a non-table order.
     *
     * When T4 has merged this table into a party the paper must say so, or the
     * runner carries "Table 1" to a party that is now sitting across 1 + 2 and
     * the cashier settles against a bill that names one table.
     * `partyLabelForOrder()` already returns the party label for the primary
     * table and null for everything else — including when the merge feature is
     * off — so this degrades to the bare number it always printed.
     */
    private function tableLabel(Order $order): ?string
    {
        if ($order->table_number === null || $order->table_number === '') {
            return null;
        }

        try {
            $party = TableMergeService::partyLabelForOrder($order);
        } catch (\Throwable $e) {
            $party = null;
        }

        return $party ?: __('Table').' '.$order->table_number;
    }

    // ── documents ────────────────────────────────────────────────────────────

    /**
     * The customer receipt. Signature frozen for the kiosk endpoint
     * (KioskPrintController) — do not change it.
     */
    public function render(Order $order, ?bool $openDrawer = false, bool $reprint = false): string
    {
        $store = Store::find($order->store_id);
        $opts = store_options((int) $order->store_id);
        $currency = (string) (store_options((int) $order->store_id, 'currency_code') ?: 'SAR');

        $out = self::INIT;
        $out .= $this->logoBlock();
        $out .= self::ALIGN_CENTER.self::BOLD_ON.$this->line((string) ($store->title ?? '')).self::BOLD_OFF;
        if ($reprint) {
            $out .= self::ALIGN_CENTER.self::BOLD_ON.$this->line('*** '.__('REPRINT').' ***').self::BOLD_OFF;
        }
        if ($store && $store->address) {
            $out .= $this->line((string) $store->address);
        }
        if ($store && $store->phone) {
            $out .= $this->line((string) $store->phone);
        }
        $out .= $this->sep();

        $out .= self::ALIGN_LEFT;
        $out .= $this->line(__('Order').' #'.$order->id);
        $type = order_type_label((string) $order->type);
        if ($tableLabel = $this->tableLabel($order)) {
            $type .= ' · '.$tableLabel;
        }
        $out .= $this->line($type);
        $out .= $this->line((string) optional($order->created_at)->format('Y-m-d H:i'));
        $out .= $this->sep();

        $subtotal = 0.0;
        foreach ($order->items as $it) {
            /* PARITY-RUN T6: a combo line has NO menu row (item_id is NULL by
               design) — the old `continue` would have skipped it, printing a
               receipt whose subtotal did not reconcile with the Total line. That
               is exactly the bug T1-2 fixed for choice groups; a combo is the
               same class. Its whole price is the `options` snapshot, so it prints
               as one priced line with its components listed beneath. */
            if ($it->isCombo()) {
                $snap = $it->comboSnapshot();
                $lineTotal = $it->snapshotUnitPrice() * (int) $it->quantity;
                $subtotal += $lineTotal;
                $out .= $this->cols($it->quantity.'x '.($it->displayName() ?: '-'), $this->money($lineTotal, $currency));
                foreach ($snap['components'] as $c) {
                    $label = '  • '.$c['name'];
                    if ((float) $c['price'] > 0) {
                        $out .= $this->cols($label, $this->money((float) $c['price'] * (int) $it->quantity, $currency));
                    } else {
                        $out .= $this->line($label);
                    }
                }

                continue;
            }

            $menu = $it->menu;
            if (! $menu) {
                continue;
            }
            /* WV.V — T5 × T1 cross-track regression. `Order::getTotalAttribute()`
               lets a snapshotted `unit_price` (a happy-hour / day-part price
               resolved at ORDER time) REPLACE the derived menu/variant price.
               This renderer still derived it, so a receipt for a scheduled-price
               order printed a Subtotal + Tax that could not reconcile with its
               own Total line — the exact defect T1-2 fixed for choice groups,
               reintroduced when T5 landed the snapshot a wave later. NULL on
               every line no schedule touched, so this is bit-identical there. */
            $price = $it->unit_price !== null
                ? (float) $it->unit_price
                : (float) ($it->variation && $it->variant ? $it->variant->price : ($menu->price ?? 0));
            $lineTotal = $price * (int) $it->quantity;
            $subtotal += $lineTotal;
            $name = $it->quantity.'x '.($menu?->getRawOriginal('name') ?? '-');
            $out .= $this->cols($name, $this->money($lineTotal, $currency));
            foreach ($it->itemExtras as $ex) {
                if ($ex->extra) {
                    // P4-01: the modifier price SNAPSHOT the order charged (live price only for a pre-snapshot row)
                    $exTotal = (float) ($ex->price ?? $ex->extra->price) * (int) $it->quantity;
                    $subtotal += $exTotal;
                    $out .= $this->cols('  + '.$ex->extra->getRawOriginal('title'), $this->money($exTotal, $currency));
                }
            }
            // T1-2: snapshotted choice-group options are part of the price the
            // server charges (Order::getTotalAttribute) — they used to be missing
            // here, so a receipt for an order with choice groups did not add up.
            foreach ((array) $it->options as $grp) {
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $optTotal = (float) ($opt['price'] ?? 0) * (int) $it->quantity;
                    if ($optTotal <= 0) {
                        continue;
                    }
                    $subtotal += $optTotal;
                    $out .= $this->cols('  + '.(string) ($opt['option'] ?? ''), $this->money($optTotal, $currency));
                }
            }
            // P3-07: the guest's own line note ("Name on the cup") is on the receipt too (customerNote() has no control bytes)
            foreach (array_filter(explode("\n", $it->customerNote())) as $noteLine) {
                $out .= $this->line('   ! '.$noteLine);
            }
        }
        $out .= $this->sep();

        $out .= $this->cols(__('Subtotal'), $this->money($subtotal, $currency));

        // P6-186 (J44): the order's own VAT (Order::computeMoney — the VAT engine's per-line or whole-order VAT, else the
        // legacy tax), the same number as the invoice and the journal. It used to re-run the legacy formula, so a
        // VAT-engine order printed a different VAT than its invoice when a discount or a taxed fee applied.
        $tax = (float) $order->computeMoney()['tax'];
        if ($tax > 0) {
            $out .= $this->cols(__('Tax'), $this->money($tax, $currency));
        }
        // Bug batch 4 · 3: the fee the order charges (a marketplace order's quoted zone fee).
        if (($delivery = $order->deliveryCharge($opts)) > 0) {
            $out .= $this->cols(__('Delivery'), $this->money($delivery, $currency));
        }
        $tray = Order::trayCharge($order->type, $subtotal, $order->store_id);
        if ($tray > 0) {
            $out .= $this->cols(__('Tray charge'), $this->money($tray, $currency));
        }
        if (($order->service_charge_amount ?? 0) > 0) {
            $out .= $this->cols(__('Service'), $this->money((float) $order->service_charge_amount, $currency));
        }
        if (($order->tip_amount ?? 0) > 0) {
            $out .= $this->cols(__('Tip'), $this->money((float) $order->tip_amount, $currency));
        }
        if (($order->donation_amount ?? 0) > 0) {
            $out .= $this->cols(__('Donation'), $this->money((float) $order->donation_amount, $currency));
        }
        if (($order->discount_amount ?? 0) > 0) {
            $out .= $this->cols(__('Discount'), '-'.$this->money((float) $order->discount_amount, $currency));
        }
        $total = $order->total ?? $subtotal;
        $out .= self::BOLD_ON.$this->cols(__('Total'), $this->money((float) $total, $currency)).self::BOLD_OFF;

        // Tenders reduce what is DUE, not the order value (Order::getAmountDue).
        foreach ([
            'wallet_applied' => __('Wallet'),
            'gift_card_applied' => __('Gift card'),
        ] as $col => $label) {
            if ((float) ($order->{$col} ?? 0) > 0) {
                $out .= $this->cols($label, '-'.$this->money((float) $order->{$col}, $currency));
            }
        }

        $out .= self::LF.self::ALIGN_CENTER.$this->line((string) (store_options((int) $order->store_id, 'receipt_footer') ?: __('Thank you!')));
        $out .= self::LF.self::LF;

        if ($openDrawer) {
            $out .= self::ESC.'p0'."\x19\xFA"; // cash-drawer kick pulse
        }
        $out .= self::CUT;

        return $out;
    }

    /** Alias with an intention-revealing name; identical bytes to render(). */
    public function renderReceipt(Order $order, bool $openDrawer = false, bool $reprint = false): string
    {
        return $this->render($order, $openDrawer, $reprint);
    }

    /**
     * A kitchen ticket (KOT). No prices — a cook does not need them — and only
     * the lines routed to the given stations, which is what makes "a different
     * ticket for the kitchen and the bar" possible from one order.
     *
     * @param  array<int,int>  $stationIds  empty = every line on one ticket
     * @param  array<int,int>|null  $onlyItemIds  null = every line
     */
    public function renderKot(Order $order, array $stationIds = [], ?string $stationLabel = null, bool $reprint = false, ?array $onlyItemIds = null, bool $addOn = false): string
    {
        $items = $this->itemsForStations($order, $stationIds, $onlyItemIds);

        $out = self::INIT;
        $out .= self::ALIGN_CENTER.self::BOLD_ON;
        $out .= $this->line($stationLabel ? $stationLabel : __('Kitchen'));
        if ($reprint) {
            $out .= $this->line('*** '.__('REPRINT').' ***');
        }
        if ($addOn) {
            $out .= $this->line('*** '.__('ADD-ON').' ***');
        }
        $out .= self::BOLD_OFF;
        $out .= self::ALIGN_LEFT;
        $out .= $this->line(__('Order').' #'.$order->id.' · '.order_type_label((string) $order->type));
        if ($tableLabel = $this->tableLabel($order)) {
            $out .= $this->line($tableLabel);
        }
        $out .= $this->line((string) optional($order->created_at)->format('Y-m-d H:i'));
        $out .= $this->sep();

        if ($items->isEmpty()) {
            $out .= $this->line('—');
        }

        foreach ($items as $it) {
            // T6: the kitchen needs the burger, not "Combo #2" — displayName()
            // names the bundle and the option loop below prints its components,
            // because they are snapshotted as choice groups.
            $name = (string) ($it->displayName() ?: '-');
            $out .= self::BOLD_ON.$this->line($it->quantity.' x '.$name).self::BOLD_OFF;
            foreach ($it->itemExtras as $ex) {
                if ($ex->extra) {
                    $out .= $this->line('   + '.$ex->extra->getRawOriginal('title'));
                }
            }
            foreach ((array) $it->options as $grp) {
                // T6: the bundle group repeats the combo's own name (it carries the
                // bundle PRICE, which a cook does not need) — the components below
                // it are the useful half.
                if (! empty($grp['combo'])) {
                    continue;
                }
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $out .= $this->line('   • '.(string) ($opt['option'] ?? ''));
                }
            }
            if ($it->order_note) {
                // a note is guest text: no control byte of it may reach the printer as a command
                foreach (array_filter(array_map('trim', preg_split('/\R/u', (string) $it->order_note) ?: [])) as $noteLine) {
                    $out .= $this->line('   ! '.(string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $noteLine));
                }
            }
        }

        if ($order->message) {
            $out .= $this->sep();
            $out .= $this->line('! '.(string) $order->message);
        }

        $out .= self::LF.self::LF.self::CUT;

        return $out;
    }

    /**
     * Shift-close (Z) summary. Takes the report array PosService::zReport()
     * already produces, so the printed numbers are literally the on-screen ones.
     *
     * @param  array<string,mixed>  $z
     */
    public function renderShiftSummary(PosShift $shift, array $z): string
    {
        $currency = (string) (store_options((int) $shift->store_id, 'currency_code') ?: 'SAR');
        $store = Store::find($shift->store_id);

        $out = self::INIT;
        $out .= self::ALIGN_CENTER.self::BOLD_ON;
        $out .= $this->line((string) ($store->title ?? ''));
        $out .= $this->line(__('Shift report'));
        $out .= self::BOLD_OFF.self::ALIGN_LEFT;
        $out .= $this->line(__('Shift').' #'.$shift->id);
        $out .= $this->line(__('Opened').': '.(string) optional($shift->opened_at)->format('Y-m-d H:i'));
        $out .= $this->line(__('Closed').': '.(string) optional($shift->closed_at)->format('Y-m-d H:i'));
        $out .= $this->sep();

        $out .= $this->cols(__('Orders'), (string) (int) ($z['orders'] ?? 0));
        foreach ((array) ($z['sales_by_method'] ?? []) as $method => $amount) {
            $out .= $this->cols('  '.payment_method_label((string) $method), $this->money((float) $amount, $currency));
        }
        $netSales = (float) ($z['net_sales'] ?? 0);
        if ($netSales > 0) {
            $out .= $this->cols(__('Net sales'), $this->money($netSales, $currency));
        }
        foreach ((array) ($z['tax_lines'] ?? []) as $tl) {
            $vatAmt = (float) ($tl['vat'] ?? 0);
            if ($vatAmt > 0) {
                $rate = $tl['rate'] ?? null;
                $rateStr = $rate === null ? '-' : rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.').'%';
                $out .= $this->cols(__('VAT').' '.$rateStr, $this->money($vatAmt, $currency));
            }
        }
        $taxTotal = (float) ($z['tax_total'] ?? 0);
        if ($taxTotal > 0) {
            $out .= $this->cols(__('Tax total'), $this->money($taxTotal, $currency));
        }
        $discountTotal = (float) ($z['discount_total'] ?? 0);
        if ($discountTotal > 0) {
            $out .= $this->cols(__('Discounts'), $this->money($discountTotal, $currency));
        }
        $serviceTotal = (float) ($z['service_total'] ?? 0);
        if ($serviceTotal > 0) {
            $out .= $this->cols(__('Service charge'), $this->money($serviceTotal, $currency));
        }
        foreach ((array) ($z['refunds_by_method'] ?? []) as $method => $amount) {
            $amt = (float) $amount;
            if ($amt > 0) {
                $out .= $this->cols('  '.str_replace(':method', (string) $method, __('Refunds (:method)')), $this->money($amt, $currency));
            }
        }
        $out .= $this->cols(__('Tips'), $this->money((float) ($z['tips'] ?? 0), $currency));
        $out .= $this->cols(__('Refunds'), $this->money((float) ($z['refunds_total'] ?? 0), $currency));
        $cashRecon = (float) ($z['cash_reconciliation'] ?? 0);
        if ($cashRecon > 0) {
            $out .= $this->cols(__('Cash reconciliation'), $this->money($cashRecon, $currency));
        }
        $out .= $this->cols(__('Voids'), (string) (int) ($z['voids'] ?? 0));
        if ((float) ($z['voids_total'] ?? 0) > 0) {
            $out .= $this->cols('  '.__('Void total'), $this->money((float) $z['voids_total'], $currency));
        }
        $out .= $this->sep();
        $out .= $this->cols(__('Opening float'), $this->money((float) ($z['opening_float'] ?? 0), $currency));
        $out .= $this->cols(__('Expected cash'), $this->money((float) ($z['expected_cash'] ?? 0), $currency));
        $out .= $this->cols(__('Counted cash'), $this->money((float) ($z['counted_cash'] ?? 0), $currency));
        $out .= self::BOLD_ON.$this->cols(__('Over / short'), $this->money((float) ($z['over_short'] ?? 0), $currency)).self::BOLD_OFF;

        $out .= self::LF.self::LF.self::CUT;

        return $out;
    }

    /** A short self-test page, so an owner can prove a printer works before service. */
    public function renderTest(Store $store, string $profileName): string
    {
        $out = self::INIT;
        $out .= $this->logoBlock();
        $out .= self::ALIGN_CENTER.self::BOLD_ON.$this->line(__('Test print')).self::BOLD_OFF;
        $out .= $this->line((string) $store->title);
        $out .= self::ALIGN_LEFT.$this->sep();
        $out .= $this->cols(__('Printer'), $profileName);
        $out .= $this->cols(__('Width'), $this->width.' '.__('columns'));
        $out .= $this->cols(__('Time'), now()->format('Y-m-d H:i:s'));
        $out .= $this->sep();
        // A ruler proves the column count matches the paper the owner loaded.
        $out .= $this->line(substr(str_repeat('1234567890', 6), 0, $this->width));
        $out .= self::LF.self::LF.self::CUT;

        return $out;
    }

    // ── item routing ─────────────────────────────────────────────────────────

    /**
     * The order's items that belong to the given stations. Resolution mirrors
     * KdsService::resolveStationForOrderItem (per-branch pivot → menu default),
     * falling back to the station already stamped on the line by the KDS.
     *
     * @param  array<int,int>  $stationIds
     * @param  array<int,int>|null  $onlyItemIds
     */
    public function itemsForStations(Order $order, array $stationIds = [], ?array $onlyItemIds = null)
    {
        $items = $order->items;
        $stationIds = array_values(array_filter(array_map('intval', $stationIds)));

        if ($onlyItemIds !== null) {
            $only = array_values(array_filter(array_map('intval', $onlyItemIds)));
            $items = $items->filter(fn ($item) => in_array((int) $item->id, $only, true))->values();
        }

        if (! $stationIds) {
            return $items;
        }

        return $items->filter(function ($item) use ($order, $stationIds) {
            $station = $item->station_id ? (int) $item->station_id : null;
            if ($station === null && $item->menu) {
                $station = KdsService::resolveStationForOrderItem(
                    $order->branch_id ? (int) $order->branch_id : null,
                    $item->menu
                );
            }

            return $station !== null && in_array((int) $station, $stationIds, true);
        })->values();
    }

    // ── layout primitives ────────────────────────────────────────────────────

    /** Printable dot width of the head for this column count. */
    private function dots(): int
    {
        return self::DOTS[$this->width] ?? 576;
    }

    /** Logo raster block (centred), or '' when disabled/unavailable. */
    private function logoBlock(): string
    {
        if (! $this->logoPath) {
            return '';
        }

        $bytes = EscPosRaster::logo($this->logoPath, $this->dots());

        return $bytes ? (self::ALIGN_CENTER.$bytes.self::LF) : '';
    }

    /**
     * One line of text. RTL text is shaped and visually reordered; when a TTF is
     * configured it is drawn as a raster strip instead of characters, because no
     * ESC/POS character generator does Arabic shaping.
     */
    private function line(string $text): string
    {
        $rtl = $this->rtl || ArabicShaper::isRtl($text);

        if (! $rtl) {
            // Free-text header lines (store name, address, phone) are not
            // column-fitted the way cols() fits the price rows, so a long store
            // name overflowed the head on 57mm paper — invisible to a content
            // assertion, caught by decoding the byte stream. Wrap at the column
            // width so it flows onto a second line instead of clipping.
            if (mb_strlen($text) <= $this->width) {
                return $text.self::LF;
            }

            return implode('', array_map(fn ($row) => $row.self::LF, $this->wrap($text)));
        }

        $shaped = ArabicShaper::shape($text);

        if ($this->fontPath && EscPosRaster::available()) {
            $bytes = EscPosRaster::textLine($shaped, $this->dots(), $this->fontPath, 22, true);
            if ($bytes) {
                return $bytes.self::LF;
            }
        }

        // Text lane: right-align by padding, so the line still reads correctly on
        // a printer whose code page can map the presentation forms.
        return self::ALIGN_RIGHT.$shaped.self::LF.self::ALIGN_LEFT;
    }

    private function sep(): string
    {
        return str_repeat('-', $this->width).self::LF;
    }

    /**
     * Wrap a long line to the column width, mb-safe, breaking on spaces where it
     * can and hard-cutting a token longer than the width. Returns ≥ 1 rows, each
     * ≤ width.
     *
     * @return list<string>
     */
    private function wrap(string $text): array
    {
        $rows = [];
        foreach (explode(' ', $text) as $word) {
            if ($rows && mb_strlen(end($rows)) + 1 + mb_strlen($word) <= $this->width) {
                $rows[count($rows) - 1] .= ' '.$word;

                continue;
            }
            // A single word longer than the width is hard-cut into width chunks.
            while (mb_strlen($word) > $this->width) {
                $rows[] = mb_substr($word, 0, $this->width);
                $word = mb_substr($word, $this->width);
            }
            $rows[] = $word;
        }

        return $rows ?: [''];
    }

    /**
     * Left/right justified within the line width. In RTL the label and the
     * amount swap margins — an Arabic receipt reads label-right, number-left.
     */
    private function cols(string $left, string $right): string
    {
        if ($this->rtl || ArabicShaper::isRtl($left)) {
            [$left, $right] = [$right, ArabicShaper::shape($left)];
        }

        $space = $this->width - mb_strlen($left) - mb_strlen($right);
        if ($space < 1) {
            $left = mb_substr($left, 0, max(0, $this->width - mb_strlen($right) - 1));
            $space = max(1, $this->width - mb_strlen($left) - mb_strlen($right));
        }

        return $left.str_repeat(' ', $space).$right.self::LF;
    }

    private function money(float $amount, string $currency): string
    {
        // MC-21: honor the currency's own decimals so 3-decimal currencies
        // (OMR/KWD/…) keep their fils on the printed receipt instead of 2dp.
        return number_format($amount, currency_decimals($currency)).' '.$currency;
    }

    /** Absolute path to the store's logo image, when one exists on disk. */
    private static function storeLogoPath(int $storeId): ?string
    {
        $store = Store::find($storeId);
        $file = (string) ($store->main_image ?? '');
        if ($file === '' || $file === 'default.png') {
            return null;
        }

        foreach ([public_path('assets/img/uploads/'.$file), public_path($file), storage_path('app/public/'.$file)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
