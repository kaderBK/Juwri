{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Assign by Segment'))
@section('page_sub', ___('Pick a slice of stores by plan, signup date, verification or current theme, preview the match, then assign a template to all of them at once. Every assignment is undoable from Recent migrations.'))

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.menu-templates.index')">{{ ___('Back to templates') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    {{-- Segment filters (GET so the preview is shareable/bookmarkable). --}}
    <x-ui.card>
        <form action="{{ route('admin.menu-templates.segments') }}" method="get" class="ui-stack">
            <div class="ui-form-grid">
                <x-ui.field type="select" name="plan" id="seg-plan" :label="___('Plan')" :value="(string) $request->input('plan')"
                            :options="['' => ___('Any plan')] + \Juwri\Core\Support\ViewHelpers::propOptions($plans, 'id', 'name', true)" />
                <x-ui.field type="date" name="date_from" id="seg-date-from" :label="___('Created from')" :value="$request->input('date_from')" />
                <x-ui.field type="date" name="date_to" id="seg-date-to" :label="___('Created to')" :value="$request->input('date_to')" />
                <x-ui.field type="select" name="verified" id="seg-verified" :label="___('Verification')" :value="(string) $request->input('verified')"
                            :options="['' => ___('Any'), '1' => ___('Verified'), '0' => ___('Unverified')]" />
                <x-ui.field type="select" name="current_template" id="seg-current" :label="___('Currently on theme')" :value="(string) $request->input('current_template')"
                            :options="['' => ___('Any theme')] + \Juwri\Core\Support\ViewHelpers::templateSlugOptions($all, true)" />
            </div>
            <div class="ui-cluster">
                <x-ui.button type="submit" icon="search">{{ ___('Preview segment') }}</x-ui.button>
                @if($hasFilters)
                    <x-ui.button variant="ghost" :href="route('admin.menu-templates.segments')">{{ ___('Clear') }}</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    @if($hasFilters)
        {{-- Assign bar: applies the target to EVERY matching store (not just the
             50 previewed below). Carries the same filters as hidden inputs. --}}
        <x-ui.card variant="accent">
            <div class="ui-cluster ui-cluster--between">
                <div>
                    <strong>{{ $matchCount }}</strong> {{ ___('stores match this segment.') }}
                    @if($matchCount > 50)
                        <small class="ui-text-2">{{ ___('(showing the newest 50 below; assign applies to all)') }}</small>
                    @endif
                </div>
                @if($matchCount > 0)
                    {{-- The confirm question names the count + target theme; the page
                         script keeps data-ui-confirm in step with the picked option. --}}
                    <form action="{{ route('admin.menu-templates.segments.assign') }}" method="post"
                          class="ui-cluster js-segment-assign" data-count="{{ $matchCount }}" data-ui-confirm="{{ ___('Assign segment') }}?" data-ui-danger>
                        @csrf
                        <input type="hidden" name="plan" value="{{ $request->input('plan') }}">
                        <input type="hidden" name="date_from" value="{{ $request->input('date_from') }}">
                        <input type="hidden" name="date_to" value="{{ $request->input('date_to') }}">
                        <input type="hidden" name="verified" value="{{ $request->input('verified') }}">
                        <input type="hidden" name="current_template" value="{{ $request->input('current_template') }}">
                        <label class="ui-small ui-text-2" for="seg-assign-to">{{ ___('Assign to') }}:</label>
                        <select name="to" id="seg-assign-to" class="ui-input ui-input--sm ui-input--auto">
                            @foreach($available as $slug => $tpl)
                                <option value="{{ $slug }}">{{ $tpl['name'] }}</option>
                            @endforeach
                        </select>
                        <x-ui.button type="submit" variant="danger" size="sm" icon="layout-grid">{{ ___('Assign segment') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card variant="flush">
            @if($matches && $matches->isNotEmpty())
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('ID') }}</th>
                                <th>{{ ___('Store') }}</th>
                                <th>{{ ___('Owner') }}</th>
                                <th>{{ ___('Created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($matches as $store)
                                <tr>
                                    <td data-label="{{ ___('ID') }}" class="ui-text-2">{{ $store->id }}</td>
                                    <td data-primary data-label="{{ ___('Store') }}">
                                        <strong>{{ $store->title }}</strong>
                                        <a href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener" class="ui-text-2"
                                           title="{{ ___('Open store') }}" aria-label="{{ ___('Open store') }}"><x-ui.icon name="external" size="16" /></a>
                                    </td>
                                    <td data-label="{{ ___('Owner') }}" class="ui-text-2">{{ $store->user->email ?? '—' }}</td>
                                    <td data-label="{{ ___('Created') }}" class="ui-text-2">{{ $store->created_at?->toDateString() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-ui.empty icon="filter" :title="___('No stores match this segment.')" />
            @endif
        </x-ui.card>
    @endif
</div>
@endsection

@push('scripts_at_bottom')
    {{-- Confirm the segment assign, naming the count + target theme. --}}
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            var L = {
                assign: @js(___('Assign')),
                storesTo: @js(___('stores to')),
                undo: @js(___('You can revert this from Recent migrations.'))
            };
            document.querySelectorAll('.js-segment-assign').forEach(function (form) {
                var sel = form.querySelector('select[name="to"]');
                function sync() {
                    var count = form.getAttribute('data-count') || '';
                    var theme = sel && sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : (sel ? sel.value : '');
                    form.setAttribute('data-ui-confirm', L.assign + ' ' + count + ' ' + L.storesTo + ' "' + theme + '"? ' + L.undo);
                }
                if (sel) { sel.addEventListener('change', sync); }
                sync();
            });
        })();
    </script>
@endpush
