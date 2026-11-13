{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $mkLogo = ($mkReseller && brand_logo())
        ? brand_logo()
        : (! empty(data_get($settings, 'site_logo')) ? media_thumb_url('storage/logo/', data_get($settings, 'site_logo'), null, 140) : null);
    $mkLinks = array_filter([
        ['home', ___('Home')],
        (Route::has('features') && app(\Juwri\Core\Contracts\Content\PlatformSite::class)->enabled()) ? ['features', ___('Features')] : null,
        (Route::has('pricing') && app(\Juwri\Core\Contracts\Content\PlatformSite::class)->enabled()) ? ['pricing', ___('Pricing')] : null,
        Route::has('blog.index') ? ['blog.index', ___('Blog')] : null,
        Route::has('marketplace.index') ? ['marketplace.index', ___('Marketplace')] : null,
        Route::has('partners.index') ? ['partners.index', ___('Partners')] : null,
    ]);
    $mkLangs = (isset($languages) ? collect($languages) : collect())->unique('code')->values();
    $mkCode = get_lang();
@endphp
<header class="mk-top">
    <div class="mk-wrap mk-top__bar">
        <a class="mk-top__brand" href="{{ route('home') }}" aria-label="{{ brand_title() }}">
            @if ($mkLogo)
                <span class="brand-tile brand-tile--sm"><img src="{{ $mkLogo }}" alt="{{ brand_title() }}" width="140" height="140"></span>
            @else
                <span class="mk-top__name">{{ brand_title() }}</span>
            @endif
        </a>

        <nav class="mk-top__nav" aria-label="{{ ___('Main') }}">
            @foreach ($mkLinks as [$mkRoute, $mkLabel])
                <a href="{{ route($mkRoute) }}" @if (request()->routeIs($mkRoute)) aria-current="page" @endif>{{ $mkLabel }}</a>
            @endforeach
        </nav>

        <div class="mk-top__tools">
            @if ($mkLangs->count() > 1)
                <div class="ui-pop-host" data-ui-pop>
                    <button type="button" class="mk-top__icon" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true"
                            aria-label="{{ ___('Change language') }}: {{ strtoupper($mkCode) }}">
                        <x-ui.icon name="world" size="18" /><span class="mk-top__code">{{ strtoupper($mkCode) }}</span>
                    </button>
                    <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                        @foreach ($mkLangs as $mkLang)
                            <a class="ui-pop__item" href="{{ lang_url($mkLang->code) }}" @if ($mkLang->code === $mkCode) aria-current="true" @endif>
                                <span class="ui-pop__label">{{ $mkLang->name }}</span>
                                @if ($mkLang->code === $mkCode)<x-ui.icon name="check" size="16" />@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
            <button type="button" class="mk-top__icon" data-ui-theme-toggle aria-pressed="false" aria-label="{{ ___('Toggle theme') }}">
                <x-ui.icon name="moon" size="18" class="mk-top__moon" />
                <x-ui.icon name="sun" size="18" class="mk-top__sun" />
            </button>
            @if (Route::has('login'))
                <a href="{{ route('login') }}" class="mk-top__login">{{ ___('Login') }}</a>
            @endif
            <a href="{{ route('register') }}" class="ui-btn mk-top__cta">{{ ___('Get Started') }}</a>

            <details class="mk-menu">
                <summary class="mk-top__icon" aria-label="{{ ___('Open menu') }}"><x-ui.icon name="more" size="20" /></summary>
                <div class="mk-menu__panel">
                    @foreach ($mkLinks as [$mkRoute, $mkLabel])
                        <a href="{{ route($mkRoute) }}">{{ $mkLabel }}</a>
                    @endforeach
                    <div class="mk-menu__actions">
                        @if (Route::has('login'))
                            <a href="{{ route('login') }}" class="ui-btn ui-btn--secondary">{{ ___('Login') }}</a>
                        @endif
                        <a href="{{ route('register') }}" class="ui-btn">{{ ___('Get Started') }}</a>
                    </div>
                </div>
            </details>
        </div>
    </div>
</header>
