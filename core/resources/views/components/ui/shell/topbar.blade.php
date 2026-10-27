{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.topbar /> — owner shell top bar: drawer toggle, page search
  (Cmd/Ctrl-K), store switcher, setup pill, help, theme, language, notification
  bell and account menu. Pop-overs use [data-ui-pop] (assets/owner-ui/layout.js);
  search and bell are Alpine components defined there too (uiTopbarSearch, notifBell).
--}}
@php
    $__user = auth()->user();
    $__brand = brand_title();
    $__isAdmin = $__user && (method_exists($__user, 'isAdmin') ? $__user->isAdmin() : ($__user->user_type ?? null) === 'admin');
    $__lang = function_exists('current_language') ? current_language() : null;
    // Settings is a localized route: link the variant for the locale in the current URL,
    // so opening it never resets the session language to the default one.
    $__curLoc = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getCurrentLocale();
    $__defLoc = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getDefaultLocale();
    $__settingsUrl = url(($__curLoc && $__curLoc !== $__defLoc ? $__curLoc.'/' : '').'settings');
    $__langs = collect();
    if (isset($languages)) {
        $__langs = $languages->unique('code')->values();
    } else {
        try {
            $__langs = \Juwri\Core\Models\Language::where('active', 1)->orderBy('position')->get()->unique('code')->values();
        } catch (\Throwable $e) {
        }
    }
    $__stores = function_exists('dashboard_stores') ? dashboard_stores() : collect();
    $__store = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
    $__branch = function_exists('dashboard_current_branch') ? dashboard_current_branch() : null;
    $__next = function_exists('onboarding_next_step') ? onboarding_next_step() : null;
    $__helpSlug = \Juwri\Modules\Content\Http\Controllers\HelpCenterController::contextSlug();
