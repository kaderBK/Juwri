<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Tax;

/**
 * P6-99b: what the VAT engine assumes per fiscal country (TAX-ENGINE.md). Only defaults — an owner's
 * explicit `tax_fees_taxable` setting wins. Items marked OWNER/ACCOUNTANT-CONFIRM in the design note are best
 * knowledge, not advice.
 */
final class TaxCountryRules
{
    /** @var array<string, array{rate: float, fees_taxable: bool}> */
    private const RULES = [
        'SA' => ['rate' => 15.0, 'fees_taxable' => true],  // KSA VAT: delivery and service charges are part of the supply
        'AE' => ['rate' => 5.0, 'fees_taxable' => true],
        'OM' => ['rate' => 5.0, 'fees_taxable' => true],
        'BH' => ['rate' => 10.0, 'fees_taxable' => true],
        'JO' => ['rate' => 16.0, 'fees_taxable' => true],  // general sales tax; restaurant rate CONFIRM
        'EG' => ['rate' => 14.0, 'fees_taxable' => true],  // CONFIRM service-charge treatment
        'TN' => ['rate' => 19.0, 'fees_taxable' => true],  // CONFIRM reduced restaurant rate
        'LY' => ['rate' => 0.0, 'fees_taxable' => false],  // J32: no VAT in Libya (stamp duty is an opt-in levy)
    ];

    /**
     * J32: per-store rate presets — OFF until the owner picks one; picking one writes the store's rate on save.
     *
     * @var array<string, array{country: string, rate: float, label: string}>
     */
    public const RATE_PRESETS = [
        'jo_tourist_8' => ['country' => 'JO', 'rate' => 8.0, 'label' => 'Jordan tourist restaurant 8%'],
        'tn_reduced_7' => ['country' => 'TN', 'rate' => 7.0, 'label' => 'Tunisia reduced rate 7%'],
        'tn_reduced_13' => ['country' => 'TN', 'rate' => 13.0, 'label' => 'Tunisia reduced rate 13%'],
    ];

    public static function feesTaxable(?string $country): bool
    {
        return self::RULES[strtoupper((string) $country)]['fees_taxable'] ?? false;
    }

    /** The country's standard rate, or null when unknown. */
    public static function standardRate(?string $country): ?float
    {
        return self::RULES[strtoupper((string) $country)]['rate'] ?? null;
    }

    /** The preset's rate, or null when the key is unknown. */
    public static function presetRate(?string $preset): ?float
    {
        return self::RATE_PRESETS[(string) $preset]['rate'] ?? null;
    }

    /**
     * J32: the store's tax settings as saved — a chosen rate preset sets the rate; an empty rate takes the country's
     * default (only for a known country). Every rate stays editable.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function applyDefaults(array $options): array
    {
        if (array_key_exists('tax_rate_preset', $options) && ($rate = self::presetRate((string) $options['tax_rate_preset'])) !== null) {
            $options['restaurant_taxx_charge'] = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        }
        if (array_key_exists('restaurant_taxx_charge', $options) && trim((string) $options['restaurant_taxx_charge']) === ''
            && ($rate = self::standardRate((string) ($options['fiscal_country'] ?? ''))) !== null) {
            $options['restaurant_taxx_charge'] = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0';
        }

        return $options;
    }

    /** Countries where a store still on the legacy tax (no VAT on fees) should be told to opt in. */
    public static function mandatesFeeVat(?string $country): bool
    {
        return self::feesTaxable($country);
    }
}
