{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.sidebar /> — owner shell navigation.

  One nav model (juwri_nav_model) feeds this sidebar and the top-bar search, so
  they never drift. Layout: a rail of sections (icon + caption) and a panel with
  the active section's groups. Docked from 1200px; below that it is a drawer
  opened by the top bar's menu button or the phone tab bar's "More".
  Section switching and the drawer live in assets/owner-ui/layout.js.
--}}
@php
    $__reseller = active_brand_reseller();
    $__brand = brand_title();
    $__actor = function_exists('dashboard_actor') ? dashboard_actor() : auth()->user();
    $__owner = function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();
    $__accountName = $__actor->username ?? $__actor->name ?? ___('Account');
    $__plan = $__owner && method_exists($__owner, 'plan') ? $__owner->plan() : null;
    $__stores = function_exists('dashboard_stores') ? dashboard_stores() : collect();
    $__store = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
    $__branch = function_exists('dashboard_current_branch') ? dashboard_current_branch() : null;
    $__branches = $__store
        ? \Juwri\Core\Models\Branch::where('store_id', $__store->id)->orderBy('position')->orderBy('id')->get()
        : collect();
    // A branch-bound staff session cannot leave its branch.
    $__branchLocked = ($__staff = staff_session()) && $__staff->branch_id;
    $__curLoc = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getCurrentLocale();
    $__defLoc = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getDefaultLocale();
    $__settingsUrl = url(($__curLoc && $__curLoc !== $__defLoc ? $__curLoc.'/' : '').'settings');

    $__sections = juwri_nav_model();
    $__pins = function_exists('juwri_nav_pins') ? juwri_nav_pins() : [];
    // Open the section that holds the current page (else the first one).
    $__open = $__sections[0]['key'] ?? 'workspace';
    foreach ($__sections as $__s) {
        foreach ($__s['groups'] as [$__g, $__items]) {
            foreach ($__items as $__i) {
                if (! empty($__i['active'])) {
                    $__open = $__s['key'];
                    break 3;
                }
            }
        }
    }
