<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * P4-21: an in-memory Odoo behind Http::fake — the JSON-RPC calls the connector makes (common.login,
 * object.execute_kw: search_read / create / unlink / action_post / action_confirm / button_validate /
 * action_create_payments), with Odoo's invoice arithmetic: line base = quantity × price_unit, percentage taxes
 * rounded globally per tax over their base, fixed taxes = amount × quantity, all to 2 decimals.
 */
final class FakeOdoo
{
    public const URL = 'https://odoo.fake.test';

    public const DB = 'shop';

    public const USER = 'owner@shop.test';

    public const KEY = 'odoo-api-key-secret-123';

    /** @var array<string, array<int, array<string, mixed>>> model => id => row */
    public array $rows = [];

    /** @var list<array{model: string, method: string}> */
    public array $calls = [];

    /** Answer HTTP 500 to the next N object calls. */
    public int $failNext = 0;

    private int $nextId = 100;

    public function __construct()
    {
        $this->rows = [
            'res.currency' => [1 => ['id' => 1, 'name' => 'SAR', 'active' => true], 2 => ['id' => 2, 'name' => 'USD', 'active' => true]],
            'account.tax' => [
                10 => ['id' => 10, 'name' => 'VAT 15%', 'amount' => 15.0, 'amount_type' => 'percent', 'price_include' => false, 'type_tax_use' => 'sale'],
                11 => ['id' => 11, 'name' => 'VAT 10%', 'amount' => 10.0, 'amount_type' => 'percent', 'price_include' => false, 'type_tax_use' => 'sale'],
            ],
            'account.journal' => [20 => ['id' => 20, 'name' => 'Cash', 'type' => 'cash'], 21 => ['id' => 21, 'name' => 'Bank', 'type' => 'bank']],
            'stock.picking.type' => [30 => ['id' => 30, 'name' => 'Delivery Orders', 'code' => 'outgoing', 'default_location_src_id' => [8, 'WH/Stock'], 'default_location_dest_id' => [5, 'Customers']]],
            'product.product' => [40 => ['id' => 40, 'default_code' => 'TEA-1', 'name' => 'Tea']],
            'res.partner' => [], 'account.move' => [], 'account.payment' => [], 'account.payment.register' => [], 'stock.picking' => [],
        ];
    }

    public static function install(): self
    {
        $fake = new self;
        Http::fake([self::URL.'/jsonrpc' => fn (Request $r) => $fake->handle($r)]);

        return $fake;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $model): array
    {
        return array_values($this->rows[$model] ?? []);
    }

