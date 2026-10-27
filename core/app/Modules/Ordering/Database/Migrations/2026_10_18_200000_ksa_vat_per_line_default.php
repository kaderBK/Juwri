<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-186 (DECISIONS J44): "VAT per line" ON for every existing KSA store (`fiscal.country` SA, percentage tax): an
 * empty `tax.engine` becomes 'vat'; an owner's explicit 'vat'/'order' is kept. New orders only: every order of those
 * stores without a VAT snapshot (`orders.tax_lines` NULL = placed on the legacy tax) gets the legacy marker, so no
 * placed order or issued invoice is recalculated. Settings JSON + a marker only — no schema change.
 */
return new class extends Migration
{
    private const LEGACY_MARKER = '{"mode":"legacy"}';

    public function up(): void
    {
        DB::table('stores')->whereNotNull('settings')->select(['id', 'settings'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $s = is_string($row->settings) ? json_decode($row->settings, true) : null;
                    if (! is_array($s) || strtoupper(trim((string) ($s['fiscal']['country'] ?? ''))) !== 'SA') {
                        continue;
                    }
                    $tax = is_array($s['tax'] ?? null) ? $s['tax'] : [];
                    if ((string) ($tax['tax_type'] ?? 'percentage') === 'fixed') {
                        continue;
                    }
                    DB::table('orders')->where('store_id', $row->id)->whereNull('tax_lines')->update(['tax_lines' => self::LEGACY_MARKER]);
                    if ((string) ($tax['engine'] ?? '') !== '') {
                        continue;
                    }
                    $tax['engine'] = 'vat';
                    $s['tax'] = $tax;
                    DB::table('stores')->where('id', $row->id)->update([
                        'settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // one-way: which stores had an empty setting is not recorded, and placed orders keep their tax
    }
};
