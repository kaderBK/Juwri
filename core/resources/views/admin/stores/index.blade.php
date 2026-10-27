{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Stores'))
@section('content')
<div class="ui-stack">
    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('Stores') }}">
        @foreach ([
            'total'     => [___('Total stores'), 'store', null],
            'new30'     => [___('New (30 days)'), 'user-plus', 'success'],
            'orders30'  => [___('With orders (30 days)'), 'orders', 'warning'],
            'scanned30' => [___('Scanned (30 days)'), 'qr', 'danger'],
        ] as $key => [$__label, $__icon, $__tone])
            <x-ui.stat :value="format_number_count($stats[$key])" :label="$__label" :icon="$__icon" :tone="$__tone" />
        @endforeach
    </section>

    <form method="GET" class="ui-cluster" role="search">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Title or slug…') }}" aria-label="{{ ___('Title or slug…') }}">
        <input type="text" name="owner" value="{{ $filters['owner'] ?? '' }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Owner name or email…') }}" aria-label="{{ ___('Owner name or email…') }}">
        <select name="days" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Added date') }}">
            <option value="">{{ ___('All time') }}</option>
            @foreach ([7 => ___('7 days'), 30 => ___('30 days')] as $d => $label)
                <option value="{{ $d }}" @selected((int) ($filters['days'] ?? 0) === $d)>{{ $label }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card variant="flush" :title="___('All stores')" data-ui-bulk-scope>
        <div class="ui-stack">
            {{-- rev/external-redirect C2: migration-wave bulk tool. Check stores in
                 the table, then set (from a {slug}{query} template) or clear their
                 menu redirect in one action. --}}
            <div class="ui-card ui-card--inset" id="er-bulk-toolbar" data-filters='@json($filters ?? [])' style="margin-inline: 20px">
                <div class="ui-stack">
                    <div class="ui-cluster ui-cluster--bottom">
                        <div class="ui-field ui-grow">
                            <label class="ui-field__label" for="er-bulk-template">{{ ___('External redirect — migration wave') }}</label>
                            <div class="ui-field__control">
                                <input type="url" id="er-bulk-template" placeholder="https://legacy.example.com/{slug}{query}" aria-describedby="er-bulk-help">
                            </div>
                        </div>
                        <x-ui.button id="er-bulk-set" size="sm">{{ ___('Set on selected') }}</x-ui.button>
                        <x-ui.button id="er-bulk-clear" variant="secondary" size="sm">{{ ___('Clear on selected') }}</x-ui.button>
                    </div>
                    <x-ui.switch id="er-bulk-all-matching" :label="___('Apply to all :count stores matching the current filter (not just this page)', ['count' => $stores->total()])" />
                    <span class="ui-small ui-text-2" id="er-bulk-help">{{ ___('Select stores with the checkboxes, then apply. Use {slug} and {query} to forward the store name and table/QR parameters.') }}</span>
                </div>
            </div>

            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="ui-dt__narrow"><input type="checkbox" id="quick-checkbox-all" data-ui-check-all aria-label="{{ ___('Select all') }}"></th>
                            <th>#</th>
                            <th>{{ ___('Restaurant') }}</th>
                            <th>{{ ___('Owner') }}</th>
                            <th>{{ ___('Template') }}</th>
                            <th>{{ ___('Scans (30 days)') }}</th>
                            <th>{{ ___('Added date') }}</th>
                            <th class="ui-end">{{ ___('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stores as $store)
                            @php $__redirecting = ($redirects ?? collect())->has($store->id); @endphp
                            <tr>
                                <td data-label=""><input type="checkbox" id="check_{{ $store->id }}" value="{{ $store->id }}" class="quick-check" aria-label="{{ ___('Select') }} {{ $store->title }}"></td>
                                <td data-label="#" class="ui-text-2">{{ $store->id }}</td>
                                <td data-primary data-label="{{ ___('Restaurant') }}">
                                    <div class="ui-cell-person">
                                        <a href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener">
                                            <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}" width="36" height="36" />
                                        </a>
                                        <div>
                                            <a class="ui-strong" href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener">{{ $store->title }}</a>
                                            @if ($__redirecting)
                                                <x-ui.tag tone="warning" title="{{ ___('Public menu redirects externally') }}">↪ {{ ___('redirect') }}</x-ui.tag>
                                            @endif
                                            <small class="ui-cell-sub">{{ $store->slug }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="{{ ___('Owner') }}">
                                    @if ($store->user)
                                        <div class="ui-cell-person">
                                            <a href="{{ route('admin.users.edit', $store->user->id) }}">
                                                <img alt="{{ $store->user->username }}" src="{{ asset('storage/profile/'.$store->user->image) }}" width="36" height="36" />
                                            </a>
                                            <div>
                                                <a class="ui-strong" href="{{ route('admin.users.edit', $store->user->id) }}">{{ $store->user->name }}</a>
                                                <small class="ui-cell-sub">{{ '@'.$store->user->username }}</small>
                                            </div>
                                        </div>
                                    @else
                                        <span class="ui-text-2">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ ___('Template') }}">
                                    @if ($templates->get($store->id))
                                        <code>{{ $templates->get($store->id) }}</code>
                                    @else
                                        <span class="ui-text-2">—</span>
                                    @endif
                                </td>
                                <td data-label="{{ ___('Scans (30 days)') }}"><x-ui.tag :tone="$scanCounts->get($store->id) ? 'accent' : 'neutral'">{{ format_number_count($scanCounts->get($store->id, 0)) }}</x-ui.tag></td>
                                <td data-label="{{ ___('Added date') }}" class="ui-text-2" title="{{ $store->created_at }}">{{ date_formating($store->created_at) }}</td>
                                <td class="ui-end" data-label="">
                                    <div class="ui-cell-actions">
                                        <a href="{{ route('publicView', $store->slug) }}" title="{{ ___('View') }}" aria-label="{{ ___('View') }}"
                                           class="ui-icon-btn ui-icon-btn--ghost" target="_blank" rel="noopener"><x-ui.icon name="eye" size="18" /></a>
                                        @php $__codeTitle = $store->custom_code_enabled ? ___('Custom code: ON (click to revoke)') : ___('Custom code: OFF (click to grant)'); @endphp
                                        <button type="button" title="{{ $__codeTitle }}" aria-label="{{ $__codeTitle }}"
                                                aria-pressed="{{ $store->custom_code_enabled ? 'true' : 'false' }}"
                                                @class(['ui-icon-btn', 'ui-icon-btn--ghost', 'toggle-custom-code', 'ui-text-warning' => $store->custom_code_enabled])
                                                data-id="{{ $store->id }}"><x-ui.icon name="settings" size="18" /></button>
                                        <a href="{{ route('admin.stores.externalRedirect.edit', $store->id) }}"
                                           title="{{ ___('External redirect') }}" aria-label="{{ ___('External redirect') }}"
                                           @class(['ui-icon-btn', 'ui-icon-btn--ghost', 'ui-text-warning' => $__redirecting])><x-ui.icon name="external" size="18" /></a>
                                        <button type="button" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger"
                                                data-ui-bulk="{{ route('admin.stores.delete') }}" data-ui-bulk-id="{{ $store->id }}"><x-ui.icon name="trash" size="18" /></button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="ui-center ui-text-2">{{ ___('No restaurants match these filters.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ui-card__foot ui-cluster--between">
            <x-ui.button type="button" variant="secondary" size="sm" icon="trash" :data-ui-bulk="route('admin.stores.delete')">{{ ___('Delete selected') }}</x-ui.button>
            @if ($stores->hasPages())
                <div>{{ $stores->links() }}</div>
            @endif
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        (function () {
            function csrf() {
                var m = document.querySelector('meta[name="csrf-token"]');
                return m ? m.getAttribute('content') : '';
            }
            function say(message, tone) {
                if (window.adminUi) { window.adminUi.toast(message, tone); } else { window.alert(message); }
            }
            function ask(message) {
                return window.adminUi ? window.adminUi.confirm(message) : Promise.resolve(window.confirm(message));
            }

            // P0-3: superadmin per-tenant custom-code grant/revoke.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.toggle-custom-code');
                if (!btn) { return; }
                fetch(@js(route('admin.stores.toggleCustomCode')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json'
                    },
                    body: JSON.stringify({ id: btn.getAttribute('data-id') })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success) { return; }
                    btn.classList.toggle('ui-text-warning', !!d.enabled);
                    btn.setAttribute('aria-pressed', d.enabled ? 'true' : 'false');
                    if (d.message) { say(d.message, 'success'); }
                })
                .catch(function () { say(@js(___('Something went wrong. Please try again.')), 'danger'); });
            });

            // rev/external-redirect C2/G2/G7: migration-wave bulk set/clear, with a
            // whole-filter scope and a dry-run confirmation before it writes.
            var toolbar = document.getElementById('er-bulk-toolbar');
            var filters = {};
            try { filters = JSON.parse((toolbar && toolbar.getAttribute('data-filters')) || '{}') || {}; } catch (e) {}

            function checkedIds() {
                return Array.prototype.slice
                    .call(document.querySelectorAll('.quick-check:checked'))
                    .map(function (c) { return parseInt(c.value, 10); });
            }
            function runBulk(action) {
                var allMatching = document.getElementById('er-bulk-all-matching');
                var useFilter = !!(allMatching && allMatching.checked);
                var ids = useFilter ? [] : checkedIds();
                var template = document.getElementById('er-bulk-template').value.trim();

                if (!useFilter && !ids.length) { say(@js(___('Select at least one store first.')), 'danger'); return; }
                if (action === 'set' && !template) { say(@js(___('Enter a template URL.')), 'danger'); return; }

                // G7: dry-run confirm — say exactly what will change before writing.
                var target = useFilter
                    ? @js(___('all stores matching the current filter'))
                    : (ids.length + ' ' + @js(___('selected store(s)')));
                var summary = action === 'set'
                    ? @js(___('Set the redirect on')) + ' ' + target + ' → ' + template
                    : @js(___('Clear the redirect on')) + ' ' + target;
                ask(summary + ' — ' + @js(___('Continue?'))).then(function (yes) {
                    if (!yes) { return; }
                    fetch(@js(route('admin.stores.externalRedirect.bulk')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf(),
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        },
                        body: JSON.stringify({
                            scope: useFilter ? 'filter' : 'selected',
                            ids: ids, action: action, template: template, filters: filters
                        })
                    })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                    .then(function (res) {
                        var ok = res.ok && res.d && res.d.success;
                        if (res.d && res.d.message) { say(res.d.message, ok ? 'success' : 'danger'); }
                        if (ok) { window.setTimeout(function () { window.location.reload(); }, 600); }
                    })
                    .catch(function () { say(@js(___('Something went wrong. Please try again.')), 'danger'); });
                });
            }
            var setBtn = document.getElementById('er-bulk-set');
            var clearBtn = document.getElementById('er-bulk-clear');
            if (setBtn) { setBtn.addEventListener('click', function () { runBulk('set'); }); }
            if (clearBtn) { clearBtn.addEventListener('click', function () { runBulk('clear'); }); }
        })();
    </script>
@endpush
