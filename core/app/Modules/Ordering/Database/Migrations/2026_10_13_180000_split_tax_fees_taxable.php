<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P6-116 (DECISIONS J32): the single "VAT on delivery and service fees" setting (`tax.fees_taxable`: '' = country
 * rule, '1', '0') becomes one switch per fee — `tax.delivery_taxable`, `tax.tray_taxable`, `tax.service_taxable`,
 * OFF by default. Every store keeps what it charged: an explicit '1'/'0' is copied to the three switches; a store on
 * the VAT engine that followed the country rule gets that rule's answer written out (as of P6-99b: SA, AE, OM, BH, JO,
 * EG, TN taxed fees). A switch the store already has is left alone. Settings JSON only — no schema change.
 */
return new class extends Migration
{
    /** Countries whose rule taxed fees when this migration was written (frozen copy of TaxCountryRules, P6-99b). */
    private const FEES_TAXED = ['SA', 'AE', 'OM', 'BH', 'JO', 'EG', 'TN'];

    private const SWITCHES = ['delivery_taxable', 'tray_taxable', 'service_taxable'];

    public function up(): void
    {
        DB::table('stores')->whereNotNull('settings')->select(['id', 'settings'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $s = is_string($row->settings) ? json_decode($row->settings, true) : null;
                    if (! is_array($s) || ! is_array($s['tax'] ?? null)) {
                        continue;
                    }
                    $tax = $s['tax'];
                    if (! array_key_exists('fees_taxable', $tax) && ($tax['engine'] ?? '') !== 'vat') {
                        continue;
                    }
                    $v = (string) ($tax['fees_taxable'] ?? '');
                    $taxed = match ($v) {
                        '1' => true,
                        '0' => false,
                        default => ($tax['engine'] ?? '') === 'vat'
                            && in_array(strtoupper((string) ($s['fiscal']['country'] ?? '')), self::FEES_TAXED, true),
                    };
                    foreach (self::SWITCHES as $key) {
                        $tax[$key] ??= $taxed;
                    }
                    unset($tax['fees_taxable']);
                    $s['tax'] = $tax;
                    DB::table('stores')->where('id', $row->id)->update([
                        'settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('stores')->whereNotNull('settings')->select(['id', 'settings'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $s = is_string($row->settings) ? json_decode($row->settings, true) : null;
                    if (! is_array($s) || ! is_array($s['tax'] ?? null) || ! array_key_exists('delivery_taxable', $s['tax'])) {
                        continue;
                    }
                    $s['tax']['fees_taxable'] = $s['tax']['delivery_taxable'] ? '1' : '0';
                    foreach (self::SWITCHES as $key) {
                        unset($s['tax'][$key]);
                    }
                    DB::table('stores')->where('id', $row->id)->update([
                        'settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    ]);
                }
            });
    }
};
