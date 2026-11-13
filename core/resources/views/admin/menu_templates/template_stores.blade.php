{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Restaurants using template'))
@section('page_sub')
    @if($isMissing)
        {{ ___('This template no longer exists on disk — these restaurants currently render the fallback template. Reassign them to finish the cleanup.') }}
    @else
        {{ ___('Every restaurant currently rendering this template. Reassign all of them, or select rows to move only some.') }}
    @endif
@endsection

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="store" :href="route('admin.menu-templates.stores')">{{ ___('Store Themes') }}</x-ui.button>
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.menu-templates.index')">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    {{-- Hero: template identity + status --}}
    <div class="ui-cluster">
        <img src="{{ $template['image'] ?? asset('assets/images/placeholder.png') }}" class="js-hide-broken" width="84" height="56" style="object-fit: cover; object-position: top center; border-radius: 10px" alt="{{ $template['name'] }}">
        <div>
            <h2 class="ui-section-title">
                {{ $template['name'] }} <small class="ui-text-2">({{ $slug }})</small>
                @if($isMissing)
                    <x-ui.tag tone="danger">{{ ___('Deleted from disk') }}</x-ui.tag>
                @elseif(!$template['enabled'])
                    <x-ui.tag tone="warning">{{ ___('Disabled') }}</x-ui.tag>
                @else
                    <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                @endif
            </h2>
        </div>
    </div>

    {{-- Overview strip --}}
    <section class="ui-kpis" aria-label="{{ ___('Restaurants using template') }}">
        <x-ui.stat :value="$total" :label="___('On this template')" icon="store" />
        <x-ui.stat :value="$total > 0 ? $stores->total() : 0" :label="$q !== '' ? ___('Matching search') : ___('Showing')" icon="search" />
        <x-ui.stat :value="count($targets)" :label="___('Themes to move to')" icon="refresh" />
    </section>

    @if($total > 0)
        {{-- Reassign moves ALL restaurants on this template unless rows are
             checked in the table below (name="post_ids[]" form="reassignForm").
             Uses the true unfiltered $total — a search filter must not shrink
             what "reassign all" touches. The live count + confirm are computed
             in the pushed script (selected vs all). --}}
        <x-ui.card :title="___('Migrate restaurants')">
            <form id="reassignForm" action="{{ route('admin.menu-templates.reassign') }}" method="post" class="ui-stack"
                  data-total="{{ $total }}" data-from="{{ $slug }}" data-ui-confirm="{{ ___('Reassign') }}?" data-ui-danger>
                @csrf
                <p class="ui-small ui-text-2">{{ ___('Choose a target theme, then reassign the whole set — or tick rows in the table to move only those.') }}</p>
                <input type="hidden" name="from" value="{{ $slug }}">
                {{-- Round-trip the active search so a partial reassign lands
                     back on the same filtered view the admin was triaging. --}}
                <input type="hidden" name="q" value="{{ $q }}">
                <div class="ui-cluster ui-cluster--bottom">
                    <x-ui.field type="select" name="to" id="reassign-to" :label="___('Target theme')" required
                                :options="\Juwri\Core\Support\ViewHelpers::templateFolderOptions($targets)" />
                    <x-ui.button type="submit" variant="danger" icon="refresh">{{ ___('Reassign') }} <span id="reassignCount"></span></x-ui.button>
                    <span class="ui-small ui-text-2" id="reassignHint" data-all="{{ ___('Moves all :n restaurants') }}" data-some="{{ ___(':n selected') }}"></span>
                </div>
            </form>
        </x-ui.card>

        {{-- Filter this template's restaurants by name / slug / owner email.
             Independent of the reassign-all above (which always targets the
             full set). --}}
        <form action="{{ route('admin.menu-templates.restaurants', $slug) }}" method="get" class="ui-cluster" role="search">
            <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm ui-input--auto" placeholder="{{ ___('Search name, slug or owner email') }}" aria-label="{{ ___('Search') }}">
            <x-ui.button type="submit" size="sm" icon="search">{{ ___('Search') }}</x-ui.button>
            @if($q !== '')
                <x-ui.button variant="ghost" size="sm" icon="x" :href="route('admin.menu-templates.restaurants', $slug)">{{ ___('Clear') }}</x-ui.button>
                <x-ui.tag tone="accent">{{ $stores->total() }} {{ ___('match') }} “{{ $q }}”</x-ui.tag>
            @endif
        </form>

        <x-ui.card variant="flush">
            @if($stores->total() > 0)
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th class="ui-dt__narrow"><input type="checkbox" id="reassignSelectAll" aria-label="{{ ___('Select all') }}"></th>
                                <th class="ui-dt__narrow">{{ ___('ID') }}</th>
                                <th>{{ ___('Restaurant') }}</th>
                                <th>{{ ___('Owner') }}</th>
                                <th class="ui-end">{{ ___('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stores as $store)
                                <tr>
                                    <td data-label=""><input type="checkbox" name="post_ids[]" value="{{ $store->id }}" form="reassignForm" class="js-reassign-check" aria-label="{{ ___('Select') }} {{ $store->title }}"></td>
                                    <td data-label="{{ ___('ID') }}" class="ui-text-2">{{ $store->id }}</td>
                                    <td data-primary data-label="{{ ___('Restaurant') }}">
                                        <strong>{{ $store->title }}</strong>
                                        <small class="ui-cell-sub">{{ $store->slug }}</small>
                                    </td>
                                    <td data-label="{{ ___('Owner') }}" class="ui-text-2">{{ $store->user->email ?? '—' }}</td>
                                    <td class="ui-end" data-label="">
                                        <div class="ui-cell-actions">
                                            {{-- Preview this store under the currently-selected target theme
                                                 before committing the move (reuses the admin ?preview_template
                                                 override). The JS fills in the chosen target on click. --}}
                                            <x-ui.button variant="secondary" size="sm" icon="eye" class="js-row-preview"
                                                         data-preview-url="{{ route('publicView', $store->slug) }}"
                                                         title="{{ ___('Preview this store under the selected target theme') }}">{{ ___('Preview target') }}</x-ui.button>
                                            @if($store->user)
                                                <x-ui.button variant="secondary" size="sm" icon="user" :href="route('admin.users.edit', $store->user->id)">{{ ___('User') }}</x-ui.button>
                                            @endif
                                        </div>
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
                <x-ui.empty icon="search" :title="___('No restaurants match your search')" :body="___('Try a different name, slug or owner email.')" />
            @endif
        </x-ui.card>
    @else
        <x-ui.card>
            <x-ui.empty icon="check" :title="___('No restaurants use this template')" :body="___('Nothing to reassign here.')" />
        </x-ui.card>
    @endif
</div>
@endsection

@push('scripts_at_bottom')
    {{-- I7: header select-all toggle + a submit confirm that names how many
         restaurants actually move (checked rows, or the whole set when none
         are checked). A live count keeps the button label honest. @js() keeps
         translated labels quote-safe. The confirm itself is the kit's
         form[data-ui-confirm]; refresh() keeps its question current. --}}
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            document.querySelectorAll('img.js-hide-broken').forEach(function (img) {
                img.addEventListener('error', function () { img.hidden = true; });
            });

            var form = document.getElementById('reassignForm');
            if (!form) { return; }
            var selectAll = document.getElementById('reassignSelectAll');
            var countEl = document.getElementById('reassignCount');
            var hintEl = document.getElementById('reassignHint');
            var total = parseInt(form.dataset.total, 10) || 0;
            var toSelect = form.querySelector('select[name="to"]');
            var L = {
                move: @js(___('Move')),
                storesFrom: @js(___('restaurants from')),
                to: @js(___('to')),
                undo: @js(___('This cannot be undone.'))
            };

            function selectedCount() {
                return document.querySelectorAll('.js-reassign-check:checked').length;
            }
            function targetText() {
                return toSelect && toSelect.options[toSelect.selectedIndex] ? toSelect.options[toSelect.selectedIndex].text : (toSelect ? toSelect.value : '');
            }

            function refresh() {
                var sel = selectedCount();
                var moving = sel > 0 ? sel : total;
                if (countEl) { countEl.textContent = moving; }
                if (hintEl) {
                    var tpl = sel > 0 ? (hintEl.dataset.some || ':n selected') : (hintEl.dataset.all || 'Moves all :n restaurants');
                    hintEl.textContent = tpl.replace(':n', String(moving));
                }
                form.setAttribute('data-ui-confirm', L.move + ' ' + moving + ' ' + L.storesFrom + ' "' + (form.dataset.from || '') + '" ' + L.to + ' "' + targetText() + '"? ' + L.undo);
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    document.querySelectorAll('.js-reassign-check').forEach(function (c) { c.checked = selectAll.checked; });
                    refresh();
                });
            }
            document.querySelectorAll('.js-reassign-check').forEach(function (c) {
                c.addEventListener('change', refresh);
            });
            if (toSelect) { toSelect.addEventListener('change', refresh); }

            // Preview a store under the currently-selected target theme.
            document.querySelectorAll('.js-row-preview').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var base = btn.getAttribute('data-preview-url');
                    if (!base || !toSelect) { return; }
                    var sep = base.indexOf('?') === -1 ? '?' : '&';
                    window.open(base + sep + 'preview_template=' + encodeURIComponent(toSelect.value), '_blank', 'noopener');
                });
            });

            refresh();
        })();
    </script>
@endpush
