{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Store Themes'))
@section('page_sub', ___('Find any store and switch the template it renders. Changing the theme keeps all of the store’s data — only its look changes.'))

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.menu-templates.index')">{{ ___('Back to templates') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    <form action="{{ route('admin.menu-templates.stores') }}" method="get" class="ui-cluster" role="search">
        <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Name, slug or owner email') }}" aria-label="{{ ___('Search') }}">
        <x-ui.button type="submit" size="sm" icon="search">{{ ___('Search') }}</x-ui.button>
        @if($q !== '')
            <x-ui.button variant="ghost" size="sm" :href="route('admin.menu-templates.stores')">{{ ___('Clear') }}</x-ui.button>
        @endif
    </form>

    <x-ui.card variant="flush">
        @if($stores->total() > 0)
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('ID') }}</th>
                            <th>{{ ___('Store') }}</th>
                            <th>{{ ___('Owner') }}</th>
                            <th>{{ ___('Current theme') }}</th>
                            <th class="ui-end">{{ ___('Switch to') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stores as $store)
                            @php
                                $cur = $store->current_template;
                                $curName = $cur ? ($available[$cur]['name'] ?? $cur) : null;
                            @endphp
                            <tr>
                                <td data-label="{{ ___('ID') }}" class="ui-text-2">{{ $store->id }}</td>
                                <td data-primary data-label="{{ ___('Store') }}">
                                    <strong>{{ $store->title }}</strong>
                                    <a href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener" class="ui-text-2"
                                       title="{{ ___('Open store') }}" aria-label="{{ ___('Open store') }}"><x-ui.icon name="external" size="16" /></a>
                                </td>
                                <td data-label="{{ ___('Owner') }}" class="ui-text-2">{{ $store->user->email ?? '—' }}</td>
                                <td data-label="{{ ___('Current theme') }}">
                                    @if($cur)
                                        <x-ui.tag>{{ $curName }}</x-ui.tag>
                                        @unless(isset($available[$cur]))
                                            <small class="ui-text-danger">{{ ___('(unavailable)') }}</small>
                                        @endunless
                                    @else
                                        <span class="ui-text-2">—</span>
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Switch to') }}">
                                    {{-- The confirm question names the store + target theme; the page
                                         script keeps data-ui-confirm in step with the picked option. --}}
                                    <form action="{{ route('admin.menu-templates.stores.switch') }}" method="post" class="ui-cluster ui-cluster--end js-store-switch"
                                          data-ui-confirm="{{ ___('Switch') }}?">
                                        @csrf
                                        <input type="hidden" name="store_id" value="{{ $store->id }}">
                                        <input type="hidden" name="q" value="{{ $q }}">
                                        <select name="to" class="ui-input ui-input--sm ui-input--auto" data-store="{{ $store->title }}" aria-label="{{ ___('Switch to') }}">
                                            @foreach($available as $slug => $tpl)
                                                <option value="{{ $slug }}" @selected($cur === $slug)>{{ $tpl['name'] }}</option>
                                            @endforeach
                                        </select>
                                        <x-ui.button type="submit" size="sm">{{ ___('Switch') }}</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($stores->hasPages())
                <div class="ui-card__foot">{{ $stores->links() }}</div>
            @endif
        @else
            <x-ui.empty icon="store" :title="$q !== '' ? ___('No stores match your search.') : ___('No stores yet.')" />
        @endif
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
    {{-- Confirm a single-store theme switch, naming the store + target. @js()
         keeps translated labels quote-safe. --}}
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            var L = { switchTo: @js(___('Switch')), to: @js(___('to')) };
            document.querySelectorAll('.js-store-switch').forEach(function (form) {
                var sel = form.querySelector('select[name="to"]');
                if (!sel) { return; }
                function sync() {
                    var store = sel.getAttribute('data-store') || '';
                    var theme = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : sel.value;
                    form.setAttribute('data-ui-confirm', L.switchTo + ' "' + store + '" ' + L.to + ' ' + theme + '?');
                }
                sel.addEventListener('change', sync);
                sync();
            });
        })();
    </script>
@endpush
