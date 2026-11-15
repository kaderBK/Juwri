<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Requests;

use App\Services\StorefrontThemeService;
use App\Services\Theme\VerticalThemeSets;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Juwri\Core\Models\Store;
use Juwri\Core\Rules\GloballyUnique;
use Juwri\Core\Rules\PhishingSlug;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Modules\Fiscal\Services\Zatca\Vatex;
use Juwri\Modules\Ordering\Services\Tax\TaxCountryRules;
use Juwri\Modules\Ordering\Services\Tax\TaxLevies;

/**
 * Shared validation rules for the store-settings editor.
 *
 * F0-1 (reseller-store): the owner editor (User\StoreController::update) and the
 * new reseller Store console both validate the same fields — this is the single
 * source of truth so the two entry points can never drift.
 *
 * Rules are split by tab so a partial save (reseller edits one tab) validates
 * only its own fields. `rules($store)` with no tab filter returns the full set,
 * which is what the owner full-form submit uses — byte-identical to the old
 * inline validator.
 */
class StoreSettingsRules
{
    /**
     * Tab groups. A partial save passes the tab keys it owns; omitting $tabs
     * (or passing []) returns every rule (the owner full-form behaviour).
     *
     * @param  list<string>  $tabs
     * @return array<string, mixed>
     */
    public static function rules(Store $store, array $tabs = []): array
    {
        $all = self::byTab($store);

        if ($tabs === []) {
            return array_merge(...array_values($all));
        }

        $out = [];
        foreach ($tabs as $tab) {
            if (isset($all[$tab])) {
                $out = array_merge($out, $all[$tab]);
            }
        }

        return $out;
    }

