{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Activity Log'))
@section('content')
<div class="ui-stack">
    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('Activity Log') }}">
        <x-ui.stat :value="number_format($stats['last24h'])" :label="___('Actions (last 24h)')" icon="clock" />
        <x-ui.stat :value="number_format($stats['last7d'])" :label="___('Actions (last 7 days)')" icon="chart" />
        <x-ui.stat :value="number_format($stats['actors7d'])" :label="___('Active actors (7 days)')" icon="users" tone="success" />
        <x-ui.stat :value="number_format($stats['restaurants7d'])" :label="___('Active restaurants (7 days)')" icon="store" tone="warning" />
    </section>

    <form method="GET" class="ui-cluster">
        <select name="action" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Action') }}">
            <option value="">{{ ___('All actions') }}</option>
            @foreach ($actions as $actionOption)
                <option value="{{ $actionOption }}" @selected(request('action') === $actionOption)>{{ $actionOption }}</option>
            @endforeach
        </select>
        <input type="number" name="store_id" value="{{ request('store_id') }}" min="1"
               class="ui-input ui-input--sm ui-input--auto" placeholder="{{ ___('Restaurant ID') }}" aria-label="{{ ___('Restaurant ID') }}">
        <input type="text" name="actor" value="{{ request('actor') }}"
               class="ui-input ui-input--sm ui-input--auto" placeholder="{{ ___('Actor name or email') }}" aria-label="{{ ___('Actor name or email') }}">
        <select name="days" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Date') }}">
            <option value="1" @selected($days === 1)>{{ ___('Last 24 hours') }}</option>
            <option value="7" @selected($days === 7)>{{ ___('Last 7 days') }}</option>
            <option value="30" @selected($days === 30)>{{ ___('Last 30 days') }}</option>
        </select>
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card variant="flush" :title="___('Activity Log')">
        <x-slot:actions>
            {{-- WI-H2: blocked impersonation attempts are security signal, not noise. --}}
            <a href="{{ route('admin.activity.index', ['action' => 'impersonation.blocked', 'days' => 7]) }}" class="ui-tag"
               data-tone="{{ ($stats['impersonationBlocked7d'] ?? 0) ? 'danger' : 'neutral' }}">
                {{ ___('Blocked impersonation attempts') }}: {{ number_format($stats['impersonationBlocked7d'] ?? 0) }}
            </a>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>{{ ___('Time') }}</th>
                    <th>{{ ___('Actor') }}</th>
                    <th>{{ ___('Action') }}</th>
                    <th>{{ ___('Restaurant') }}</th>
                    <th>{{ ___('Subject') }}</th>
                    <th>{{ ___('IP') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    @php
                        $actor = $log->actor_user_id ? $actors->get($log->actor_user_id) : null;
                        $store = $log->store_id ? $stores->get($log->store_id) : null;
                    @endphp
                    <tr>
                        <td data-label="{{ ___('Time') }}">
                            <span title="{{ $log->created_at?->format('Y-m-d H:i:s') }}">{{ $log->created_at?->diffForHumans() }}</span>
                        </td>
                        <td data-primary data-label="{{ ___('Actor') }}">
                            @if ($actor)
                                <a href="{{ route('admin.users.edit', $actor->id) }}">{{ $actor->name }}</a>
                                <small class="ui-cell-sub">{{ $actor->email }}</small>
                            @else
                                <span class="ui-text-2">{{ ___('System') }}</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('Action') }}"><code class="ui-mono">{{ $log->action }}</code></td>
                        <td data-label="{{ ___('Restaurant') }}">{{ $store->title ?? '—' }}</td>
                        <td data-label="{{ ___('Subject') }}">
                            @if ($log->subject_type)
                                {{ class_basename($log->subject_type) }} <small class="ui-text-2">#{{ $log->subject_id }}</small>
                            @else
                                —
                            @endif
                        </td>
                        <td data-label="{{ ___('IP') }}"><small class="ui-text-2 ui-mono">{{ $log->ip ?: '—' }}</small></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="ui-center ui-text-2">{{ ___('No activity recorded for this period.') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="ui-card__foot">{{ $logs->withQueryString()->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
