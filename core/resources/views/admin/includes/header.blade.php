{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Superadmin top bar on the kit (admin.layout): drawer toggle, user search,
  view site, theme, language, notifications and account menu. Pop-overs use
  [data-ui-pop] (assets/owner-ui/layout.js). `$notifications` and
  `$totalUnreadNotifications` come from the admin.includes.header composer.
--}}
@php
    $__me = auth()->user();
    $__brand = trim(\Illuminate\Support\Str::afterLast((string) ($settings->site_title ?? ''), '|')) ?: ___('Admin');
    $__unread = (int) ($totalUnreadNotifications ?? 0);
    $__langs = collect($admin_languages ?? [])->unique('code')->values();
    $__lang = function_exists('get_lang') ? get_lang() : app()->getLocale();
@endphp
<header class="ui-top" role="banner">
    <button type="button" class="ui-top__btn ui-top__menu" data-ui-drawer-toggle aria-controls="appMenubar" aria-expanded="false" aria-label="{{ ___('Toggle menu') }}">
        <x-ui.shell.glyph name="menu-2" kit="list" />
    </button>
    <a class="ui-top__brand" href="{{ route('admin.dashboard') }}" aria-label="{{ $__brand }}">{{ \Illuminate\Support\Str::limit($__brand, 18) }}</a>

    {{-- Global search → the users list, which filters on ?search. "/" focuses it. --}}
    <form class="ui-top__search" action="{{ route('admin.users.index') }}" method="GET" role="search">
        <label class="ui-top__field">
            <x-ui.icon name="search" size="18" />
            <input type="search" name="search" class="ui-top__input" data-admin-search
                   placeholder="{{ ___('Search users by name, email…') }}" aria-label="{{ ___('Search users') }}"
                   value="{{ request()->routeIs('admin.users.index') ? request('search') : '' }}">
            <kbd class="ui-top__kbd" aria-hidden="true">/</kbd>
        </label>
    </form>

    <div class="ui-top__end">
        <a class="ui-top__btn" href="{{ url('/') }}" target="_blank" rel="noopener" aria-label="{{ ___('View site') }}" title="{{ ___('View site') }}">
            <x-ui.shell.glyph name="world" kit="external" />
        </a>

        <button type="button" class="ui-top__btn" data-ui-theme-toggle aria-pressed="false"
                aria-label="{{ ___('Switch to dark') }}"
                data-label-dark="{{ ___('Switch to dark') }}" data-label-light="{{ ___('Switch to light') }}">
            <x-ui.shell.glyph name="moon" kit="sparkle" class="ui-top__moon" />
            <x-ui.shell.glyph name="sun" kit="flame" class="ui-top__sun" />
        </button>

        @if ($__langs->isNotEmpty())
            <div class="ui-pop-host" data-ui-pop>
                <button type="button" class="ui-top__btn ui-top__lang" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Language') }}">
                    {{ strtoupper($__lang) }}
                </button>
                <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                    @foreach ($__langs as $__l)
                        <a class="ui-pop__item" href="{{ lang_url($__l->code) }}" @if ($__l->code === $__lang) aria-current="true" @endif>
                            <span class="ui-pop__label">{{ $__l->name }}</span>
                            @if ($__l->code === $__lang)<x-ui.icon name="check" size="16" />@endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="ui-pop-host ui-top__bell" data-ui-pop>
            <button type="button" class="ui-top__btn" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Notifications') }}">
                <span class="ui-top__bell-icon"><x-ui.icon name="bell" /></span>
            </button>
            @if ($__unread > 0)
                <span class="ui-top__badge">{{ $__unread > 99 ? '99+' : $__unread }}</span>
            @endif
            <div class="ui-pop ui-pop--end ui-pop--bell" data-ui-pop-panel hidden>
                <div class="ui-admin-pophead">
                    <p class="ui-pop__head">{{ ___('Notifications') }}</p>
                    @if ($__unread > 0)
                        <a class="ui-admin-pophead__link" href="{{ route('admin.notifications.markasread') }}">{{ ___('Mark all read') }}</a>
                    @endif
                </div>
                <div class="ui-admin-popscroll">
                    @forelse ($notifications ?? [] as $__n)
                        @php $__title = \Illuminate\Support\Str::limit((string) $__n->title, 40); @endphp
                        @if ($__n->link)
                            <a class="ui-pop__item" href="{{ route('admin.notifications.view', $__n->id) }}" title="{{ $__n->title }}">
                        @else
                            <div class="ui-pop__item" title="{{ $__n->title }}">
                        @endif
                            <span class="ui-pop__label">{{ $__title }}</span>
                            <span class="ui-pop__meta">{{ $__n->created_at?->diffForHumans() }}</span>
                            @unless ($__n->status)<span class="ui-admin-dot" aria-label="{{ ___('Unread') }}"></span>@endunless
                        @if ($__n->link)
                            </a>
                        @else
                            </div>
                        @endif
                    @empty
                        <p class="ui-pop__empty">{{ ___('No notifications found') }}</p>
                    @endforelse
                </div>
                <div class="ui-pop__foot">
                    <a class="ui-pop__item ui-pop__item--quiet" href="{{ route('admin.notifications.index') }}">{{ ___('View all notifications') }}</a>
                </div>
            </div>
        </div>

        @if ($__me)
            <div class="ui-pop-host" data-ui-pop>
                <button type="button" class="ui-top__avatar" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true" aria-label="{{ ___('Account menu') }}">
                    {{ mb_strtoupper(mb_substr($__me->name ?: ($__me->username ?? $__me->email), 0, 1)) }}
                </button>
                <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                    <div class="ui-pop__who">
                        <strong>{{ $__me->name ?? '—' }}</strong>
                        <span>{{ $__me->email }}</span>
                    </div>
                    <a class="ui-pop__item" href="{{ route('admin.settings.index') }}"><x-ui.icon name="settings" size="18" />{{ ___('Settings') }}</a>
                    <a class="ui-pop__item" href="{{ url('/') }}" target="_blank" rel="noopener"><x-ui.shell.glyph name="world" kit="external" size="18" />{{ ___('View site') }}</a>
                    <form method="POST" action="{{ route('logout') }}" class="ui-pop__foot">
                        @csrf
                        <button type="submit" class="ui-pop__item ui-pop__item--danger"><x-ui.shell.glyph name="logout" kit="back" size="18" />{{ ___('Logout') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</header>