    /**
     * The rules grouped by settings tab. Keep the field lists aligned with
     * StoreSettingsService::OPTION_KEYS and the owner edit blade.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function byTab(Store $store): array
    {
        $templates = array_keys(app(StorefrontThemeService::class)->available());
        // P6-176: Saudi formats apply when the submitted (or, on a partial save without it, the stored) fiscal country is SA
        $ksa = function (Fluent $input) use ($store): bool {
            $attrs = $input->getAttributes();
            $country = array_key_exists('fiscal_country', $attrs) ? $attrs['fiscal_country'] : store_options($store->id, 'fiscal_country');

            return strtoupper(is_scalar($country) ? (string) $country : '') === 'SA';
        };

        return [
            'identity' => [
                'color' => ['required'],
                'cover_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
                'main_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
                'slug' => ['nullable', 'alpha_dash', 'not_regex:/^[0-9]+$/', new ReservedSlug, new PhishingSlug($store->slug), new GloballyUnique('stores', 'slug', $store->id)],
                'title' => ['required', 'string', 'max:150'],
                'sub_title' => ['nullable', 'string', 'max:255'],
                'description' => ['required', 'string'],
                'address' => ['required', 'string', 'max:255'],
                'timing' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:20'],
                // DIRECTORY L1 T1-1: cuisine multi-select, capped at 3 (D2/UI-KIT).
                'cuisines' => ['nullable', 'array', 'max:3'],
                'cuisines.*' => ['integer', 'exists:cuisines,id'],
                // P6-289: optional store-level timezone override. Empty value
                // clears the option (falls back to the primary branch tz); an
                // invalid IANA zone fails validation so the owner can't break
                // every store-scoped day boundary.
                'timezone' => ['nullable', 'string', 'timezone:all'],
            ],
            'design' => [
                /* enabled templates + the restaurant's current one (grandfathered
                   if it was disabled after being selected).
                   rev/multi-vertical Lane C (C4): scoped to the store's own
                   vertical theme set, so a crafted request can't move a store
                   onto a template outside its vertical (the real owner-facing
                   picker already only ever offers the matching set — see
                   ThemeCustomizerController::setTemplate()). A restaurant's set
                   is every existing template, so this is unchanged for it. */
                'store_template' => ['required', Rule::in(array_merge(
                    (function () use ($templates, $store) {
                        $sets = app(VerticalThemeSets::class);
                        $wanted = app(VerticalRegistry::class)->themeSet($store->store_type ?? null);

                        return array_values(array_filter($templates, fn (string $slug) => $sets->setFor($slug) === $wanted));
                    })(),
                    array_filter([store_options($store->id, 'store_template')])
                ))],
            ],
            'languages' => [
                'default_language' => ['required_with:menu_languages'],
            ],
            'currency' => [
                'currency_code' => ['required', 'string', 'max:4', 'regex:/^[A-Z]{3}$/'],
                'currency_sign' => ['required', 'string', 'max:6'],
                'currency_pos' => ['required', 'integer', 'min:0', 'max:1'],
                'currency_rtl_auto' => ['nullable', 'in:0,1'],
                // MC-33: display-currency source + the auto-mode code list (each a
                // 3-letter ISO code; unknown codes are silently ignored downstream).
                'currency_rates_mode' => ['nullable', 'in:manual,auto'],
                'display_currencies' => ['nullable', 'string', 'max:500', 'regex:/^\s*([A-Za-z]{3})(\s*,\s*[A-Za-z]{3})*\s*$/'],
            ],
            // Store-settings audit (P3): the owner full-form path validated NO tax
            // fields — an out-of-vocabulary tax_type was persisted raw and the tax
            // engine (=== 'fixed') then treated it as a percentage. The reseller Tax
            // tab already validated these inline; this shared group makes both paths
            // agree. A FIXED tax is a flat amount (can exceed 100), so no max on the
            // charge; fiscal identity is shape-validated only (never locked to the
            // registry, so a later-dropped country code can't reject an old save).
            'tax' => [
                'restaurant_taxx_charge' => ['nullable', 'numeric', 'min:0'],
                'restaurant_tax_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
                'restaurant_tax_label' => ['nullable', 'string', 'max:60'],
                'tax_engine' => ['nullable', Rule::in(['', 'vat', 'order'])], // P6-99b; P6-186 order = whole-order VAT
                'tax_prices_include' => ['nullable', 'in:0,1'],
                'tax_delivery_taxable' => ['nullable', 'in:0,1'], // J32
                'tax_tray_taxable' => ['nullable', 'in:0,1'],
                'tax_service_taxable' => ['nullable', 'in:0,1'],
                'tax_rate_preset' => ['nullable', Rule::in(array_merge([''], array_keys(TaxCountryRules::RATE_PRESETS)))],
                'tax_zero_vatex' => ['nullable', Rule::in(array_merge([''], array_keys(Vatex::choices('Z'))))], // P6-175
                'tax_exempt_vatex' => ['nullable', Rule::in(array_merge([''], array_keys(Vatex::choices('E'))))],
                'tax_levies' => ['nullable', 'array', 'max:'.TaxLevies::MAX],
                'tax_levies.*.name' => ['nullable', 'string', 'max:60'],
                'tax_levies.*.type' => ['nullable', Rule::in(['percent', 'fixed'])],
                'tax_levies.*.value' => ['nullable', 'numeric', 'min:0'],
                'tax_levies.*.base' => ['nullable', Rule::in(['items', 'total'])],
                'tax_levies.*.over' => ['nullable', 'numeric', 'min:0'],
                'tax_levies.*.value_over' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'fiscal_country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
                // P6-176: Saudi formats when the fiscal country is SA (VAT 15 digits starting and ending with 3,
                // building / additional number 4 digits, postal code 5 digits); other countries keep the shape check only
                'fiscal_vat_number' => ['nullable', 'string', 'max:40', Rule::when($ksa, ['regex:/^3\d{13}3$/'])],
                'fiscal_cr_number' => ['nullable', 'string', 'max:40'],
                'fiscal_legal_name' => ['nullable', 'string', 'max:150'],
                'fiscal_street' => ['nullable', 'string', 'max:150'],
                'fiscal_building_number' => ['nullable', 'string', Rule::when($ksa, ['regex:/^\d{4}$/'], ['max:20'])],
                'fiscal_additional_number' => ['nullable', 'string', Rule::when($ksa, ['regex:/^\d{4}$/'], ['max:20'])],
                'fiscal_district' => ['nullable', 'string', 'max:100'],
                'fiscal_city' => ['nullable', 'string', 'max:100'],
                'fiscal_postal_code' => ['nullable', 'string', Rule::when($ksa, ['regex:/^\d{5}$/'], ['max:20'])],
            ],
            'ordering' => [
                // ZERO-TOUCH Track C: static prep-time fallback ("25-35", minutes).
                'prep_eta_static' => ['nullable', 'regex:/^\d{1,3}-\d{1,3}$/'],
                // Numeric bounds (P3). All nullable — an absent/blank field (empty
                // strings arrive as null via ConvertEmptyStringsToNull) never rejects
                // an existing store's save. Bounds verified against prod: no live
                // value sits outside these ranges.
                'min_order_value' => ['nullable', 'numeric', 'min:0'],
                'restaurant_delivery_charge' => ['nullable', 'numeric', 'min:0'],
                'restaurant_free_delivery_over' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'restaurant_free_delivery_covers_branch_fee' => ['nullable', 'in:0,1'], // P5-34
                'waitlist_avg_turn' => ['nullable', 'integer', 'min:0', 'max:240'],
                'offline_queue_expiry_min' => ['nullable', 'integer', 'min:5', 'max:240'],
                'scheduling_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
                'scheduling_slot_interval' => ['nullable', 'integer', 'min:1', 'max:1440'],
                'scheduling_max_days' => ['nullable', 'integer', 'min:0', 'max:365'],
                'scheduling_prep_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
                'scheduling_slot_capacity' => ['nullable', 'integer', 'min:0'],
                'laundry_turnaround_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
                'gift_card_min' => ['nullable', 'numeric', 'min:0'],
                'gift_card_max' => ['nullable', 'numeric', 'min:0'],
                'gift_card_expiry_months' => ['nullable', 'integer', 'min:0'],
                'reservation_deposit_amount' => ['nullable', 'numeric', 'min:0'],
                'reservation_deposit_min_party' => ['nullable', 'integer', 'min:0'],
                'reservation_deposit_refund_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
                'birthday_reward_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'birthday_validity_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'order_board_ready_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            ],
            // The legacy welcome-page 'bio' group (link*, bio_title/description,
            // bio_color_*, bio images) was retired in Phase 3 — that content lives
            // on the new biolink now, validated by its own editor.
        ];
    }
}
