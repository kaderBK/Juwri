{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Registry-driven plan feature list (M13-3): limits from plan_entitlements
     (falls back to plans.settings), every feature-registry flag, and an add-on
     link for an off feature that is sold à la carte. Kit markup (.mk-checks). --}}
@php
    /** @var object $plan a plan row (PlanCatalog) */
    /* M13-3: features sourced from the typed `plan_entitlements` rows keyed by
       the feature registry (a new registry key surfaces automatically). Falls
       back to plans.settings for plans not yet mirrored. */
    $s = $plan->settings ?? null;
    $sval = function ($key, $default = null) use ($s) {
        if (is_object($s)) return $s->{$key} ?? $default;
        if (is_array($s)) return $s[$key] ?? $default;
        return $default;
    };
    $fmtLimit = function ($v, $raw = false) {
        if ($raw && $v === null) return ___('Unlimited');
        if ($v === null || $v === '') return '0';
        return \App\Services\Entitlements::isUnlimitedRawLimit($v) ? ___('Unlimited') : number_format((int) $v);
    };

    $entRows = collect();
    try {
        $entRows = app(\Juwri\Core\Contracts\Billing\PlanCatalog::class)->entitlementRows($plan->id);
    } catch (\Throwable $e) { $entRows = collect(); }

    $limitLabels = [
        'restaurant_limit' => ___('Stores'),
        'category_limit'   => ___('Menu categories'),
        'menu_limit'       => ___('Menu items'),
        'scan_limit'       => ___('Scans / month'),
    ];

    $rfeatures = [];

    if ($entRows->isNotEmpty()) {
        foreach ($entRows as $key => $row) {
            if ($row->int_value === null && $row->bool_value !== null) continue;
            if (\Illuminate\Support\Str::endsWith($key, '_limit') || $row->int_value !== null) {
                $label = $limitLabels[$key] ?? \Illuminate\Support\Str::of($key)->replace('_limit', '')->replace('_', ' ')->title();
                $rfeatures[] = ['on' => true, 'label' => $fmtLimit($row->int_value, true) . ' ' . $label, 'addon_sku' => null];
            }
        }
        foreach (\App\Services\FeatureRegistry::all() as $feat) {
            $row = $entRows[$feat['key']] ?? null;
            $on = $row ? (bool) $row->bool_value : (bool) ($feat['default'] ?? false);
            $addonSku = null;
            if (! $on) {
                try {
                    $addonSku = app(\Juwri\Core\Contracts\Billing\FeatureCommerce::class)->addonSkuFor($feat['key']);
                } catch (\Throwable $e) { $addonSku = null; }
            }
            $rfeatures[] = ['on' => $on, 'label' => $feat['label'], 'addon_sku' => $addonSku];
        }
    } else {
        $rfeatures = [
            ['on' => true, 'label' => $fmtLimit($sval('restaurant_limit')) . ' ' . ___('Stores'), 'addon_sku' => null],
            ['on' => true, 'label' => $fmtLimit($sval('category_limit')) . ' ' . ___('Menu categories'), 'addon_sku' => null],
            ['on' => true, 'label' => $fmtLimit($sval('menu_limit')) . ' ' . ___('Menu items'), 'addon_sku' => null],
            ['on' => true, 'label' => $fmtLimit($sval('scan_limit')) . ' ' . ___('Scans / month'), 'addon_sku' => null],
            ['on' => (bool) $sval('allow_ordering'), 'label' => ___('Online ordering'), 'addon_sku' => null],
            ['on' => (bool) $sval('table_qr'),       'label' => ___('Table QR codes'), 'addon_sku' => null],
            ['on' => (bool) $sval('hide_branding'),  'label' => ___('Remove branding'), 'addon_sku' => null],
        ];
    }
@endphp

<ul class="mk-checks mk-plan-feats">
    @foreach ($rfeatures as $f)
        <li @class(['is-off' => ! $f['on']])>
            {{ $f['label'] }}
            @if (! $f['on'] && ! empty($f['addon_sku']) && \Illuminate\Support\Facades\Route::has('addons.checkout'))
                <a href="{{ route('addons.checkout', ['type' => $f['addon_sku']]) }}">{{ ___('add-on') }}</a>
            @endif
        </li>
    @endforeach
</ul>
