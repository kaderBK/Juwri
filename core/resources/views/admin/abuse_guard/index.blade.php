{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Abuse Guard'))
@section('header_buttons')
    <x-ui.tag tone="accent">{{ ___('Server Protection') }}</x-ui.tag>
@endsection

@section('content')
@php
    $__modeOptions = ['off' => ___('Off'), 'warn' => ___('Warn-only'), 'enforce' => ___('Enforce')];
    $__tierTone = ['free' => 'danger', 'trial' => 'warning', 'paid' => 'success'];
@endphp
<div class="ui-stack">
    {{-- session('success') is shown as a kit toast by the layout. --}}
    @if($errors->any())
        <x-ui.banner tone="danger">{{ implode(', ', $errors->all()) }}</x-ui.banner>
    @endif

    <x-ui.banner>
        <strong>{{ ___('Warn-only by default.') }}</strong> {{ ___('Every guard ships in') }} <code class="ui-mono">Warn</code> {{ ___('mode: would-be blocks are logged to the Events tab but nothing is blocked. Watch the 24h counts, then switch a guard to') }}
        <code class="ui-mono">Enforce</code> {{ ___('once you trust its limits.') }} <code class="ui-mono">Off</code> {{ ___('disables logging too.') }}
    </x-ui.banner>

    {{-- Tabs --}}
    @php $tabs = ['rate-limits' => 'Rate Limits', 'ai-spend' => 'AI Spend', 'signup' => 'Signup Guard', 'queue' => 'Queue & Uploads', 'events' => 'Events', 'resource-usage' => 'Resource Usage']; @endphp
    <nav class="ui-chips" aria-label="{{ ___('Abuse Guard') }}">
        @foreach($tabs as $key => $label)
            <a class="ui-chip" href="{{ route('admin.abuse-guard.index', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>{{ ___($label) }}</a>
        @endforeach
    </nav>

    @if($tab === 'rate-limits')
        <form method="POST" action="{{ route('admin.abuse-guard.rate-limits') }}" class="ui-stack">
            @csrf
            <x-ui.card variant="flush" :title="___('Named rate limiters')">
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('Limiter') }}</th>
                                <th style="inline-size:140px">{{ ___('Mode') }}</th>
                                <th style="inline-size:110px">{{ ___('Per minute') }}</th>
                                <th style="inline-size:110px">{{ ___('Per hour') }}</th>
                                <th style="inline-size:120px">{{ ___('24h events') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($limiters as $guard => $row)
                                <tr>
                                    <td data-primary data-label="{{ ___('Limiter') }}">
                                        <strong>{{ $guard }}</strong>
                                        <small class="ui-cell-sub">{{ $row[0] }}</small>
                                    </td>
                                    <td data-label="{{ ___('Mode') }}">
                                        <select name="{{ $guard }}_mode" class="ui-input ui-input--sm" aria-label="{{ $guard }} — {{ ___('Mode') }}">
                                            @foreach($__modeOptions as $mv => $ml)
                                                <option value="{{ $mv }}" @selected($modes[$guard] === $mv)>{{ $ml }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td data-label="{{ ___('Per minute') }}">
                                        <input type="number" min="0" name="{{ $guard }}_per_min" class="ui-input ui-input--sm"
                                               value="{{ $val('abuse_rl_' . $guard . '_per_min', $row[1]) }}" aria-label="{{ $guard }} — {{ ___('Per minute') }}">
                                    </td>
                                    <td data-label="{{ ___('Per hour') }}">
                                        <input type="number" min="0" name="{{ $guard }}_per_hour" class="ui-input ui-input--sm"
                                               value="{{ $val('abuse_rl_' . $guard . '_per_hour', $row[2]) }}" aria-label="{{ $guard }} — {{ ___('Per hour') }}">
                                    </td>
                                    <td data-label="{{ ___('24h events') }}">
                                        <x-ui.tag :tone="$counts[$guard] > 0 ? 'warning' : 'neutral'">{{ $counts[$guard] }}</x-ui.tag>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save rate limits') }}</x-ui.button>
                </div>
            </x-ui.card>

            @php $xf = $extra['fields'] ?? []; @endphp
            <x-ui.card :title="___('Branch ceilings & bulk messaging')">
                <x-slot:actions>
                    <span class="ui-cell-tags">
                        <x-ui.tag :tone="($extra['branch_events'] ?? 0) > 0 ? 'warning' : 'neutral'">{{ ___('Branch: :count / 24h', ['count' => $extra['branch_events'] ?? 0]) }}</x-ui.tag>
                        <x-ui.tag :tone="($extra['messaging_events'] ?? 0) > 0 ? 'warning' : 'neutral'">{{ ___('Messaging: :count / 24h', ['count' => $extra['messaging_events'] ?? 0]) }}</x-ui.tag>
                    </span>
                </x-slot:actions>
                <div class="ui-stack">
                    <p class="ui-note" data-tone="neutral">
                        {{ ___('Per-restaurant/branch ceilings on public order/waitlist/reservation writes (menu') }} <strong>{{ ___('reads are never limited') }}</strong>{{ ___('),
                        plus the per-tenant daily cap on bulk/marketing WhatsApp + SMS (transactional sends exempt).') }}
                    </p>
                    <div class="ui-form-grid">
                        <x-ui.field type="select" name="branch_mode" id="ag-branch-mode" :label="___('Branch ceilings mode')" :value="$extra['branch_mode'] ?? 'warn'" :options="$__modeOptions" />
                        @foreach(['branch_orders_per_min' => 'Orders / min / branch', 'branch_waitlist_per_min' => 'Waitlist joins / min', 'branch_reservation_per_min' => 'Reservations / min'] as $k => $label)
                            <x-ui.field type="number" :name="$k" :id="'ag-'.$k" :label="$label" :value="$xf[$k] ?? ''" min="0" />
                        @endforeach
                    </div>
                    <div class="ui-form-grid ui-rule-top">
                        <x-ui.field type="select" name="messaging_mode" id="ag-messaging-mode" :label="___('Bulk messaging mode')" :value="$extra['messaging_mode'] ?? 'warn'" :options="$__modeOptions" />
                        <x-ui.field type="number" name="messaging_tenant_daily_cap" id="ag-messaging-cap" :label="___('Marketing messages / tenant / day')" :value="$xf['messaging_tenant_daily_cap'] ?? ''" min="0" />
                        <x-ui.field type="number" name="webhook_in_max_kb" id="ag-webhook-kb" :label="___('Inbound webhook payload cap (KB)')" :value="$xf['webhook_in_max_kb'] ?? ''" min="0" />
                    </div>
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save rate limits') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @elseif($tab === 'ai-spend')
        @php $f = $aiSpend['fields'] ?? []; @endphp
        <form method="POST" action="{{ route('admin.abuse-guard.ai-spend') }}">
            @csrf
            <x-ui.card :title="___('Denial-of-wallet caps')">
                <x-slot:actions>
                    <x-ui.tag :tone="($aiSpend['events_24h'] ?? 0) > 0 ? 'warning' : 'neutral'">{{ ___(':count events / 24h', ['count' => $aiSpend['events_24h'] ?? 0]) }}</x-ui.tag>
                </x-slot:actions>
                <div class="ui-stack">
                    <p class="ui-note" data-tone="neutral">
                        {{ ___('Today so far:') }} <strong>{{ $aiSpend['today_total'] ?? 0 }}</strong> {{ ___('platform AI calls across') }}
                        <strong>{{ $aiSpend['today_users'] ?? 0 }}</strong> {{ ___("users. BYOK calls bypass these caps (they burn the owner's wallet).") }}
                    </p>
                    @php
                        $rows = [
                            'user_daily_cap' => 'Per-user daily call cap',
                            'tenant_daily_cap' => 'Per-tenant (reseller) daily cap',
                            'platform_daily_cap' => 'Platform-wide daily cap (kill switch)',
                            'anomaly_multiplier' => 'Anomaly multiplier (× personal 7-day avg)',
                            'max_input_chars' => 'Max input characters per call',
                            'max_tokens' => 'Max output tokens per call',
                        ];
                    @endphp
                    <div class="ui-form-grid">
                        <x-ui.field type="select" name="ai_spend_mode" id="ag-ai-mode" :label="___('Mode')" :value="$aiSpend['mode'] ?? 'warn'" :options="$__modeOptions" />
                        @foreach($rows as $key => $label)
                            <x-ui.field type="number" :name="$key" :id="'ag-'.$key" :label="$label" :value="$f[$key] ?? ''" min="0"
                                        :step="$key === 'anomaly_multiplier' ? '0.1' : '1'" />
                        @endforeach
                    </div>
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save AI spend settings') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @elseif($tab === 'signup')
        @php $f = $signup['fields'] ?? []; @endphp
        <form method="POST" action="{{ route('admin.abuse-guard.signup') }}">
            @csrf
            <x-ui.card title="Signup guard">
                <x-slot:actions>
                    <x-ui.tag :tone="($signup['events_24h'] ?? 0) > 0 ? 'warning' : 'neutral'">{{ ___(':count events / 24h', ['count' => $signup['events_24h'] ?? 0]) }}</x-ui.tag>
                </x-slot:actions>
                <div class="ui-stack">
                    <p class="ui-note" data-tone="neutral">
                        {{ ___('Disposable-email blocklist + honeypot are always on (warn/enforce per mode). Turnstile is') }}
                        <strong>{{ ___('skipped') }}</strong> {{ ___('until both keys below are filled.') }}
                    </p>
                    <div class="ui-form-grid">
                        <x-ui.field type="select" name="signup_mode" id="ag-signup-mode" :label="___('Mode')" :value="$signup['mode'] ?? 'warn'" :options="$__modeOptions" />
                        <x-ui.field type="number" name="velocity_per_day" id="ag-velocity" :label="___('Max accounts / day (per email & IP)')" :value="$f['velocity_per_day'] ?? 3" min="0" />
                        <x-ui.field name="turnstile_site_key" id="ag-ts-site" :label="___('Turnstile site key')" :value="$f['turnstile_site_key'] ?? ''" placeholder="empty = Turnstile disabled" />
                        <x-ui.field name="turnstile_secret_key" id="ag-ts-secret" :label="___('Turnstile secret key')" :value="$f['turnstile_secret_key'] ?? ''" placeholder="empty = Turnstile disabled" />
                    </div>
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save signup guard settings') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @elseif($tab === 'queue')
        @php $f = $queue['fields'] ?? []; @endphp
        <form method="POST" action="{{ route('admin.abuse-guard.queue') }}">
            @csrf
            <x-ui.card title="Queue & uploads">
                <x-slot:actions>
                    <x-ui.tag :tone="($queue['events_24h'] ?? 0) > 0 ? 'warning' : 'neutral'">{{ ___(':count events / 24h', ['count' => $queue['events_24h'] ?? 0]) }}</x-ui.tag>
                </x-slot:actions>
                @php
                    $modes = [
                        'queue_mode' => ['Heavy-queue quota mode', $queue['queue_mode'] ?? 'warn'],
                        'upload_mode' => ['Upload hardening mode', $queue['upload_mode'] ?? 'warn'],
                        'storage_mode' => ['Storage quota mode', $queue['storage_mode'] ?? 'warn'],
                    ];
                    $nums = [
                        'queue_tenant_pending_cap' => 'Max pending heavy jobs / tenant',
                        'upload_max_mb' => 'Max upload size (MB)',
                        'upload_max_pixels' => 'Max image pixels (pre-decode)',
                        'scan_max_pages' => 'Max menu-scan PDF pages',
                        'storage_tenant_cap_mb' => 'Storage quota / tenant (MB)',
                    ];
                @endphp
                <div class="ui-stack">
                    <div class="ui-form-grid">
                        @foreach($modes as $name => $row)
                            <x-ui.field type="select" :name="$name" :id="'ag-'.$name" :label="$row[0]" :value="$row[1]" :options="$__modeOptions" />
                        @endforeach
                    </div>
                    <div class="ui-form-grid ui-rule-top">
                        @foreach($nums as $key => $label)
                            <x-ui.field type="number" :name="$key" :id="'ag-'.$key" :label="$label" :value="$f[$key] ?? ''" min="0" />
                        @endforeach
                    </div>
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save queue & upload settings') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @elseif($tab === 'events')
        <x-ui.card variant="flush">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Abuse events') }} <span class="ui-text-2 ui-small">({{ ___('last 7 days') }})</span></h2>
            </div>
            <div class="ui-card__body">
                <nav class="ui-chips" aria-label="{{ ___('Guard') }}">
                    <a href="{{ route('admin.abuse-guard.index', ['tab' => 'events']) }}" class="ui-chip" @if(empty($events['filter'])) aria-current="page" @endif>{{ ___('All') }}</a>
                    @foreach($events['guards'] ?? [] as $g)
                        <a href="{{ route('admin.abuse-guard.index', ['tab' => 'events', 'guard' => $g]) }}" class="ui-chip" @if(($events['filter'] ?? '') === $g) aria-current="page" @endif>
                            {{ $g }} <span class="ui-chip__count">{{ $events['counts'][$g] ?? 0 }}</span>
                        </a>
                    @endforeach
                </nav>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr><th>{{ ___('When') }}</th><th>{{ ___('Guard') }}</th><th>{{ ___('Action') }}</th><th>{{ ___('Mode') }}</th><th>{{ ___('User') }}</th><th>{{ ___('IP') }}</th><th>{{ ___('Meta') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse($events['rows'] ?? [] as $ev)
                            <tr>
                                <td data-primary data-label="{{ ___('When') }}" class="ui-small ui-nowrap">{{ $ev->created_at?->format('m-d H:i') }}</td>
                                <td data-label="{{ ___('Guard') }}"><x-ui.tag>{{ $ev->guard }}</x-ui.tag></td>
                                <td data-label="{{ ___('Action') }}" class="ui-small">{{ $ev->action }}</td>
                                <td data-label="{{ ___('Mode') }}" class="ui-small">{{ $ev->mode }}</td>
                                <td data-label="{{ ___('User') }}" class="ui-small">{{ $ev->user_id ?? '—' }}</td>
                                <td data-label="{{ ___('IP') }}" class="ui-small">{{ $ev->ip ?? '—' }}</td>
                                <td data-label="{{ ___('Meta') }}" class="ui-small ui-text-2 ui-truncate" style="max-inline-size:260px">{{ $ev->meta ? json_encode($ev->meta) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="ui-center ui-text-2">{{ ___('No abuse events in the last 7 days.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(isset($events['rows']))
                <div class="ui-card__foot">{{ $events['rows']->links() }}</div>
            @endif
        </x-ui.card>

        @php $al = $events['alerts'] ?? []; @endphp
        <form method="POST" action="{{ route('admin.abuse-guard.alerts') }}">
            @csrf
            <x-ui.card :title="___('Alert thresholds & retention')">
                @php
                    $rows = [
                        'signups_per_hour' => 'Signup-spike alert (events / hour)',
                        'user_events_per_day' => 'Single-user flood alert (events / day)',
                        'heavy_tenant_pct' => 'Heavy tenant (% of platform requests)',
                        'heavy_tenant_ai_pct' => 'Heavy AI tenant (% of AI spend)',
                        'retention_days' => 'Event retention (days, 0 = keep forever)',
                    ];
                @endphp
                <div class="ui-form-grid">
                    @foreach($rows as $k => $label)
                        <x-ui.field type="number" :name="$k" :id="'ag-'.$k" :label="$label" :value="$al[$k] ?? ''" min="0"
                                    :step="str_contains($k, 'pct') ? '0.1' : '1'" />
                    @endforeach
                </div>
                <div class="ui-card__foot ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" data-ui-busy>{{ ___('Save alert settings') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @elseif($tab === 'resource-usage')
        @if(!empty($usage['heavy']))
            <x-ui.banner tone="warning">
                <strong>{{ ___('Heavy tenants:') }}</strong>
                <ul>
                    @foreach($usage['heavy'] as $h)<li>{{ $h }}</li>@endforeach
                </ul>
            </x-ui.banner>
        @endif
        <div class="ui-columns">
            @foreach($usage['metrics'] ?? [] as $metric)
                <x-ui.card variant="flush">
                    <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Top tenants —') }} <code class="ui-mono">{{ $metric }}</code></h2></div>
                    <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('Tenant') }}</th>
                                <th>{{ ___('Plan') }}</th>
                                <th class="ui-end">{{ ___('Today') }}</th>
                                <th class="ui-end" title="{{ ___('Share of platform today') }}">{{ ___('Share') }}</th>
                                <th class="ui-end" title="{{ ___('Month to date') }}">{{ ___('MTD') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($usage['top'][$metric] ?? [] as $r)
                                <tr>
                                    <td data-primary data-label="{{ ___('Tenant') }}">
                                        <a href="{{ route('admin.abuse-guard.index', ['tab' => 'resource-usage', 'tenant' => $r->user_id]) }}">{{ $r->tenant_name ?: ('User #'.$r->user_id) }}</a>
                                        <small class="ui-cell-sub">#{{ $r->user_id }}{{ $r->tenant_email ? ' · '.$r->tenant_email : '' }}{{ $r->reseller_id ? ' · R'.$r->reseller_id : '' }}</small>
                                    </td>
                                    <td data-label="{{ ___('Plan') }}"><x-ui.tag :tone="$__tierTone[$r->tier] ?? 'neutral'">{{ $r->plan }}</x-ui.tag></td>
                                    <td data-label="{{ ___('Today') }}" class="ui-end"><strong>{{ number_format($r->value) }}</strong></td>
                                    <td data-label="{{ ___('Share') }}" @class(['ui-end', 'ui-text-danger ui-strong' => $r->share_pct >= 10])>{{ $r->share_pct }}%</td>
                                    <td data-label="{{ ___('MTD') }}" class="ui-end ui-text-2">{{ number_format($r->mtd) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="ui-center ui-text-2">{{ ___('No data yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        @if(!empty($usage['drill']))
            <x-ui.card variant="flush">
                <div class="ui-card__head">
                    <div>
                        <h2 class="ui-card__title">{{ $usage['drill']['name'] ?: ('User #'.$usage['drill']['user_id']) }}
                            <x-ui.tag :tone="$__tierTone[$usage['drill']['tier'] ?? ''] ?? 'neutral'">{{ $usage['drill']['plan'] }}</x-ui.tag>
                            <span class="ui-text-2 ui-small">— {{ ___('last 14 days') }}</span></h2>
                        <p class="ui-text-2 ui-small">#{{ $usage['drill']['user_id'] }}{{ $usage['drill']['email'] ? ' · '.$usage['drill']['email'] : '' }}</p>
                    </div>
                </div>
                @if(!empty($usage['drill']['mtd']) && count($usage['drill']['mtd']))
                    <div class="ui-card__body ui-cluster">
                        <span class="ui-text-2 ui-small">{{ ___('Month to date:') }}</span>
                        @foreach($usage['drill']['mtd'] as $m => $v)
                            <x-ui.tag><code class="ui-mono">{{ $m }}</code> {{ number_format($v) }}</x-ui.tag>
                        @endforeach
                    </div>
                @endif
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead><tr><th>{{ ___('Date') }}</th><th>{{ ___('Metric') }}</th><th class="ui-end">{{ ___('Value') }}</th></tr></thead>
                        <tbody>
                            @forelse($usage['drill']['series'] as $s)
                                <tr>
                                    <td data-primary data-label="{{ ___('Date') }}" class="ui-small">{{ $s->date }}</td>
                                    <td data-label="{{ ___('Metric') }}" class="ui-small"><code class="ui-mono">{{ $s->metric }}</code></td>
                                    <td data-label="{{ ___('Value') }}" class="ui-end">{{ number_format($s->value) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No history for this tenant.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @else
        <x-ui.card>
            <p class="ui-text-2">{{ ___('This tab is delivered in a later build phase.') }}</p>
        </x-ui.card>
    @endif
</div>
@endsection