@endphp
<header class="ui-top" role="banner">
    <button type="button" class="ui-top__btn ui-top__menu" data-ui-drawer-toggle aria-controls="appMenubar" aria-expanded="false" aria-label="{{ ___('Toggle menu') }}">
        <x-ui.shell.glyph name="menu-2" kit="list" />
    </button>
    <a class="ui-top__brand" href="{{ route('dashboard') }}" aria-label="{{ $__brand }}">{{ \Illuminate\Support\Str::limit($__brand, 18) }}</a>

    <div class="ui-top__search" id="topbar-search-root"
         x-data="topbarSearch(@js(dashboard_nav_search()), @js(route('dashboard.search')))"
         @keydown.window.cmd.k.prevent="focusInput()" @keydown.window.ctrl.k.prevent="focusInput()"
         @click.outside="close()">
        <label class="ui-top__field">
            <x-ui.icon name="search" size="18" />
            <input type="search" class="ui-top__input" x-ref="input" x-model="q"
                   placeholder="{{ ___('Search pages, orders, items…') }}" aria-label="{{ ___('Search pages…') }}"
                   autocomplete="off" role="combobox" aria-expanded="false" :aria-expanded="open.toString()"
                   aria-controls="topbar-search-results"
                   @focus="onFocus()" @input="onInput()"
                   @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                   @keydown.enter.prevent="choose()" @keydown.escape="close()">
            <kbd class="ui-top__kbd" aria-hidden="true">⌘K</kbd>
        </label>
        <div class="ui-pop ui-pop--wide" id="topbar-search-results" role="listbox" x-show="open" x-cloak>
            <template x-if="results.length === 0">
                <p class="ui-pop__empty">{{ ___('No matches') }}</p>
            </template>
            {{-- Keyed by position: results may repeat a url (one help page under several
                 nav rows) or lack one, and a duplicate/undefined key breaks Alpine. --}}
            <template x-for="(item, idx) in results" :key="idx">
                <a class="ui-pop__item" :href="item.url" role="option" :aria-selected="(idx === cursor).toString()"
                   :class="{ 'is-active': idx === cursor }" @mouseenter="cursor = idx" @click="remember(item)">
                    <span class="ui-pop__label" x-text="item.label"></span>
                    <span class="ui-pop__meta" x-text="item.group"></span>
                </a>
            </template>
        </div>
    </div>

    @if ($__stores->isNotEmpty() && Route::has('dashboard.context'))
        <div class="ui-pop-host ui-top__ctx" id="topbar-context" data-ui-pop>
            <button type="button" class="ui-top__chip" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Store') }}">
                <x-ui.icon name="store" size="18" />
                <span class="ui-top__chip-text">{{ $__store->title ?? ___('All stores') }}@if ($__branch) · {{ $__branch->name }}@endif</span>
                <x-ui.icon name="chevron-down" size="16" />
            </button>
            <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                <p class="ui-pop__head">{{ ___('Switch store') }}</p>
                <x-ui.shell.context-select />
            </div>
        </div>
    @endif

    <div class="ui-top__end">
        @if ($__next)
            {{-- Next onboarding step; gone once setup is finished or dismissed. --}}
            <a href="{{ $__next['url'] }}" class="ui-btn ui-btn--secondary ui-btn--sm ui-top__setup" title="{{ ___('Finish setup') }}">
                <x-ui.shell.glyph name="rocket" kit="sparkle" size="16" /><span>{{ $__next['label'] }}</span>
            </a>
        @endif

        <div class="ui-pop-host" data-ui-pop>
            <button type="button" class="ui-top__btn" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Help') }}">
                <x-ui.icon name="book-open" />
            </button>
            <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                @if ($__helpSlug)
                    <a class="ui-pop__item" href="{{ route('help.show', $__helpSlug) }}" target="_blank" rel="noopener">{{ ___('Help for this page') }}</a>
                @endif
                <a class="ui-pop__item" href="{{ route('help.index') }}" target="_blank" rel="noopener">{{ ___('Help Center') }}</a>
                <a class="ui-pop__item" href="{{ route('changelog') }}" target="_blank" rel="noopener">{{ ___("What's new") }}</a>
            </div>
        </div>

        <button type="button" class="ui-top__btn" data-ui-theme-toggle aria-pressed="false"
                aria-label="{{ ___('Switch to dark') }}"
                data-label-dark="{{ ___('Switch to dark') }}" data-label-light="{{ ___('Switch to light') }}">
            <x-ui.shell.glyph name="moon" kit="sparkle" class="ui-top__moon" />
            <x-ui.shell.glyph name="sun" kit="flame" class="ui-top__sun" />
        </button>

        @if (count($__langs))
            <div class="ui-pop-host" data-ui-pop>
                <button type="button" class="ui-top__btn ui-top__lang" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Language') }}">
                    {{ strtoupper($__lang->code ?? 'EN') }}
                </button>
                <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                    @foreach ($__langs as $__l)
                        <a class="ui-pop__item" href="{{ function_exists('lang_url') ? lang_url($__l->code) : url('change-lang/'.$__l->code) }}" data-turbo="false"
                           @if (($__lang->code ?? null) === $__l->code) aria-current="true" @endif>
                            <span class="ui-pop__label">{{ $__l->name }}</span>
                            @if (($__lang->code ?? null) === $__l->code)<x-ui.icon name="check" size="16" />@endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if (Route::has('dashboard.notifications'))
            @php
                $__ordUrl = ($__store && Route::has('stores.orders')) ? route('stores.orders', $__store->id) : (($__isAdmin && Route::has('admin.orders.index')) ? route('admin.orders.index') : '#');
                $__revUrl = ($__store && Route::has('dashboard.reviews')) ? route('dashboard.reviews', $__store->id) : '#';
                $__dispUrl = Route::has('stores.disputes.index') ? route('stores.disputes.index') : '#';
                $__waitUrl = ($__store && Route::has('waitlist.index')) ? route('waitlist.index', $__store->id) : '#';
                $__hookUrl = Route::has('api.index') ? route('api.index') : '#';
            @endphp
            <div class="ui-pop-host ui-top__bell" data-ui-pop
                 x-data="notifBell(@js(route('dashboard.notifications')), @js(Route::has('dashboard.notifications.seen') ? route('dashboard.notifications.seen') : ''), @js(csrf_token()))"
                 @click="ensure()">
                <button type="button" class="ui-top__btn" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true"
                        aria-label="{{ ___('Notifications') }}" @click="markSeen()">
                    <span class="ui-top__bell-icon" :class="{ 'notif-bell--shake': pop }"><x-ui.icon name="bell" /></span>
                </button>
                {{-- Lights only when the total rose above what the owner last opened. --}}
                <span class="ui-top__badge notif-badge" :class="{ 'notif-badge--pop': pop }"
                      x-show="counts.badge && counts.total > 0" x-cloak x-text="counts.total > 99 ? '99+' : counts.total"></span>
                <div class="ui-pop ui-pop--end ui-pop--bell" data-ui-pop-panel hidden>
                    <p class="ui-pop__head">{{ ___('Notifications') }}</p>
                    <template x-if="counts.total === 0">
                        <p class="ui-pop__empty">{{ ___('All clear') }}</p>
                    </template>
                    <a class="ui-pop__item notif-row" href="{{ $__ordUrl }}" x-show="counts.orders > 0">
                        <x-ui.icon name="orders" size="18" /><span class="ui-pop__label">{{ ___('New orders') }}</span><span class="ui-pop__count" x-text="counts.orders"></span>
                    </a>
                    <a class="ui-pop__item notif-row" href="{{ $__revUrl }}" x-show="counts.reviews > 0">
                        <x-ui.icon name="star" size="18" /><span class="ui-pop__label">{{ ___('Pending reviews') }}</span><span class="ui-pop__count" x-text="counts.reviews"></span>
                    </a>
                    <a class="ui-pop__item notif-row" href="{{ $__dispUrl }}" x-show="counts.disputes > 0">
                        <x-ui.icon name="alert" size="18" /><span class="ui-pop__label">{{ ___('Open disputes') }}</span><span class="ui-pop__count" x-text="counts.disputes"></span>
                    </a>
                    <a class="ui-pop__item notif-row" href="{{ $__waitUrl }}" x-show="counts.waitlist > 0">
                        <x-ui.icon name="clock" size="18" /><span class="ui-pop__label">{{ ___('Waiting guests') }}</span><span class="ui-pop__count" x-text="counts.waitlist"></span>
                    </a>
                    <a class="ui-pop__item notif-row" href="{{ $__hookUrl }}" x-show="counts.webhooks > 0">
                        <x-ui.icon name="error" size="18" /><span class="ui-pop__label">{{ ___('Failed webhooks') }}</span><span class="ui-pop__count" x-text="counts.webhooks"></span>
                    </a>
                    @if (Route::has('dashboard.notifPrefs') || Route::has('dashboard.sounds'))
                        <div class="ui-pop__foot">
                            @if (Route::has('dashboard.notifPrefs'))
                                <a class="ui-pop__item ui-pop__item--quiet" href="{{ route('dashboard.notifPrefs') }}"><x-ui.icon name="bell" size="16" />{{ ___('Notification preferences') }}</a>
                            @endif
                            @if (Route::has('dashboard.sounds'))
                                <a class="ui-pop__item ui-pop__item--quiet" href="{{ route('dashboard.sounds') }}"><x-ui.icon name="settings" size="16" />{{ ___('Sound settings') }}</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($__user)
            <div class="ui-pop-host" data-ui-pop>
                <button type="button" class="ui-top__avatar" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Account menu') }}">
                    {{ mb_strtoupper(mb_substr($__user->username ?? $__user->email, 0, 1)) }}
                </button>
                <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                    <div class="ui-pop__who">
                        <strong>{{ $__user->username ?? '—' }}</strong>
                        <span>{{ $__user->email }}</span>
                    </div>
                    @if (Route::has('settings.editProfile') && \App\Http\Middleware\EnsureStaffDashboardPermission::staffMayOpen('settings'))
                        <a class="ui-pop__item" href="{{ $__settingsUrl }}"><x-ui.icon name="settings" size="18" />{{ ___('Settings') }}</a>
                    @endif
                    @if (Route::has('wallet.index'))
                        <a class="ui-pop__item" href="{{ route('wallet.index') }}"><x-ui.icon name="wallet" size="18" />{{ ___('Wallet') }}</a>
                    @endif
                    @if (function_exists('is_staff_dashboard') && is_staff_dashboard() && Route::has('staff.passkeys.index'))
                        <a class="ui-pop__item" href="{{ route('staff.passkeys.index') }}"><x-ui.icon name="shield" size="18" />{{ ___('My passkeys') }}</a>
                    @endif
                    @if ($__isAdmin)
                        <a class="ui-pop__item" href="{{ url((function_exists('admin_url') ? admin_url() : 'admin').'/dashboard') }}"><x-ui.shell.glyph name="shield" kit="settings" size="18" />{{ ___('Admin panel') }}</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}" class="ui-pop__foot">
                        @csrf
                        <button type="submit" class="ui-pop__item ui-pop__item--danger"><x-ui.shell.glyph name="logout" kit="back" size="18" />{{ ___('Log out') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</header>
