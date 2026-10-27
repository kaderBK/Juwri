{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Backup & export'))
@section('page_title', ___('Backup & export'))
@section('page_sub', ___('Download a full copy of this store\'s data as a ZIP — take it anywhere, any time.'))

@php
    $dsLabels = [
        'menu'           => ___('Menu (categories, items, extras)'),
        'menu_structure' => ___('Menu structure (combos, variants, banners)'),
        'branches'       => ___('Branches'),
        'coupons'        => ___('Coupons'),
        'tables'         => ___('Tables'),
        'reviews'        => ___('Reviews'),
        'loyalty'        => ___('Loyalty programs'),
        'settings'       => ___('Settings'),
        'inventory'      => ___('Inventory & purchasing'),
        'suppliers'      => ___('Suppliers'),
        'financials'     => ___('Financials (expenses, ledgers)'),
        'loyalty_ledger' => ___('Loyalty ledger'),
        'staff'          => ___('Staff & payroll'),
        'crm'            => ___('Campaigns & audiences'),
        'crm_responses'  => ___('Feedback & consent records'),
        'customers'      => ___('Customers'),
        'orders'         => ___('Orders'),
        'order_money'    => ___('Order adjustments'),
        'gift_cards'     => ___('Gift cards'),
        'check_payments' => ___('Check payments'),
        'reservations'   => ___('Reservations'),
        'floor_service'  => ___('Floor & service'),
        'floor_sessions' => ___('Table sessions'),
        'drivers'        => ___('Drivers'),
        'hotel'          => ___('Hotel & catering'),
        'social'         => ___('Social (follows, favourites)'),
        'media_rows'     => ___('Media records'),
        'voice_notes'    => ___('Order voice notes'),
        'media'          => ___('Media files (photos, logos)'),
    ];
    // Group the toggles by their UI group label, preserving DATASETS order.
    $grouped = [];
    foreach ($datasets as $key) {
        if ($key === 'media') continue; // rendered as its own toggle below
        $grouped[$groups[$key] ?? ___('Other')][] = $key;
    }
    $defaultSel = array_values(array_diff($datasets, $piiSets));
@endphp

@section('content')
<div class="ui-scope ui-backup">
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

<div class="ui-backup__stack" x-data="storeBackup(@js([
        'statusBase' => url('backup'),
        'pending'    => $backups->whereIn('status', ['pending','processing'])->pluck('token')->values(),
        'imports'    => $imports->whereIn('status', ['processing'])->pluck('token')->values(),
        'pii'        => array_values($piiSets),
    ]))">

    {{-- ============ Tabs ============ --}}
    <div class="ui-chips ui-backup__tabs" role="tablist">
        <a class="ui-chip" :aria-current="tab==='export' ? 'true' : 'false'" href="#" @click.prevent="tab='export'">
            <x-ui.icon name="download" size="18" />{{ ___('Export') }}
        </a>
        <a class="ui-chip" :aria-current="tab==='import' ? 'true' : 'false'" href="#" @click.prevent="tab='import'">
            <x-ui.icon name="upload" size="18" />{{ ___('Import / restore') }}
        </a>
    </div>

    {{-- ================================================================ --}}
    {{-- EXPORT --}}
    {{-- ================================================================ --}}
    <div class="ui-backup__stack" x-show="tab==='export'" x-cloak>

    {{-- ============ P4-07: all the account's data (every store) ============ --}}
    @if (($accountExport['allowed'] ?? false))
        <x-ui.card :title="___('Download all your data')" data-account-export>
            <p class="ui-muted">{{ ___('One archive with every store of your account and all its data and files (passwords, keys and payment credentials are left out). It is kept for 7 days.') }}</p>
            @php $__ae = $accountExport['latest'] ?? null; @endphp
            @if ($__ae && $__ae['status'] === 'ready')
                <p><a class="ui-btn ui-btn--sm" href="{{ route('backup.account.download', $__ae['token']) }}" data-account-export-download>{{ ___('Download your data') }}</a>
                    <span class="ui-muted">{{ date('Y-m-d H:i', $__ae['created_at']) }} · {{ number_format(($__ae['size'] ?? 0) / 1024, 0) }} KB</span></p>
            @elseif ($__ae && $__ae['status'] === 'processing')
                <p class="ui-muted" data-account-export-processing>{{ ___('Your account export is being prepared. It appears below when it is ready.') }}</p>
            @elseif ($__ae && $__ae['status'] === 'failed')
                <p class="ui-muted">{{ ___('The export could not be generated. Please try again.') }}</p>
            @endif
            <form method="POST" action="{{ route('backup.account.export') }}" data-account-export-form>
                @csrf
                <button type="submit" class="ui-btn ui-btn--sm">{{ ___('Prepare a new export') }}</button>
            </form>
        </x-ui.card>
    @endif

    {{-- ============ owner trial: page caching for this store (decision 2026-10-08; off for everyone by default) ============ --}}
    @if (($accountExport['allowed'] ?? false))
        <x-ui.card :title="___('Faster store page (trial)')" data-fullpage-trial>
            <p class="ui-muted">{{ ___('Keeps your store\'s home page for 5 minutes for visitors who are not signed in, so it opens faster on a busy day. Menu and setting changes show at once; the open / closed badge can lag by up to 5 minutes. Only the account owner can change this.') }}</p>
            <form method="POST" action="{{ route('backup.fullpage.trial') }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $fullPageTrial ? 0 : 1 }}">
                <button type="submit" class="ui-btn ui-btn--sm">{{ $fullPageTrial ? ___('Turn off') : ___('Turn on') }}</button>
                <span class="ui-muted">{{ $fullPageTrial ? ___('On') : ___('Off') }}</span>
            </form>
        </x-ui.card>
    @endif

    {{-- ============ Create a backup ============ --}}
    <x-ui.card :title="___('Create a backup')">

        <form method="POST" action="{{ route('backup.store') }}" class="ui-backup__form">
            @csrf
            <input type="hidden" name="orders_window" :value="ordersWindow">

            {{-- datasets, grouped --}}
            <p class="ui-backup__label">{{ ___('What to include') }}</p>
            @foreach($grouped as $groupLabel => $keys)
                <div class="ui-backup__group">
                    <div class="ui-backup__group-head">
                        <span class="ui-overline">{{ $groupLabel }}</span>
                        <button type="button" class="ui-btn ui-btn--link ui-btn--sm" @click.prevent="toggleGroup(@js($keys))">{{ ___('Toggle all') }}</button>
                    </div>
                    <div class="ui-backup__checks">
                        @foreach($keys as $key)
                            <div>
                                <div class="ui-check">
                                    <input type="checkbox" name="datasets[]" value="{{ $key }}"
                                           id="ds-{{ $key }}" x-model="sel"
                                           @if(in_array($key, $defaultSel)) checked @endif>
                                    <label for="ds-{{ $key }}">
                                        {{ $dsLabels[$key] ?? $key }}
                                        @if(in_array($key, $piiSets))
                                            <x-ui.tag tone="warning">{{ ___('personal data') }}</x-ui.tag>
                                        @endif
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            {{-- media files toggle --}}
            <div class="ui-check">
                <input type="checkbox" name="datasets[]" value="media" id="ds-media"
                       x-model="sel" @if(in_array('media', $defaultSel)) checked @endif>
                <label for="ds-media">
                    {{ $dsLabels['media'] }}
                    <span class="ui-backup__muted">— {{ ___('large stores may be capped at 500 MB') }}</span>
                </label>
            </div>

            <div class="ui-banner" data-tone="warning" role="status" x-show="hasPii()" x-cloak>
                <span class="ui-banner__icon"><x-ui.icon name="alert" size="18" /></span>
                <div class="ui-banner__body">{{ ___('You selected personal data (customer names, emails, phones and their orders). Only include it if you are allowed to hold and move that data.') }}</div>
            </div>

            {{-- what's included / excluded transparency --}}
            <div>
                <button type="button" class="ui-btn ui-btn--link ui-btn--sm" @click.prevent="showInfo=!showInfo" :aria-expanded="showInfo ? 'true' : 'false'"><span class="ui-btn__icon"><x-ui.icon name="info" /></span>{{ ___('What\'s included and excluded?') }}</button>
                <div x-show="showInfo" x-cloak class="ui-backup__info">
                    {{ ___('The export contains the datasets you tick above, plus a manifest listing exactly what shipped. Secret credentials (payment/gateway keys, tokens, certificates) and system/ephemeral records are always excluded. This is a curated portable export, not a raw database dump.') }}
                </div>
            </div>

            {{-- format --}}
            <div class="ui-backup__fields">
                <div>
                    <label class="ui-label">{{ ___('Format') }}</label>
                    <select class="ui-input" name="format">
                        <option value="both">{{ ___('JSON + CSV') }}</option>
                        <option value="json">{{ ___('JSON only') }}</option>
                        <option value="csv">{{ ___('CSV only') }}</option>
                    </select>
                </div>

                @if($branches->count())
                <div>
                    <label class="ui-label">{{ ___('Branch') }}</label>
                    <select class="ui-input" name="branch_id">
                        <option value="">{{ ___('All branches') }}</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div>
                    <label class="ui-label">{{ ___('Order history') }}</label>
                    <select class="ui-input" x-model="ordersWindow">
                        <option value="all">{{ ___('All time') }}</option>
                        <option value="90">{{ ___('Last 90 days') }}</option>
                        <option value="30">{{ ___('Last 30 days') }}</option>
                        <option value="custom">{{ ___('Custom range') }}</option>
                    </select>
                </div>
            </div>
            <div class="ui-backup__fields" x-show="ordersWindow === 'custom'" x-cloak>
                <div>
                    <label class="ui-label">{{ ___('From') }}</label>
                    <input type="date" class="ui-input" name="orders_from">
                </div>
                <div>
                    <label class="ui-label">{{ ___('To') }}</label>
                    <input type="date" class="ui-input" name="orders_to">
                </div>
            </div>

            {{-- password --}}
            <div class="ui-backup__narrow">
                <label class="ui-label">{{ ___('Password-protect (optional)') }}</label>
                <input type="password" class="ui-input" name="password" autocomplete="new-password"
                       placeholder="{{ ___('Leave blank for no password') }}" @unless($canEncrypt) disabled @endunless>
                @if($canEncrypt)
                    <div class="ui-backup__muted">{{ ___('Encrypts the ZIP with AES-256. Keep this password safe — it cannot be recovered.') }}</div>
                @else
                    <div class="ui-backup__err">{{ ___('Encryption is unavailable on this server, so password protection is disabled.') }}</div>
                @endif
            </div>

            {{-- notify --}}
            <div class="ui-check">
                <input type="checkbox" name="notify" value="1" id="notify">
                <label for="notify">{{ ___('Email me when it\'s ready') }}</label>
            </div>

            <div><x-ui.button type="submit" icon="download">{{ ___('Prepare backup') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    {{-- ============ Automatic backups ============ --}}
    <x-ui.card :title="___('Automatic backups')">

        @if($schedule)
            <div class="ui-backup__sched">
                <div class="ui-backup__sched-on">
                    <x-ui.pill tone="success">{{ ___('On') }}</x-ui.pill>
                    <span>
                        {{ $schedule->frequency === 'monthly' ? ___('Every month') : ___('Every week') }}
                        @if($schedule->next_run_at) · {{ ___('Next') }}: {{ $schedule->next_run_at->format('Y-m-d') }} @endif
                    </span>
                </div>
                <form method="POST" action="{{ route('backup.schedule.destroy') }}"
                      data-confirm="{{ ___('Turn off automatic backups?') }}">
                    @csrf @method('DELETE')
                    <x-ui.button type="submit" variant="secondary" size="sm" class="ui-menu-item__delete">{{ ___('Turn off') }}</x-ui.button>
                </form>
            </div>
        @else
            <p class="ui-backup__muted ui-backup__intro">{{ ___('Have a backup prepared automatically on a schedule, using the same options as above.') }}</p>
            <form method="POST" action="{{ route('backup.schedule.store') }}" x-data="{ w:'all' }">
                @csrf
                <input type="hidden" name="orders_window" :value="w">
                <template x-for="key in sel" :key="key">
                    <input type="hidden" name="datasets[]" :value="key">
                </template>
                <div class="ui-backup__fields ui-backup__fields--4">
                    <div>
                        <label class="ui-label">{{ ___('Frequency') }}</label>
                        <select class="ui-input" name="frequency">
                            <option value="weekly">{{ ___('Weekly') }}</option>
                            <option value="monthly">{{ ___('Monthly') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Format') }}</label>
                        <select class="ui-input" name="format">
                            <option value="both">{{ ___('JSON + CSV') }}</option>
                            <option value="json">{{ ___('JSON only') }}</option>
                            <option value="csv">{{ ___('CSV only') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Order history') }}</label>
                        <select class="ui-input" x-model="w">
                            <option value="all">{{ ___('All time') }}</option>
                            <option value="90">{{ ___('Last 90 days') }}</option>
                            <option value="30">{{ ___('Last 30 days') }}</option>
                        </select>
                    </div>
                    <div>
                        <div class="ui-check">
                            <input type="checkbox" name="notify" value="1" id="sch-notify">
                            <label for="sch-notify">{{ ___('Email me') }}</label>
                        </div>
                        <x-ui.button type="submit" variant="secondary" block>{{ ___('Turn on') }}</x-ui.button>
                    </div>
                </div>
                <div class="ui-backup__muted ui-backup__after">{{ ___('Automatic backups are never password-protected.') }}</div>
            </form>
        @endif
    </x-ui.card>

    {{-- ============ Your backups ============ --}}
    <section class="ui-card ui-card--flush">
        <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Your backups') }}</h2></div>

        @if($backups->isEmpty())
            <x-ui.empty icon="download" :title="___('No backups yet. Prepare one above.')" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Requested') }}</th>
                        <th>{{ ___('Contents') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-table__num">{{ ___('Size') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($backups as $b)
                    <tr id="backup-{{ $b->token }}">
                        <td data-primary>
                            {{ $b->created_at->diffForHumans() }}
                            @if($b->schedule_id)<x-ui.tag>{{ ___('auto') }}</x-ui.tag>@endif
                        </td>
                        <td data-label="{{ ___('Contents') }}">
                            @php $c = is_array($b->datasets) ? count($b->datasets) : null; @endphp
                            @if($b->include_pii)
                                {{ ___('Includes personal data') }}
                            @elseif($c)
                                {{ $c }} {{ ___('datasets') }}
                            @else
                                {{ ___('Catalog & settings') }}
                            @endif
                            · <span class="ui-caps">{{ $b->format }}</span>
                            @if($b->encrypted) · <x-ui.tag tone="accent" title="{{ ___('Password-protected') }}">{{ ___('Password-protected') }}</x-ui.tag>@endif
                        </td>
                        <td data-label="{{ ___('Status') }}">
                            <span class="ui-backup__status">
                                <span class="ui-pill"
                                      :data-tone="toneOf('{{ $b->token }}', '{{ $b->isExpired() ? 'expired' : $b->status }}')"
                                      x-text="label('{{ $b->token }}', '{{ $b->isExpired() ? 'expired' : $b->status }}')"></span>
                                <template x-if="isWorking('{{ $b->token }}', '{{ $b->status }}')">
                                    <span class="ui-ov-meter" role="progressbar"><span
                                         :style="`inline-size:${progressOf('{{ $b->token }}', {{ (int) $b->progress }})}%`"></span></span>
                                </template>
                            </span>
                        </td>
                        <td class="ui-table__num" data-label="{{ ___('Size') }}"><span class="ui-ltr">{{ $b->size_bytes ? number_format($b->size_bytes / 1024, 0) . ' KB' : '—' }}</span></td>
                        <td class="ui-catalog__row-actions">
                            <template x-if="isReady('{{ $b->token }}', {{ $b->isReady() ? 'true' : 'false' }})">
                                <a class="ui-btn ui-btn--secondary ui-btn--sm" :href="downloadUrl('{{ $b->token }}')">
                                    <span class="ui-btn__icon"><x-ui.icon name="download" /></span>{{ ___('Download') }}
                                </a>
                            </template>
                            <form method="POST" action="{{ route('backup.destroy', $b->token) }}"
                                  data-confirm="{{ ___('Delete this backup?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
    </div>{{-- /export --}}

    {{-- ================================================================ --}}
    {{-- IMPORT / RESTORE --}}
    {{-- ================================================================ --}}
    <div class="ui-backup__stack" x-show="tab==='import'" x-cloak>
        <x-ui.card :title="___('Import from a backup')">
            <p class="ui-backup__muted">
                {{ ___('Upload a backup ZIP to rebuild this store\'s data. Import is additive — it adds or updates records, never deletes. You\'ll see a preview before anything is written.') }}
            </p>
            <form method="POST" action="{{ route('backup.import') }}" enctype="multipart/form-data" class="ui-backup__import">
                @csrf
                <div>
                    <label class="ui-label" for="backup-archive">{{ ___('Backup file (.zip)') }}</label>
                    <input type="file" id="backup-archive" name="archive" accept=".zip" class="ui-input" required>
                    {{-- P6-212: tell the owner the host's real ceiling so they don't waste time uploading a 500 MB file on a 16 MB host. --}}
                    @if(! empty($hostUploadMaxMb))
                        <p class="ui-backup__muted">{{ ___('Up to :mb MB (set by your host)', ['mb' => $hostUploadMaxMb]) }}</p>
                    @endif
                </div>
                <x-ui.button type="submit" icon="upload">{{ ___('Upload & preview') }}</x-ui.button>
            </form>
        </x-ui.card>

        @foreach($imports as $imp)
            <section class="ui-card ui-backup__imp">
                <div class="ui-card__head">
                    <h2 class="ui-card__title">{{ ___('Import') }} · {{ $imp->created_at->diffForHumans() }}</h2>
                    <x-ui.pill :tone="$imp->status === 'done' ? 'success' : ($imp->status === 'failed' ? 'danger' : 'warning')">{{ $imp->status }}</x-ui.pill>
                </div>

                @if($imp->cross_store)
                    <x-ui.banner tone="warning">{{ ___('This backup was made from a different store. Its records will be added to THIS store with new ids.') }}</x-ui.banner>
                @endif

                @if($imp->status === 'previewed' && is_array($imp->plan))
                    <table class="ui-table ui-backup__plan">
                        <thead><tr><th>{{ ___('Dataset') }}</th><th class="ui-table__num">{{ ___('Records') }}</th><th>{{ ___('Action') }}</th></tr></thead>
                        <tbody>
                            @foreach($imp->plan as $file => $row)
                                <tr>
                                    <td data-primary><code>{{ $file }}</code></td>
                                    <td class="ui-table__num" data-label="{{ ___('Records') }}"><span class="ui-ltr">{{ $row['rows'] ?? '—' }}</span></td>
                                    <td data-label="{{ ___('Action') }}">{{ $row['action'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="ui-backup__imp-actions">
                    <form method="POST" action="{{ route('backup.import.confirm', $imp->token) }}" class="ui-backup__confirm"
                          data-confirm="{{ ___('Import these records into this store?') }}">
                        @csrf
                        {{-- HPMS E14(e): dataset picker — tick which datasets to restore (all on by default). --}}
                        @if(is_array($imp->summary) && count($imp->summary))
                            <div class="ui-backup__ds">
                                @foreach(array_keys($imp->summary) as $ds)
                                    <label class="ui-check">
                                        <input type="checkbox" name="datasets[]" value="{{ $ds }}" checked>
                                        <span>{{ str_replace('_', ' ', ucfirst($ds)) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        <x-ui.button type="submit" size="sm" icon="check">{{ ___('Confirm import') }}</x-ui.button>
                    </form>
                    <form method="POST" action="{{ route('backup.import.destroy', $imp->token) }}">
                        @csrf @method('DELETE')
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Discard') }}</x-ui.button>
                    </form>
                    </div>
                @elseif($imp->status === 'done' && is_array($imp->summary))
                    <div class="ui-backup__summary">
                        @foreach($imp->summary as $file => $row)
                            @if(is_array($row))<x-ui.tag>{{ $file }}: <span class="ui-ltr">{{ $row['created'] ?? ($row['restored'] ?? 0) }}</span> {{ ___('added') }}</x-ui.tag>@endif
                        @endforeach
                    </div>
                @elseif($imp->status === 'failed')
                    <x-ui.banner tone="danger">{{ $imp->error }}</x-ui.banner>
                @else
                    <p class="ui-backup__muted" x-text="importLabel('{{ $imp->token }}', '{{ $imp->status }}')">{{ ___('Importing…') }}</p>
                @endif
            </section>
        @endforeach
    </div>{{-- /import --}}
</div>
</div>

<script nonce="{{ csp_nonce() }}">
function storeBackup(cfg) {
    return {
        tab: 'export',
        showInfo: false,
        sel: @js($defaultSel),
        ordersWindow: 'all',
        live: {}, ready: {}, downloads: {}, prog: {}, attempts: {}, importLive: {},
        maxAttempts: 240, // ~10 minutes at 2.5s
        hasPii() { return this.sel.some(k => (cfg.pii || []).includes(k)); },
        toggleGroup(keys) {
            const allOn = keys.every(k => this.sel.includes(k));
            if (allOn) { this.sel = this.sel.filter(k => !keys.includes(k)); }
            else { keys.forEach(k => { if (!this.sel.includes(k)) this.sel.push(k); }); }
        },
        init() {
            (cfg.pending || []).forEach(t => this.poll(t));
            (cfg.imports || []).forEach(t => this.pollImport(t));
        },
        poll(token) {
            this.attempts[token] = 0;
            const tick = () => {
                fetch(cfg.statusBase + '/' + token + '/status', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        this.live[token] = d.status;
                        if (typeof d.progress === 'number') this.prog[token] = d.progress;
                        if (d.status === 'ready' && d.download_url) {
                            this.ready[token] = true;
                            this.downloads[token] = d.download_url;
                        }
                        if (d.status === 'pending' || d.status === 'processing') {
                            this.attempts[token] = (this.attempts[token] || 0) + 1;
                            if (this.attempts[token] >= this.maxAttempts) { this.live[token] = 'stalled'; }
                            else { setTimeout(tick, 2500); }
                        }
                    })
                    .catch(() => {});
            };
            tick();
        },
        pollImport(token) {
            const tick = () => {
                fetch(cfg.statusBase + '/import/' + token + '/status', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        this.importLive[token] = d.status;
                        if (d.status === 'processing') setTimeout(tick, 2500);
                        else if (d.status === 'done' || d.status === 'failed') setTimeout(() => window.location.reload(), 800);
                    })
                    .catch(() => {});
            };
            tick();
        },
        state(token, initial) { return this.live[token] || initial; },
        progressOf(token, initial) { return this.prog[token] ?? initial ?? 0; },
        isWorking(token, initial) { const s = this.state(token, initial); return s === 'pending' || s === 'processing'; },
        label(token, initial) {
            const map = {
                pending: @js(___('Preparing…')), processing: @js(___('Preparing…')),
                ready: @js(___('Ready')), failed: @js(___('Failed')), expired: @js(___('Expired')),
                stalled: @js(___('Still working — refresh later')),
            };
            return map[this.state(token, initial)] || this.state(token, initial);
        },
        importLabel(token, initial) {
            const s = this.importLive[token] || initial;
            const map = { processing: @js(___('Importing…')), done: @js(___('Imported')), failed: @js(___('Failed')) };
            return map[s] || s;
        },
        badgeClass(token, initial) {
            const s = this.state(token, initial);
            if (s === 'ready') return 'bg-success';
            if (s === 'failed') return 'bg-danger';
            if (s === 'expired' || s === 'stalled') return 'bg-secondary';
            return 'bg-warning text-black';
        },
        // Kit pill tone for the same states badgeClass() maps (owner UI kit).
        toneOf(token, initial) {
            const s = this.state(token, initial);
            if (s === 'ready') return 'success';
            if (s === 'failed') return 'danger';
            if (s === 'expired' || s === 'stalled') return 'neutral';
            return 'warning';
        },
        isReady(token, initial) { return this.ready[token] || initial; },
        downloadUrl(token) { return this.downloads[token] || (cfg.statusBase + '/' + token + '/download'); },
    };
}
</script>
@endsection
