<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Ordering\Models\OrderAdjustment;

/**
 * P4-21: the Odoo side of one queued event. Every step first looks for what an earlier attempt made — the ids kept
 * in the record's result, then Odoo itself by the reference (the order's UUIDv7) — so a retry, a replay or a second
 * worker never makes a second customer, invoice, payment, picking or credit note.
 *
 *  order:  customer (res.partner, ref juwri:<store>:c:<customer> or the store's walk-in partner) → customer invoice
 *          (account.move out_invoice, ref = UUIDv7) as a draft → Odoo's total and tax compared with Juwri's to the
 *          minor unit (a mismatch deletes the draft and stops: fix the tax mapping) → posted → paid in the mapped
 *          cash / bank journal when the order is paid → products with an SKU delivered on an outgoing picking.
 *  refund: credit note (out_refund, reversed_entry_id = the sale's invoice) for the refunded amount, compared the
 *          same way, posted; a refund (money returned) is paid out in the mapped journal.
 *
 * Targets Odoo 17+ (stock moves carry `quantity`).
 */
final class OdooSync
{
    public function __construct(private readonly OdooOrderMapper $mapper) {}

    /**
     * @param  array<string, mixed>  $result  ids kept so far (updated in place, also when a step fails)
     */
    public function order(OdooClient $odoo, OdooConnection $c, Order $order, string $reference, array &$result): void
    {
        $draft = $this->mapper->invoice($order);
        $currencyId = $this->currencyId($odoo, $draft['currency']);
        $result['partner_id'] ??= $this->partner($odoo, $order);
        $taxId = $c->taxFor((string) $order->type);
        $taxRate = $this->taxRate($odoo, $taxId, $draft);
        $result['tax_rate'] = $taxRate;

        $result['invoice_id'] ??= $this->findMove($odoo, $reference, 'out_invoice');
        if (empty($result['invoice_id'])) {
            $result['invoice_id'] = $this->createMove($odoo, $c, $taxId, 'out_invoice', $reference, (int) $result['partner_id'], $currencyId,
                $draft['lines'], $draft['total_minor'], $draft['tax_minor'], $draft['currency'], ['invoice_origin' => 'Juwri order #'.$order->id, 'invoice_date' => ($order->created_at ?? now())->toDateString()]);
        }

        if ($order->is_paid) {
            $result['payment'] = $this->pay($odoo, (int) $result['invoice_id'], $c->journalFor((string) ($order->payment_method ?: $order->payment_gateway)));
        }

        if (($pickingType = $c->pickingFor((string) $order->type)) !== null) {
            $result['picking_id'] ??= $this->picking($odoo, $pickingType, $reference, (int) $result['partner_id'], $draft['lines'], $result);
        }
    }

    /**
     * @param  array<string, mixed>  $saleResult  the synced order record's result (invoice id, tax rate)
     * @param  array<string, mixed>  $result
     */
    public function refund(OdooClient $odoo, OdooConnection $c, Order $order, OrderAdjustment $adjustment, string $reference, array $saleResult, array &$result): void
    {
        $invoiceId = (int) ($saleResult['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new OdooError('Waiting for the sale\'s invoice to reach Odoo first.');
        }
        $sale = $this->mapper->invoice($order);
        $credit = $this->mapper->refund($adjustment, $sale, isset($saleResult['tax_rate']) ? (float) $saleResult['tax_rate'] : null);
        $currencyId = $this->currencyId($odoo, $credit['currency']);
        $partnerId = (int) ($saleResult['partner_id'] ?? 0) ?: $this->partner($odoo, $order);

        $result['credit_note_id'] ??= $this->findMove($odoo, $reference, 'out_refund');
        if (empty($result['credit_note_id'])) {
            $result['credit_note_id'] = $this->createMove($odoo, $c, $c->taxFor((string) $order->type), 'out_refund', $reference, $partnerId, $currencyId,
                $credit['lines'], $credit['total_minor'], $credit['tax_minor'], $credit['currency'],
                ['reversed_entry_id' => $invoiceId, 'invoice_origin' => 'Juwri order #'.$order->id, 'invoice_date' => ($adjustment->created_at ?? now())->toDateString()]);
        }
        if ((string) $adjustment->type === 'refund') {
            $result['payment'] = $this->pay($odoo, (int) $result['credit_note_id'], $c->journalFor((string) $adjustment->method));
        }
    }

    private function currencyId(OdooClient $odoo, string $code): int
    {
        $rows = $odoo->searchRead('res.currency', [['name', '=', strtoupper($code)]], ['id', 'active'], 1);
        if ($rows === []) {
            throw new OdooError("The currency {$code} does not exist in Odoo.", permanent: true);
        }

        return (int) $rows[0]['id'];
    }