@endphp
<aside class="ui-side" id="appMenubar" aria-label="{{ ___('Sidebar navigation') }}">
    <div class="ui-side__rail">
        <a class="ui-side__logo brand-tile" href="{{ route('dashboard') }}" aria-label="{{ $__brand }}">
            @if ($__reseller && $__reseller->brand_logo)
                <img src="{{ $__reseller->brand_logo }}" alt="">
            @elseif (! empty($settings->site_logo))
                <img class="ui-side__logo-light" src="{{ asset('storage/logo/'.$settings->site_logo) }}" alt="">
                <img class="ui-side__logo-dark" src="{{ asset('storage/logo/'.($settings->site_logo_footer ?: $settings->site_logo)) }}" alt="">
            @else
                <span class="ui-side__initials">{{ mb_strtoupper(mb_substr($__brand, 0, 2)) }}</span>
            @endif
        </a>
        <div class="ui-side__sections" role="tablist" aria-orientation="vertical">
            @foreach ($__sections as $__s)
                <button type="button" class="ui-side__section" role="tab" id="ui-side-tab-{{ $__s['key'] }}"
                        aria-controls="ui-side-panel-{{ $__s['key'] }}" aria-selected="{{ $__s['key'] === $__open ? 'true' : 'false' }}"
                        data-ui-side-tab="{{ $__s['key'] }}">
                    <x-ui.shell.nav-icon :glyph="$__s['rail']" size="22" />
                    <span class="ui-side__rail-label">{{ $__s['title'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="ui-side__panel">
        <div class="ui-side__head">
            <a class="ui-side__name" href="{{ route('dashboard') }}">{{ \Illuminate\Support\Str::limit($__brand, 18) }}</a>
            <span class="ui-side__tag">{{ ___('Owner') }}</span>
            <button type="button" class="ui-side__close" data-ui-drawer-close aria-label="{{ ___('Close menu') }}" title="{{ ___('Close menu') }}">
                <x-ui.icon name="x" size="18" />
            </button>
        </div>

        <div class="ui-side__scroll">
            @if (count($__pins))
                <nav class="ui-side__nav ui-side__nav--pins" aria-label="{{ ___('Pinned') }}">
                    <p class="ui-side__group">{{ ___('Pinned') }}</p>
                    <ul class="ui-side__list">
                        @foreach ($__pins as $__p)
                            <li><a class="ui-side__link" href="{{ $__p['url'] }}"><x-ui.shell.nav-icon :glyph="$__p['icon']" size="18" /><span class="ui-side__label">{{ $__p['label'] }}</span></a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @foreach ($__sections as $__s)
                <nav class="ui-side__nav" role="tabpanel" id="ui-side-panel-{{ $__s['key'] }}" aria-labelledby="ui-side-tab-{{ $__s['key'] }}"
                     data-ui-side-panel="{{ $__s['key'] }}" @if ($__s['key'] !== $__open) hidden @endif>
                    @foreach ($__s['groups'] as [$__groupLabel, $__items])
                        @continue(! count($__items))
                        {{-- A group heading that only repeats the section title adds nothing. --}}
                        @if ($__groupLabel !== $__s['title'])
                            <p class="ui-side__group">{{ $__groupLabel }}</p>
                        @endif
                        <ul class="ui-side__list">
                            @foreach ($__items as $__i)
                                <li>
                                    {{-- A locked feature stays visible (seeing it is the point): dimmed,
                                         with a lock chip and its one-line pitch as the tooltip. --}}
                                    <a @class(['ui-side__link', 'is-locked' => ! empty($__i['locked'])])
                                       href="{{ $__i['url'] }}"
                                       @if (! empty($__i['active'])) aria-current="page" @endif
                                       @if (! empty($__i['locked']))
                                           title="{{ $__i['tip'] ?? '' }}"
                                           data-spotlight-key="{{ $__i['feature_key'] }}"
                                           data-spotlight-surface="nav"
                                           data-spotlight-cta
                                       @endif>
                                        <x-ui.shell.nav-icon :glyph="$__i['icon'] ?? null" size="18" />
                                        <span class="ui-side__label">{{ $__i['label'] }}</span>
                                        @if (! empty($__i['is_new']))
                                            <span class="ui-side__new" title="{{ ___('New in your plan') }}"><span class="ui-visually-hidden">{{ ___('New in your plan') }}</span></span>
                                        @elseif (! empty($__i['locked']))
                                            <span class="ui-side__lock" aria-label="{{ ___('Not in your plan') }}">
                                                <x-ui.shell.glyph name="lock" kit="alert" size="14" />
                                                @if (! empty($__i['sell']))<span class="ui-side__sell">{{ $__i['sell'] }}</span>@endif
                                            </span>
                                        @elseif (! empty($__i['badge']))
                                            <span class="ui-side__badge">{{ $__i['badge'] > 99 ? '99+' : $__i['badge'] }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            @endforeach
        </div>

        <div class="ui-side__foot">
            @if ($__stores->isNotEmpty() && Route::has('dashboard.context'))
                <form method="POST" action="{{ route('dashboard.context') }}" class="ui-ctxform" autocomplete="off">
                    @csrf
                    <input type="hidden" name="context_action" value="">
                    <select name="store_id" class="ui-ctxform__select" data-ui-autosubmit data-ui-action="store" aria-label="{{ ___('Store') }}" title="{{ ___('Store') }}">
                        @foreach ($__stores as $__o)
                            <option value="{{ $__o->id }}" @selected($__store && $__o->id === $__store->id)>{{ $__o->title }}</option>
                        @endforeach
                    </select>
                    @if ($__branches->isNotEmpty())
                        <select name="branch_id" class="ui-ctxform__select" data-ui-autosubmit data-ui-action="branch" aria-label="{{ ___('Branch') }}" title="{{ ___('Branch') }}" @disabled($__branchLocked)>
                            @unless ($__branchLocked)<option value="">{{ ___('All branches') }}</option>@endunless
                            @foreach ($__branches as $__o)
                                <option value="{{ $__o->id }}" @selected($__branch && $__o->id === $__branch->id)>{{ $__o->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    @unless ($__branchLocked)
                        {{-- Rides along with the next store/branch change; ticked on its own it
                             saves in the background (dashSaveDefault) instead of reloading. --}}
                        <label class="ui-side__check">
                            <input type="checkbox" name="save_default" value="1" data-lm-call="dashSaveDefault">
                            <span>{{ ___('Save as default') }}</span>
                        </label>
                    @endunless
                </form>
            @endif
            <a href="{{ Route::has('settings') && \App\Http\Middleware\EnsureStaffDashboardPermission::staffMayOpen('settings') ? $__settingsUrl : '#' }}" class="ui-side__account">
                <span class="ui-side__avatar">{{ mb_strtoupper(mb_substr($__accountName, 0, 1)) }}</span>
                <span class="ui-side__who">
                    <strong>{{ $__accountName }}</strong>
                    @if ($__plan?->name)<span>{{ $__plan->name }}</span>@endif
                </span>
            </a>
        </div>
    </div>
</aside>
<div class="ui-side-scrim" data-ui-drawer-close hidden></div>
<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
    // "Save as default" ticked on its own: store it in the background and confirm with a toast.
    window.dashSaveDefault = function (box) {
        if (!box || !box.checked || !box.form) { return; }
        var form = box.form;
        var data = new FormData();
        data.append('_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || (form.elements._token || {}).value || '');
        data.append('store_id', (form.elements.store_id || {}).value || '');
        if (form.elements.branch_id) { data.append('branch_id', form.elements.branch_id.value || ''); }
        data.append('save_default', '1');
        data.append('_ajax', '1');
        var say = function (tone, msg) {
            if (window.ownerUi && window.ownerUi.toast) { window.ownerUi.toast(msg, { tone: tone }); }
            else if (window.dashToast) { window.dashToast(tone, msg); }
        };
        fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } say('success', @js(___('Saved as your login default.'))); })
            .catch(function () { say('danger', @js(___('Could not save the default.'))); });
    };
</script>
