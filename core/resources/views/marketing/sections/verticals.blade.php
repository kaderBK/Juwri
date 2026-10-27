{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Other trades: one card per seeded demo store that exists on this host
     (a link can never 404), with its industry page when one is authored. --}}
@php
    $mkTrades = [
        'retail-demo' => ['icon' => '🛍️', 'name' => ___('Retail'), 'blurb' => ___('Products, variants, stock and shipping.')],
        'cosmetic-demo' => ['icon' => '💄', 'name' => ___('Cosmetics'), 'blurb' => ___('Shades, skincare routines and bundles.')],
        'clothing-demo' => ['icon' => '👗', 'name' => ___('Clothing'), 'blurb' => ___('Sizes, colours and a size guide.')],
        'carparts-demo' => ['icon' => '🔧', 'name' => ___('Auto parts'), 'blurb' => ___('Fitment lookup by make, model and year.')],
        'laundry-demo' => ['icon' => '🧺', 'name' => ___('Laundry'), 'blurb' => ___('Per-item or per-kg pickup and delivery.')],
        'pharmacy-demo' => ['icon' => '💊', 'name' => ___('Pharmacy'), 'blurb' => ___('OTC catalogue with expiry and age checks.')],
        'hotel-demo' => ['icon' => '🏨', 'name' => ___('Hotel'), 'blurb' => ___('Rooms, bookings and charge-to-room.')],
    ];
    try {
        $mkLive = \Juwri\Core\Models\Store::whereIn('slug', array_keys($mkTrades))->pluck('slug')->all();
    } catch (\Throwable $e) {
        $mkLive = [];
    }
    $mkTrades = array_intersect_key($mkTrades, array_flip($mkLive));
    try {
        $mkIndustries = \Juwri\Modules\Verticals\Services\VerticalLandingDefs::slugs();
    } catch (\Throwable $e) {
        $mkIndustries = [];
    }
@endphp
@if (! empty($mkTrades))
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Every trade') }}</span>
                <h2 class="mk-h2">{{ ___('Not a restaurant? Still yours.') }}</h2>
                <p class="mk-lead">{{ ___('One storefront engine, tuned for each trade — the words, the fields, and the checkout all follow your business type. Open any live demo.') }}</p>
            </div>
            <div class="mk-cards">
                @foreach ($mkTrades as $mkSlug => $mkT)
                    @php $mkIndustry = \Illuminate\Support\Str::replaceLast('-demo', '', $mkSlug); @endphp
                    <article class="mk-card">
                        <span class="mk-tile__ico" aria-hidden="true">@include('marketing.partials.vt-icon', ['icon' => $mkT['icon'], 'size' => 22])</span>
                        <h3>{{ $mkT['name'] }}</h3>
                        <p>{{ $mkT['blurb'] }}</p>
                        <div class="mk-card__links">
                            @if (in_array($mkIndustry, $mkIndustries, true))
                                <a href="{{ route('industries.show', $mkIndustry) }}">{{ ___('Learn more') }}</a>
                            @endif
                            <a href="{{ route('publicView', $mkSlug) }}" target="_blank" rel="noopener" class="is-muted">{{ ___('View live demo') }}</a>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif
