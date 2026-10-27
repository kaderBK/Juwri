{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Feature roster (marketing/data/feature-defs.php); each tile links to its
     chapter on /features. --}}
@php $mkFeatures = require resource_path('views/marketing/data/feature-defs.php'); @endphp
<section class="mk-sec">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('Your digital menu, online ordering and POS in one place') }}</h2>
            <p class="mk-lead">{{ ___('Menus, orders, payments and marketing — every tool ships in one subscription, wired to the same dashboard.') }}</p>
        </div>
        <ul class="mk-tiles">
            @foreach ($mkFeatures as $mkF)
                <li>
                    <a class="mk-tile" href="{{ Route::has('features') ? route('features').'#'.$mkF['id'] : '#' }}">
                        <span class="mk-tile__ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $mkF['icon'] }}"/></svg></span>
                        <span>{{ $mkF['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if (Route::has('features'))
            <p class="mk-center"><a href="{{ route('features') }}" class="ui-btn">{{ ___('See all features') }}</a></p>
        @endif
    </div>
</section>