    private function partner(OdooClient $odoo, Order $order): int
    {
        $customer = $order->customer_id ? Customer::query()->find($order->customer_id) : null;
        $ref = $customer ? 'juwri:'.$order->store_id.':c:'.$customer->id : 'juwri:'.$order->store_id.':walkin';
        $found = $odoo->searchRead('res.partner', [['ref', '=', $ref]], ['id'], 1);
        if ($found !== []) {
            return (int) $found[0]['id'];
        }

        return $odoo->create('res.partner', $customer
            ? array_filter(['name' => (string) ($customer->name ?: 'Customer '.$customer->id), 'phone' => $customer->phone, 'email' => $customer->email, 'ref' => $ref, 'customer_rank' => 1], fn ($v) => $v !== null && $v !== '')
            : ['name' => 'Walk-in customers', 'ref' => $ref, 'customer_rank' => 1]);
    }

    /**
     * The mapped Odoo tax's rate (percent), checked against how the store taxes; null when the sale has no tax.
     *
     * @param  array{tax_minor: int, fixed_tax: bool}  $draft
     */
    private function taxRate(OdooClient $odoo, ?int $taxId, array $draft): ?float
    {
        if ($draft['tax_minor'] <= 0) {
            return null;
        }
        if ($taxId === null) {
            throw new OdooError('Choose the Odoo tax that matches the store\'s tax in the Odoo settings.', permanent: true);
        }
        $tax = $odoo->searchRead('account.tax', [['id', '=', $taxId]], ['amount', 'amount_type', 'price_include', 'type_tax_use'], 1)[0] ?? null;
        if ($tax === null) {
            throw new OdooError('The mapped Odoo tax no longer exists.', permanent: true);
        }
        $want = $draft['fixed_tax'] ? 'fixed' : 'percent';
        if ((string) $tax['amount_type'] !== $want || ! empty($tax['price_include'])) {
            throw new OdooError("The mapped Odoo tax must be a {$want} tax excluded from the price.", permanent: true);
        }

        return (float) $tax['amount'];
    }

    private function findMove(OdooClient $odoo, string $reference, string $type): ?int
    {
        $rows = $odoo->searchRead('account.move', [['ref', '=', $reference], ['move_type', '=', $type], ['state', '!=', 'cancel']], ['id', 'state'], 1);
        if ($rows === []) {
            return null;
        }
        if ((string) $rows[0]['state'] === 'draft') {
            $odoo->execute('account.move', 'action_post', [[(int) $rows[0]['id']]]);
        }

        return (int) $rows[0]['id'];
    }

    /**
     * @param  list<array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function createMove(OdooClient $odoo, OdooConnection $c, ?int $taxId, string $type, string $reference, int $partnerId, int $currencyId,
        array $lines, int $totalMinor, int $taxMinor, string $currency, array $extra): int
    {
        $products = $this->products($odoo, $lines);
        $untaxed = $this->untaxedTax($odoo, $c);
        $commands = [];
        foreach ($lines as $line) {
            $commands[] = [0, 0, array_filter([
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'price_unit' => OdooOrderMapper::major($line['price_minor'], $currency),
                'tax_ids' => [[6, 0, $line['taxed'] ? ($taxId !== null ? [$taxId] : []) : ($untaxed !== null ? [$untaxed] : [])]],
                'product_id' => $line['sku'] !== null ? ($products[$line['sku']] ?? null) : null,
            ], fn ($v) => $v !== null)];
        }
        $id = $odoo->create('account.move', array_merge([
            'move_type' => $type, 'partner_id' => $partnerId, 'currency_id' => $currencyId, 'ref' => $reference,
            'invoice_date' => now()->toDateString(), 'invoice_line_ids' => $commands,
        ], $extra));

        // Exact or nothing: Odoo's own total and tax must equal Juwri's to the minor unit before the move is posted.
        $read = $odoo->searchRead('account.move', [['id', '=', $id]], ['amount_total', 'amount_tax'], 1)[0] ?? [];
        $gotTotal = (int) round(((float) ($read['amount_total'] ?? -1)) * (10 ** Currencies::exponent($currency)));
        $gotTax = (int) round(((float) ($read['amount_tax'] ?? -1)) * (10 ** Currencies::exponent($currency)));
        if ($gotTotal !== $totalMinor || $gotTax !== $taxMinor) {
            $odoo->execute('account.move', 'unlink', [[$id]]);
            throw new OdooError(sprintf('Odoo computed %s (tax %s) but Juwri charged %s (tax %s): check the mapped tax (rate, excluded from the price) and set Odoo\'s tax rounding to "Round globally". Nothing was posted.',
                OdooOrderMapper::major($gotTotal, $currency), OdooOrderMapper::major($gotTax, $currency),
                OdooOrderMapper::major($totalMinor, $currency), OdooOrderMapper::major($taxMinor, $currency)), permanent: true);
        }
        $odoo->execute('account.move', 'action_post', [[$id]]);

        return $id;
    }

    /** Register the payment of what is still open on the move (none when Odoo already shows it paid). */
    private function pay(OdooClient $odoo, int $moveId, ?int $journalId): string
    {
        $move = $odoo->searchRead('account.move', [['id', '=', $moveId]], ['amount_residual', 'payment_state'], 1)[0] ?? [];
        if ((float) ($move['amount_residual'] ?? 0) <= 0 || in_array((string) ($move['payment_state'] ?? ''), ['paid', 'in_payment', 'reversed'], true)) {
            return 'paid';
        }
        if ($journalId === null) {
            throw new OdooError('Choose the Odoo payment journals (cash and bank) in the Odoo settings.', permanent: true);
        }
        $context = ['active_model' => 'account.move', 'active_ids' => [$moveId]];
        $wizard = $odoo->create('account.payment.register', ['journal_id' => $journalId, 'payment_date' => now()->toDateString()], $context);
        $odoo->execute('account.payment.register', 'action_create_payments', [[$wizard]], ['context' => $context]);

        return 'paid';
    }

    /**
     * Products with an SKU (Odoo internal reference) leave stock on one outgoing picking.
     *
     * @param  list<array{name: string, quantity: int, price_minor: int, taxed: bool, sku: ?string}>  $lines
     * @param  array<string, mixed>  $result
     */
    private function picking(OdooClient $odoo, int $pickingTypeId, string $reference, int $partnerId, array $lines, array &$result): ?int
    {
        $found = $odoo->searchRead('stock.picking', [['origin', '=', $reference]], ['id'], 1);
        if ($found !== []) {
            return (int) $found[0]['id'];
        }
        $products = $this->products($odoo, $lines);
        $type = $odoo->searchRead('stock.picking.type', [['id', '=', $pickingTypeId]], ['default_location_src_id', 'default_location_dest_id'], 1)[0] ?? null;
        if ($type === null) {
            throw new OdooError('The mapped Odoo operation type no longer exists.', permanent: true);
        }
        $from = (int) ($type['default_location_src_id'][0] ?? 0);
        $to = (int) ($type['default_location_dest_id'][0] ?? 0);
        $moves = [];
        $unmapped = [];
        foreach ($lines as $line) {
            if ($line['sku'] === null) {
                continue;
            }
            if (! isset($products[$line['sku']])) {
                $unmapped[] = $line['sku'];

                continue;
            }
            $qty = preg_match('/^(\d+) × /u', $line['name'], $m) === 1 ? (int) $m[1] : $line['quantity'];
            $moves[] = [0, 0, ['name' => $line['name'], 'product_id' => $products[$line['sku']], 'product_uom_qty' => $qty, 'quantity' => $qty,
                'location_id' => $from, 'location_dest_id' => $to]];
        }
        $result['unmapped_skus'] = array_values(array_unique($unmapped));
        if ($moves === []) {
            return null;
        }
        $id = $odoo->create('stock.picking', ['picking_type_id' => $pickingTypeId, 'partner_id' => $partnerId, 'origin' => $reference,
            'location_id' => $from, 'location_dest_id' => $to, 'move_ids' => $moves]);
        $odoo->execute('stock.picking', 'action_confirm', [[$id]]);
        $odoo->execute('stock.picking', 'button_validate', [[$id]]);

        return $id;
    }

    /**
     * @param  list<array{sku: ?string}>  $lines
     * @return array<string, int> sku => product.product id
     */
    private function products(OdooClient $odoo, array $lines): array
    {
        $skus = array_values(array_unique(array_filter(array_map(fn ($l) => $l['sku'], $lines))));
        if ($skus === []) {
            return [];
        }
        $map = [];
        foreach ($odoo->searchRead('product.product', [['default_code', 'in', $skus]], ['id', 'default_code']) as $row) {
            $map[(string) $row['default_code']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * The Odoo tax mapped for the untaxed lines (delivery, tip, …) — it must be a 0 % tax, or the invoice would no
     * longer match the order; refused before anything is created.
     */
    private function untaxedTax(OdooClient $odoo, OdooConnection $c): ?int
    {
        $id = $c->untaxedTaxId();
        if ($id === null) {
            return null;
        }
        $tax = $odoo->searchRead('account.tax', [['id', '=', $id]], ['amount', 'amount_type'], 1)[0] ?? null;
        if ($tax === null || (float) $tax['amount'] != 0.0) {
            throw new OdooError('The Odoo tax mapped for untaxed lines must be an existing 0 % tax.', permanent: true);
        }

        return $id;
    }
}
