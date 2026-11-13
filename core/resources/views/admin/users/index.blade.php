{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Users'))
@section('header_buttons')
    <div class="ui-pop-host" data-ui-pop>
        <x-ui.button variant="secondary" size="sm" icon="download" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true">{{ ___('Export / Import') }}</x-ui.button>
        <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
            <button type="button" class="ui-pop__item" data-users-export="{{ route('admin.users.export') }}"><span class="ui-pop__label">{{ ___('Regular Export') }}</span></button>
            <button type="button" class="ui-pop__item" data-users-export="{{ route('admin.users.whatsappExport') }}"><span class="ui-pop__label">{{ ___('WhatsApp Export') }}</span></button>
            <hr class="ui-pop__sep">
            <button type="button" class="ui-pop__item" data-users-package="{{ route('admin.users.fullExportPackage') }}" title="{{ ___('Export full user backups, restaurant data, and media') }}"><span class="ui-pop__label">{{ ___('Full Backup Export') }}</span></button>
            <button type="button" class="ui-pop__item" data-users-package="{{ route('admin.users.targetExportPackage') }}" title="{{ ___('Export in the older target-app format') }}"><span class="ui-pop__label">{{ ___('Target App Export') }}</span></button>
            <button type="button" class="ui-pop__item" data-users-package="{{ route('admin.users.hungerinoExportPackage') }}" title="{{ ___('Export in the new Hungerino contract format') }}"><span class="ui-pop__label">{{ ___('Hungerino Export') }}</span></button>
            <hr class="ui-pop__sep">
            <button type="button" class="ui-pop__item" data-ui-open="bulk-import-users-panel"><span class="ui-pop__label">{{ ___('Bulk Import') }}</span></button>
        </div>
    </div>
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.users.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
@php
    $__sortLink = function (string $key, string $label) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
        $arrow = $sort === $key ? ($dir === 'desc' ? ' ↓' : ' ↑') : '';

        return '<a class="ui-dt__sort" href="'.e(request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next])).'"'
            .($sort === $key ? ' aria-sort="'.($dir === 'desc' ? 'descending' : 'ascending').'"' : '').'>'.e($label).$arrow.'</a>';
    };
