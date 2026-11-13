{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    FIX-7 F7-4 (gap audit): near-limit warning AT THE POINT OF HIT.

    The 80% soft-limit warning existed only on the Add-ons page — i.e. only where a
    merchant had already gone looking to buy. Owners hit the hard "Limit exceeded"
    error on the create screens with no prior notice. This partial renders the same
    Entitlements::nearLimit() signal on those screens.

    Usage: @include('owner.dashboard.partials.near-limit-banner', ['keys' => ['menu', 'category']])

    Sentinel: nearLimit() resolves through Entitlements::limit(), which returns NULL for
    unlimited — an unlimited plan never warns. No raw `< 999` comparison here by design.
--}}
@php
    $__nlUser = auth()->user();
    $__nlKeys = [];
    if ($__nlUser) {
        foreach ((array) ($keys ?? []) as $__nlKey) {
            try {
                if (\App\Services\Entitlements::nearLimit($__nlUser, $__nlKey)) {
                    $__nlKeys[$__nlKey] = [
                        'used'  => \App\Services\Entitlements::usage($__nlUser, $__nlKey),
                        'limit' => \App\Services\Entitlements::limit($__nlUser, $__nlKey),
                    ];
                }
            } catch (\Throwable $e) {
                // a warning banner must never break the page it decorates
            }
        }
    }
    $__nlLabels = [
        'restaurant' => ___('Stores'),
        'scan'       => ___('Scans'),
        'category'   => ___('Categories'),
        'menu'       => ___('Menu items'),
    ];
@endphp
@if (! empty($__nlKeys))
    <div class="ui-cluster ui-note" data-tone="warning" data-near-limit-banner>
        <x-ui.icon name="alert" size="18" />
        <span>
            {{ ___('You are close to your plan limit for:') }}
            @foreach ($__nlKeys as $__nlKey => $__nlUse)
                <strong>{{ $__nlLabels[$__nlKey] ?? ucfirst(str_replace('_', ' ', $__nlKey)) }}</strong>
                <span class="ui-nowrap">({{ number_format($__nlUse['used']) }}/{{ number_format((int) $__nlUse['limit']) }})</span>{{ ! $loop->last ? ',' : '.' }}
            @endforeach
        </span>
        {{-- F7-5 consistency: never point a reseller's merchant at the platform store. --}}
        @if (Route::has('addons.index') && ! \Juwri\Core\Support\BrandContext::current()->isReseller())
            <a class="ui-ms-auto" href="{{ route('addons.index') }}">{{ ___('Add a booster pack or upgrade your plan') }}</a>
        @endif
    </div>
@endif