    public function created(string $model): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['model'] === $model && $c['method'] === 'create'));
    }

    private function handle(Request $r): mixed
    {
        $p = $r->data()['params'] ?? [];
        if (($p['service'] ?? '') === 'common') {
            [$db, $user, $key] = $p['args'];

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ($db === self::DB && $user === self::USER && $key === self::KEY) ? 7 : false]);
        }
        if ($this->failNext > 0) {
            $this->failNext--;

            return Http::response('Service Unavailable', 503);
        }
        [, , $key, $model, $method, $args, $kwargs] = $p['args'] + [6 => []];
        if ($key !== self::KEY) {
            return $this->error('odoo.exceptions.AccessDenied', 'Access Denied');
        }
        $this->calls[] = ['model' => $model, 'method' => $method];
        $kwargs = (array) $kwargs;

        try {
            $result = match ($method) {
                'search_read' => $this->searchRead($model, $args[0] ?? [], $kwargs['fields'] ?? [], (int) ($kwargs['limit'] ?? 0)),
                'create' => $this->create($model, $args[0], (array) ($kwargs['context'] ?? [])),
                'unlink' => $this->unlink($model, $args[0]),
                'action_post' => $this->set($model, $args[0], ['state' => 'posted']),
                'action_confirm' => $this->set($model, $args[0], ['state' => 'assigned']),
                'button_validate' => $this->set($model, $args[0], ['state' => 'done']),
                'action_create_payments' => $this->pay($args[0][0], (array) ($kwargs['context'] ?? [])),
                default => throw new \RuntimeException("fake odoo: {$model}.{$method} not implemented"),
            };
        } catch (\DomainException $e) {
            return $this->error('odoo.exceptions.UserError', $e->getMessage());
        }

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    private function error(string $name, string $message): mixed
    {
        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => 200, 'message' => 'Odoo Server Error', 'data' => ['name' => $name, 'message' => $message]]]);
    }

    /** @param list<array{0: string, 1: string, 2: mixed}> $domain */
    private function searchRead(string $model, array $domain, array $fields, int $limit): array
    {
        $out = [];
        foreach ($this->rows[$model] ?? [] as $row) {
            foreach ($domain as [$f, $op, $v]) {
                $have = $row[$f] ?? null;
                $ok = match ($op) {
                    '=' => $have == $v, '!=' => $have != $v, '>' => $have > $v, 'in' => in_array($have, (array) $v, false),
                    default => throw new \RuntimeException("fake odoo: operator {$op}"),
                };
                if (! $ok) {
                    continue 2;
                }
            }
            $out[] = $fields === [] ? $row : array_intersect_key($row, array_flip(array_merge(['id'], $fields)));
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function create(string $model, array $values, array $context): int
    {
        $id = $this->nextId++;
        $values['id'] = $id;
        if ($model === 'account.move') {
            $values += ['state' => 'draft', 'payment_state' => 'not_paid'];
            $this->totals($values);
        }
        if ($model === 'account.payment.register') {
            $values['context'] = $context;
        }
        if ($model === 'stock.picking') {
            $values['state'] = 'draft';
        }
        $this->rows[$model][$id] = $values;

        return $id;
    }

    /** Odoo's invoice arithmetic (2 decimals; percentage taxes rounded globally per tax). */
    private function totals(array &$move): void
    {
        $untaxed = 0.0;
        $bases = [];
        $fixed = 0.0;
        foreach ($move['invoice_line_ids'] ?? [] as $cmd) {
            $line = $cmd[2];
            $base = round($line['quantity'] * $line['price_unit'], 2);
            $untaxed += $base;
            foreach ($line['tax_ids'][0][2] ?? [] as $taxId) {
                $tax = $this->rows['account.tax'][$taxId];
                if ($tax['amount_type'] === 'fixed') {
                    $fixed += $tax['amount'] * $line['quantity'];
                } else {
                    $bases[$taxId] = ($bases[$taxId] ?? 0) + $base;
                }
            }
        }
        $tax = $fixed;
        foreach ($bases as $taxId => $base) {
            $tax += round(round($base, 2) * $this->rows['account.tax'][$taxId]['amount'] / 100 + 1e-9, 2);
        }
        $move['amount_untaxed'] = round($untaxed, 2);
        $move['amount_tax'] = round($tax, 2);
        $move['amount_total'] = round($untaxed + $tax, 2);
        $move['amount_residual'] = $move['amount_total'];
    }

    private function unlink(string $model, array $ids): bool
    {
        foreach ($ids as $id) {
            if (($this->rows[$model][$id]['state'] ?? 'draft') !== 'draft') {
                throw new \DomainException('You cannot delete a posted entry.');
            }
            unset($this->rows[$model][$id]);
        }

        return true;
    }

    private function set(string $model, array $ids, array $values): bool
    {
        foreach ($ids as $id) {
            $this->rows[$model][$id] = array_merge($this->rows[$model][$id], $values);
        }

        return true;
    }

    private function pay(int $wizardId, array $context): bool
    {
        $wizard = $this->rows['account.payment.register'][$wizardId];
        foreach ((array) ($context['active_ids'] ?? []) as $moveId) {
            $move = $this->rows['account.move'][$moveId];
            if ($move['state'] !== 'posted') {
                throw new \DomainException('Only posted entries can be paid.');
            }
            $this->create('account.payment', ['move_id' => $moveId, 'journal_id' => $wizard['journal_id'], 'amount' => $move['amount_residual'],
                'payment_type' => $move['move_type'] === 'out_refund' ? 'outbound' : 'inbound'], []);
            $this->rows['account.move'][$moveId]['amount_residual'] = 0.0;
            $this->rows['account.move'][$moveId]['payment_state'] = 'paid';
        }

        return true;
    }
}