@endphp
<div class="ui-stack">
    {{-- Async export progress --}}
    <x-ui.card id="full-export-progress" hidden role="status">
        <div class="ui-cluster ui-cluster--between">
            <strong id="full-export-progress-title">{{ ___('Preparing export') }}</strong>
            <span id="full-export-progress-meta" class="ui-small ui-text-2"></span>
        </div>
        <div class="ui-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="full-export-progress-meter">
            <div class="ui-meter__bar" id="full-export-progress-bar" style="inline-size: 0%"></div>
        </div>
    </x-ui.card>

    <section class="ui-kpis" aria-label="{{ ___('Users') }}">
        @foreach ([
            'total' => [___('Total users'), 'users', null],
            'paid' => [___('Paid subscribers'), 'wallet', 'success'],
            'free_trial' => [___('Free & trial'), 'clock', 'warning'],
            'new_30d' => [___('New last 30 days'), 'user-plus', null],
        ] as $__key => [$__label, $__icon, $__tone])
            <div class="ui-stat" @if ($__tone) data-tone="{{ $__tone }}" @endif>
                <span class="ui-stat__icon"><x-ui.icon :name="$__icon" /></span>
                <span class="ui-stat__value" data-stat="{{ $__key }}">{{ format_number_count($userStats[$__key]) }}</span>
                <span class="ui-stat__label">{{ $__label }}</span>
            </div>
        @endforeach
    </section>

    <form method="GET" class="ui-cluster" id="users-filter">
        <input type="search" name="search" id="quick-users-search" value="{{ request()->query('search') }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Name, username, email…') }}" aria-label="{{ ___('Search') }}">
        <select name="plan" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Subscription plan') }}">
            <option value="">{{ ___('All plans') }}</option>
            <option value="free" @selected(request('plan') === 'free')>{{ ___('Free') }}</option>
            <option value="trial" @selected(request('plan') === 'trial')>{{ ___('Trial') }}</option>
            @foreach ($plans as $plan)
                <option value="{{ $plan->id }}" @selected(request('plan') == $plan->id)>{{ $plan->name }}</option>
            @endforeach
        </select>
        <select name="plan_status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Plan Status') }}">
            <option value="">{{ ___('All plan statuses') }}</option>
            @foreach (['active' => ___('Active'), 'expired' => ___('Expired'), 'lifetime' => ___('Lifetime'), 'trial_active' => ___('Trial (active)'), 'trial_expired' => ___('Trial (expired)')] as $__v => $__l)
                <option value="{{ $__v }}" @selected(request('plan_status') === $__v)>{{ $__l }}</option>
            @endforeach
        </select>
        <select name="email_status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Email status') }}">
            <option value="">{{ ___('All email statuses') }}</option>
            <option value="verified" @selected(request('email_status') === 'verified')>{{ ___('Verified') }}</option>
            <option value="unverified" @selected(request('email_status') === 'unverified')>{{ ___('Unverified') }}</option>
        </select>
        <select name="status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Status') }}">
            <option value="">{{ ___('All') }}</option>
            <option value="1" @selected(request('status') === '1')>{{ ___('Active') }}</option>
            <option value="0" @selected(request('status') === '0')>{{ ___('Banned') }}</option>
        </select>
        <select name="restaurants" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Stores') }}">
            <option value="">{{ ___('All') }}</option>
            <option value="1" @selected(request('stores') === '1')>{{ ___('With') }}</option>
            <option value="0" @selected(request('stores') === '0')>{{ ___('Without') }}</option>
        </select>
        <select name="per_page" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Per page') }}">
            @foreach ([25, 50, 100] as $__n)
                <option value="{{ $__n }}" @selected($users->perPage() === $__n)>{{ $__n }}</option>
            @endforeach
        </select>
        @foreach (['sort', 'dir'] as $__keep)
            @if (is_string(request($__keep)))<input type="hidden" name="{{ $__keep }}" value="{{ request($__keep) }}">@endif
        @endforeach
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
        <x-ui.button variant="ghost" size="sm" :href="route('admin.users.index')">{{ ___('Reset') }}</x-ui.button>
    </form>

    <x-ui.card variant="flush" data-ui-bulk-scope>
        <x-slot:actions><span class="ui-small ui-text-2">{{ ___('All users') }} ({{ format_number_count($users->total()) }})</span></x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-dt__narrow"><input type="checkbox" data-ui-check-all aria-label="{{ ___('Select all') }}"></th>
                        <th>{!! $__sortLink('name', ___('User')) !!}</th>
                        <th>{!! $__sortLink('email', ___('Email')) !!}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Subscription plan') }}</th>
                        <th>{{ ___('Plan Start') }}</th>
                        <th>{!! $__sortLink('plan_end', ___('Plan End')) !!}</th>
                        <th>{!! $__sortLink('lastactive', ___('Last Login')) !!}</th>
                        <th>{!! $__sortLink('stores_count', ___('Stores')) !!}</th>
                        <th>{{ ___('Store Phone') }}</th>
                        <th>{{ ___('WhatsApp Number') }}</th>
                        <th>{!! $__sortLink('transactions_count', ___('Transactions')) !!}</th>
                        <th>{{ ___('Country') }}</th>
                        <th>{{ ___('Email status') }}</th>
                        <th>{!! $__sortLink('created_at', ___('Joined')) !!}</th>
                        <th>{!! $__sortLink('id', '#') !!}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $row)
                        @php
                            $__trialDays = (int) ($row->trial_days ?? 7);
                            $__window = null; // [text, tone]
                            if ($row->plan_end && $row->plan_end > 0) {
                                if ($row->plan_interval === 'LIFETIME' || $row->plan_end > 4000000000) {
                                    $__window = [___('Lifetime'), 'accent'];
                                } elseif ($row->plan_end < time()) {
                                    $__window = [___('Expired').' · '.date_formating($row->plan_end), 'danger'];
                                } else {
                                    $__window = [___('Until').' '.date_formating($row->plan_end), 'success'];
                                }
                            } elseif (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at) {
                                $__trialEnd = strtotime($row->created_at) + ($__trialDays * 86400);
                                $__window = $__trialEnd < time()
                                    ? [___('Expired').' · '.date_formating($__trialEnd), 'danger']
                                    : [___('Until').' '.date_formating($__trialEnd), 'success'];
                            }
                            $__wa = $row->branch_whatsapp ? whatsapp_normalize_number($row->branch_whatsapp) : null;
                        @endphp
                        <tr>
                            <td data-label=""><input type="checkbox" class="quick-check" value="{{ $row->id }}" aria-label="{{ ___('Select') }}"></td>
                            <td data-primary data-label="{{ ___('User') }}">
                                <span class="ui-cell-person">
                                    <img src="{{ asset('storage/profile/'.($row->image ?: 'default.png')) }}" alt="" width="34" height="34" loading="lazy">
                                    <span>
                                        <a href="{{ route('admin.users.edit', $row->id) }}"><strong>{{ $row->name ?: '—' }}</strong></a>
                                        <small class="ui-cell-sub">{{ '@'.$row->username }}</small>
                                    </span>
                                </span>
                            </td>
                            <td data-label="{{ ___('Email') }}"><span class="ui-break">{{ $row->email }}</span></td>
                            <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$row->status ? 'success' : 'danger'">{{ $row->status ? ___('Active') : ___('Banned') }}</x-ui.tag></td>
                            <td data-label="{{ ___('Subscription plan') }}"><strong>{{ in_array($row->plan_label, ['Free', 'Trial'], true) ? ___($row->plan_label) : $row->plan_label }}</strong></td>
                            <td data-label="{{ ___('Plan Start') }}" class="ui-text-2">
                                {{ $row->plan_start && $row->plan_start > 0 ? date_formating($row->plan_start) : (($row->plan_key ?? $row->group_id) === 'trial' && $row->created_at ? date_formating(strtotime($row->created_at)) : '—') }}
                            </td>
                            <td data-label="{{ ___('Plan End') }}">@if ($__window)<x-ui.tag :tone="$__window[1]">{{ $__window[0] }}</x-ui.tag>@else — @endif</td>
                            <td data-label="{{ ___('Last Login') }}">{{ $row->lastactive ? \Carbon\Carbon::parse($row->lastactive)->diffForHumans() : '—' }}</td>
                            <td data-label="{{ ___('Stores') }}"><strong>{{ $row->stores_count }}</strong></td>
                            <td data-label="{{ ___('Store Phone') }}" dir="ltr">{{ $row->store_phone ?: '—' }}</td>
                            <td data-label="{{ ___('WhatsApp Number') }}">
                                @if ($__wa)<a href="https://wa.me/{{ $__wa }}" target="_blank" rel="noopener" dir="ltr">{{ $row->branch_whatsapp }}</a>@else — @endif
                            </td>
                            <td data-label="{{ ___('Transactions') }}"><a href="{{ route('admin.transactions.index', ['search' => $row->email]) }}">{{ $row->transactions_count }}</a></td>
                            <td data-label="{{ ___('Country') }}">{{ $row->country ?: '—' }}</td>
                            <td data-label="{{ ___('Email status') }}"><x-ui.tag :tone="$row->email_verified_at ? 'success' : 'warning'">{{ $row->email_verified_at ? ___('Verified') : ___('Unverified') }}</x-ui.tag></td>
                            <td data-label="{{ ___('Joined') }}" class="ui-text-2" title="{{ $row->created_at }}">{{ $row->created_at ? date_formating(strtotime($row->created_at)) : '—' }}</td>
                            <td data-label="#" class="ui-text-2">{{ $row->id }}</td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    <a class="ui-icon-btn ui-icon-btn--ghost" href="{{ route('admin.users.edit', $row->id) }}" title="{{ ___('Edit') }}" aria-label="{{ ___('Edit') }}"><x-ui.icon name="pencil" size="18" /></a>
                                    <form action="{{ route('admin.users.login', $row->id) }}" method="post" data-impersonate="{{ ___('Why are you logging in as this user? (kept in the audit log)') }}">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="ui-icon-btn ui-icon-btn--ghost" title="{{ ___('Login as user') }}" aria-label="{{ ___('Login as user') }}"><x-ui.icon name="user-check" size="18" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="17" class="ui-center ui-text-2">{{ ___('No users match these filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="ui-card__foot ui-cluster ui-cluster--between">
            <div class="ui-cluster">
                <x-ui.button type="button" variant="secondary" size="sm" icon="trash" id="users-delete-selected" :data-ui-bulk="route('admin.users.delete')">{{ ___('Delete selected') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" size="sm" icon="checks" id="users-select-zero" :data-action="route('admin.users.selectionIds')">{{ ___('Select all users without restaurants') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" size="sm" icon="trash" id="users-delete-zero" class="ui-text-danger" :data-action="route('admin.users.deleteFiltered')">{{ ___('Delete all users without restaurants') }}</x-ui.button>
            </div>
            @if ($users->hasPages())<div>{{ $users->links() }}</div>@endif
        </div>
    </x-ui.card>
</div>

{{-- Bulk import (restores backups of users that already exist) --}}
<x-ui.sheet id="bulk-import-users-panel" :title="___('Bulk Import')">
    <form id="bulk-import-users-form" action="{{ route('admin.users.fullImportPackage') }}" method="POST" enctype="multipart/form-data" class="ui-stack">
        @csrf
        <x-ui.banner tone="warning">{{ ___('This will restore every user backup in the uploaded package for users that already exist in the system.') }}</x-ui.banner>
        <x-ui.field type="file" name="backup_file" :label="___('Backup Package')" accept=".zip" required
                    :help="___('Maximum').': '.\Juwri\Core\Support\BackupZipValidator::maxEntries().' '.___('files').', '.(int) round(\Juwri\Core\Support\BackupZipValidator::maxUncompressedBytes() / 1048576).' MB '.___('uncompressed').'.'" />
        <x-ui.check name="dry_run" value="1" :label="___('Validate only and show a summary without importing')" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" form="bulk-import-users-form" icon="upload">{{ ___('Import') }}</x-ui.button>
    </x-slot:footer>
</x-ui.sheet>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    var L = {
        selected: @json(___('users selected')), unableSelect: @json(___('Unable to select users')),
        starting: @json(___('Starting export...')), preparing: @json(___('Preparing export')), ready: @json(___('Export ready')),
        failed: @json(___('Something went wrong. Please try again.')), confirm: @json(___('Are you sure?')), deleted: @json(___('Deleted Successfully'))
    };
    var ui = function () { return window.adminUi; };
    function toast(msg, tone) { if (ui()) { ui().toast(msg, tone); } }

    /* The export / bulk endpoints read DataTables-shaped params: build them from the filter form. */
    function filterParams(extra) {
        var p = new URLSearchParams();
        p.set('draw', '1'); p.set('start', '0'); p.set('length', '-1');
        p.set('search[value]', (document.getElementById('quick-users-search') || {}).value || '');
        p.set('order[0][column]', '8'); p.set('order[0][dir]', 'desc');
        ['plan', 'plan_status', 'email_status', 'status', 'restaurants'].forEach(function (n) {
            var el = document.querySelector('#users-filter [name="' + n + '"]');
            if (el) { p.set(n, el.value); }
        });
        Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
        return p;
    }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function getJson(url) { return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }

    var box = document.getElementById('full-export-progress');
    var title = document.getElementById('full-export-progress-title');
    var meta = document.getElementById('full-export-progress-meta');
    var bar = document.getElementById('full-export-progress-bar');
    var meter = document.getElementById('full-export-progress-meter');
    function progress(pct, text, sub) {
        bar.style.inlineSize = pct + '%'; meter.setAttribute('aria-valuenow', String(pct));
        if (text !== undefined) { title.textContent = text; }
        meta.textContent = sub || '';
    }

    document.addEventListener('click', function (e) {
        var t = e.target instanceof Element ? e.target : null;
        if (!t) { return; }
        var plain = t.closest('[data-users-export]');
        if (plain) { e.preventDefault(); window.location = plain.getAttribute('data-users-export') + '?' + filterParams().toString(); return; }

        var pkg = t.closest('[data-users-package]');
        if (pkg) {
            e.preventDefault();
            pkg.disabled = true;
            box.hidden = false; progress(0, L.starting);
            getJson(pkg.getAttribute('data-users-package') + '?' + filterParams().toString()).then(function (res) {
                if (!res || !res.success) { box.hidden = true; toast((res && res.message) || L.failed, 'danger'); return; }
                toast(res.message, 'success');
                var timer = setInterval(function () {
                    getJson(res.status_url).then(function (s) {
                        if (s.current !== undefined && s.total) {
                            progress(Math.max(0, Math.min(100, Math.round(s.current / s.total * 100))), s.message || L.preparing, (s.current || 0) + ' / ' + s.total);
                        } else if (s.message) { title.textContent = s.message; }
                        if (s.state === 'ready' && s.download_url) {
                            clearInterval(timer); progress(100, L.ready); window.location = s.download_url;
                        } else if (s.state === 'failed') {
                            clearInterval(timer); box.hidden = true; toast(s.message || L.failed, 'danger');
                        }
                    }).catch(function () { clearInterval(timer); box.hidden = true; toast(L.failed, 'danger'); });
                }, 3000);
            }).catch(function () { box.hidden = true; toast(L.failed, 'danger'); }).then(function () { pkg.disabled = false; });
            return;
        }

        /* Users without restaurants: select all (across pages) for "Delete selected", or delete them all. */
        var sel = t.closest('#users-select-zero');
        if (sel) {
            sel.disabled = true;
            getJson(sel.getAttribute('data-action') + '?' + filterParams({ restaurants: '0' }).toString()).then(function (res) {
                if (!res || !res.success || !res.ids) { toast(L.unableSelect, 'danger'); return; }
                document.getElementById('users-delete-selected').setAttribute('data-ui-bulk-ids', res.ids.join(','));
                toast(res.ids.length + ' ' + L.selected, 'success');
            }).catch(function () { toast(L.unableSelect, 'danger'); }).then(function () { sel.disabled = false; });
            return;
        }
        var delZero = t.closest('#users-delete-zero');
        if (delZero) {
            var run = function () {
                delZero.disabled = true;
                fetch(delZero.getAttribute('data-action'), {
                    method: 'POST', credentials: 'same-origin', body: filterParams({ restaurants: '0' }),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }
                }).then(function (r) { return r.json(); }).then(function (res) {
                    toast(res.message || L.failed, res.success ? 'success' : 'danger');
                    if (res.success && res.queued && res.status_url) { /* P6-119e: queued job; poll until it ends */
                        var poll = function () {
                            getJson(res.status_url).then(function (st) {
                                if (st && (st.state === 'done' || st.state === 'failed')) {
                                    toast(st.state === 'done' ? L.deleted : (st.message || L.failed), st.state === 'done' ? 'success' : 'danger');
                                    window.location.reload();
                                } else { setTimeout(poll, 2000); }
                            }).catch(function () { setTimeout(poll, 4000); });
                        };
                        poll();
                        return;
                    }
                    if (res.success) { window.location.reload(); }
                }).catch(function () { toast(L.failed, 'danger'); }).then(function () { delZero.disabled = false; });
            };
            if (ui()) { ui().confirm('', { title: L.confirm, danger: true }).then(function (yes) { if (yes) { run(); } }); } else if (window.confirm(L.confirm)) { run(); }
        }
    });
    /* A new ticked selection replaces the "all without restaurants" list. */
    document.addEventListener('change', function (e) {
        if (e.target instanceof Element && e.target.matches('.quick-check, [data-ui-check-all]')) {
            var b = document.getElementById('users-delete-selected');
            if (b) { b.removeAttribute('data-ui-bulk-ids'); }
        }
    });
})();
</script>
@endpush
@endsection
