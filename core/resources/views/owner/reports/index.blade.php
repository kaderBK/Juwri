{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Reports'))
@section('page_title', ___('Reports'))
@section('page_sub', ___('Every report in one place — each opens store-scoped with the shared period bar.'))

@section('content')
@php $pins = juwri_nav_pin_keys(); @endphp

{{-- Reports hub on the owner UI kit: one section per category, a tile per report.
     The tile link covers the card; the pin toggle sits above it. --}}
<div class="ui-scope ui-reports">
    @foreach($categories as $cat)
        <section class="ui-reports__group" aria-labelledby="reports-{{ $loop->index }}">
            <h2 class="ui-overline" id="reports-{{ $loop->index }}">{{ $cat['title'] }}</h2>
            <div class="ui-reports__grid">
                @foreach($cat['items'] as $item)
                    @php $locked = $item['locked'] ?? false; $pinned = in_array($item['key'], $pins, true); @endphp
                    <article class="ui-report" @if($locked) data-locked @endif>
                        <span class="ui-report__icon"><x-ui.shell.nav-icon :glyph="$item['icon']" /></span>
                        <span class="ui-report__body">
                            <a class="ui-report__link" href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                            @if($locked)
                                <x-ui.tag tone="warning">{{ ___('Upgrade') }}</x-ui.tag>
                            @endif
                        </span>
                        @if(! $locked && Route::has('dashboard.pins'))
                            <button type="button"
                                    class="ui-icon-btn ui-icon-btn--ghost ui-report__pin report-pin"
                                    data-key="{{ $item['key'] }}"
                                    aria-pressed="{{ $pinned ? 'true' : 'false' }}"
                                    aria-label="{{ ___('Pin to sidebar') }}"
                                    title="{{ ___('Pin to sidebar') }}"
                                    data-ui-call="reportsTogglePin">
                                <x-ui.icon name="pin" />
                            </button>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

@if(Route::has('dashboard.pins'))
    @push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
        window.reportsTogglePin = function (btn) {
            var key = btn.dataset.key;
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch(@js(route('dashboard.pins')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ key: key }),
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
                if (!d) return;
                var on = d.pinned;
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                if (window.dashToast) window.dashToast('success', on ? @js(___('Pinned to sidebar.')) : @js(___('Unpinned.')));
            }).catch(function () {});
        };
    </script>
    @endpush
@endif
@endsection
