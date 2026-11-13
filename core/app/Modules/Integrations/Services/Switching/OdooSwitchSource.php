<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

use Juwri\Modules\Integrations\Services\Odoo\OdooClient;
use Juwri\Modules\Integrations\Services\Odoo\OdooConnection;
use Juwri\Modules\Integrations\Services\Odoo\OdooError;

/**
 * P4-22: read a dataset from the store's previous Odoo (JSON-RPC, the connector's client and address rules: public https
 * only, private hosts by exact allowlist, pinned). The credentials are used for this one read and never stored.
 * Rows come out in the same shape as a file's, so the same planner previews and imports them.
 *
 *  menu       product.template (sale_ok, active or not): name, list_price, default_code, categ_id, description_sale
 *  customers  res.partner with customer_rank > 0: name, phone (else mobile), email
 *  loyalty    loyalty.card (Odoo 16+) with a partner: the partner's phone / e-mail and the card's points
 */
final class OdooSwitchSource
{
    /** @return list<array<string, string>> */
    public function rows(string $dataset, string $url, string $database, string $username, string $apiKey): array
    {
        $odoo = new OdooClient(new OdooConnection(0, $url, $database, $username, $apiKey, false, null, null, null, null));
        try {
            $rows = match (SwitchColumns::dataset($dataset)) {
                'menu' => $this->menu($odoo),
                'customers' => $this->customers($odoo),
                'loyalty' => $this->loyalty($odoo),
            };
        } catch (OdooError $e) {
            throw new SwitchError($e->getMessage());
        }
        if ($rows === []) {
            throw new SwitchError(___('Odoo returned nothing to import.'));
        }
        if (count($rows) > SwitchFile::MAX_ROWS) {
            throw new SwitchError(___('The file has more than :n rows; split it into smaller files.', ['n' => SwitchFile::MAX_ROWS]));
        }

        return array_map(fn (array $r, int $i) => ['_row' => (string) ($i + 1)] + $r, $rows, array_keys($rows));
    }

    /** @return list<array<string, string>> */
    private function menu(OdooClient $odoo): array
    {
        $out = [];
        foreach ($odoo->searchRead('product.template', [['sale_ok', '=', true]], ['name', 'list_price', 'default_code', 'categ_id', 'description_sale', 'active'], SwitchFile::MAX_ROWS + 1) as $p) {
            $categ = is_array($p['categ_id'] ?? null) ? (string) ($p['categ_id'][1] ?? '') : '';
            $out[] = [
                'name' => (string) ($p['name'] ?? ''),
                'price' => self::decimal($p['list_price'] ?? 0),
                'sku' => is_string($p['default_code'] ?? null) ? $p['default_code'] : '',
                'category' => trim((string) last(explode(' / ', $categ))),
                'description' => is_string($p['description_sale'] ?? null) ? $p['description_sale'] : '',
                'active' => ! empty($p['active']) ? '1' : '0',
            ];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function customers(OdooClient $odoo): array
    {
        $out = [];
        foreach ($odoo->searchRead('res.partner', [['customer_rank', '>', 0]], ['name', 'phone', 'mobile', 'email'], SwitchFile::MAX_ROWS + 1) as $p) {
            $phone = is_string($p['phone'] ?? null) && $p['phone'] !== '' ? $p['phone'] : (is_string($p['mobile'] ?? null) ? $p['mobile'] : '');
            $out[] = ['name' => (string) ($p['name'] ?? ''), 'phone' => $phone, 'email' => is_string($p['email'] ?? null) ? $p['email'] : ''];
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private function loyalty(OdooClient $odoo): array
    {
        $cards = $odoo->searchRead('loyalty.card', [['partner_id', '!=', false]], ['partner_id', 'points'], SwitchFile::MAX_ROWS + 1);
        $ids = array_values(array_unique(array_map(fn ($c) => (int) ($c['partner_id'][0] ?? 0), $cards)));
        $partners = [];
        foreach ($ids === [] ? [] : $odoo->searchRead('res.partner', [['id', 'in', $ids]], ['phone', 'mobile', 'email']) as $p) {
            $partners[(int) $p['id']] = $p;
        }
        $points = [];
        foreach ($cards as $c) {
            $pid = (int) ($c['partner_id'][0] ?? 0);
            $points[$pid] = ($points[$pid] ?? 0) + (float) ($c['points'] ?? 0); // one balance per customer (all their cards)
        }
        $out = [];
        foreach ($points as $pid => $sum) {
            $p = $partners[$pid] ?? [];
            $phone = is_string($p['phone'] ?? null) && $p['phone'] !== '' ? $p['phone'] : (is_string($p['mobile'] ?? null) ? $p['mobile'] : '');
            $out[] = ['phone' => $phone, 'email' => is_string($p['email'] ?? null) ? $p['email'] : '', 'points' => self::decimal($sum)];
        }

        return $out;
    }

    /** A JSON number as the shortest exact decimal string (14.5 → "14.5", 120.0 → "120"). */
    private static function decimal(mixed $v): string
    {
        return rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.');
    }
}
