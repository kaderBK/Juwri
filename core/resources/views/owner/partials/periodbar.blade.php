{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-4: shared period bar (dashboard + analytics). Presets today/7d/30d/month
     + custom range + compare toggle. Emits ?period= (one model everywhere).
     Optional scope chip (pass showScope=false to hide). Keeps the
     dashboard-periodbar test hook. Owner UI kit look (assets/owner-ui/screens.css). --}}
@php
    $__period = $period ?? 'month';
    $__compare = $compare ?? true;
    $__showScope = $showScope ?? true;
    $__scopeAll = $scopeAll ?? false;
    // $stores is only shared by the dashboard; fall back to the context list.
    $__multi = isset($stores) ? (int) ($stores->count() ?? 0)
        : (function_exists('dashboard_stores') ? dashboard_stores()->count() : 0);
@endphp
{{-- Phones: the custom range folds behind a "Custom" chip (Alpine); wider screens always show it. --}}
<div class="ui-scope ui-periodbar dashboard-periodbar" x-data="{ custom: @js($__period === 'custom') }">
    @if($__showScope)
        {{-- A single-store owner already sees the store in the top bar: phones skip this line. --}}
        <div @class(['ui-periodbar__scope', 'ui-periodbar__scope--single' => !$__scopeAll && $__multi <= 1])>
            <span class="ui-periodbar__showing">{{ ___('Showing') }}</span>
            @if($__scopeAll)
                <strong>{{ ___('All stores') }}</strong>
                <a href="{{ request()->fullUrlWithQuery(['scope' => null]) }}" class="ui-periodbar__link">{{ ___('Back to current store') }}</a>
            @else
                <strong>{{ ($activeStore->title ?? null) ?: ___('All stores') }}@if($activeBranch ?? false) · {{ $activeBranch->name }}@endif</strong>
                @if($__multi > 1)
                    <a href="{{ request()->fullUrlWithQuery(['scope' => 'all']) }}" class="ui-periodbar__link">{{ ___('View all stores') }}</a>
                @endif
            @endif
        </div>
    @endif

    <div class="ui-periodbar__controls">
        <div class="ui-chips ui-periodbar__presets" role="group" aria-label="{{ ___('Period presets') }}">
            @foreach(['today' => ___('Today'), '7d' => ___('7 days'), '30d' => ___('30 days'), 'month' => ___('Month')] as $key => $label)
                <a href="{{ request()->fullUrlWithQuery(['period' => $key, 'from' => null, 'to' => null, 'range' => null]) }}"
                   class="ui-chip"
                   @if($__period === $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
            <button type="button" class="ui-chip ui-periodbar__custom-toggle" aria-controls="ui-periodbar-range"
                    :aria-expanded="custom ? 'true' : 'false'" @click="custom = !custom"
                    @if($__period === 'custom') aria-current="true" aria-expanded="true" @else aria-expanded="false" @endif>
                <x-ui.icon name="calendar" size="16" />{{ ___('Custom') }}
            </button>
        </div>

        <form method="GET" id="ui-periodbar-range" @class(['ui-periodbar__range', 'is-open' => $__period === 'custom']) :class="{ 'is-open': custom }" role="group" aria-label="{{ ___('Custom range') }}">
            <input type="hidden" name="period" value="custom">
            @if($__scopeAll)<input type="hidden" name="scope" value="all">@endif
            @unless($__compare)<input type="hidden" name="compare" value="0">@endunless
            <span class="ui-periodbar__dates" @if($__period === 'custom') data-active @endif>
                <input type="date" name="from" value="{{ $rangeFrom ?? '' }}" aria-label="{{ ___('From date') }}">
                <span class="ui-periodbar__sep" aria-hidden="true">–</span>
                <input type="date" name="to" value="{{ $rangeTo ?? '' }}" aria-label="{{ ___('To date') }}">
            </span>
            <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply') }}</button>
        </form>

        <a href="{{ request()->fullUrlWithQuery(['compare' => $__compare ? '0' : '1']) }}"
           class="ui-chip ui-periodbar__compare {{ $__compare ? 'is-on' : '' }}">
            <x-ui.icon :name="$__compare ? 'check' : 'refresh'" size="16" />
            {{ $__compare ? ___('Comparing to last week') : ___('Compare to last week') }}
        </a>
    </div>
</div>
