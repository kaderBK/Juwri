{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Queue Health'))
@section('content')

    @php
        $__totalDepth = collect($queues ?? [])->sum('depth');
        $__oldest = collect($queues ?? [])->max('oldest_age') ?? 0;
    @endphp

<div class="ui-stack">
    {{-- H7: Queue Health — a stalled drain silently disables every async side effect
         (order mail, SMS/WhatsApp, POS pushes, webhooks, backups). --}}
    <section class="ui-kpis" aria-label="{{ ___('Queue Health') }}">
        <x-ui.stat :value="$workerAlive ? ___('running') : ___('no worker (cron drain)')" :label="___('Queue worker')" icon="server-2" />
        <x-ui.stat :value="$schedulerAlive === null ? ___('unknown') : ($schedulerAlive ? ___('alive') : ___('stalled'))"
                   :label="___('Scheduler').($schedulerAge !== null ? ' ('.(int) $schedulerAge.'s)' : '')"
                   icon="clock" :tone="$schedulerAlive === false ? 'danger' : null" />
        <x-ui.stat :value="number_format($__totalDepth)" :label="___('Jobs waiting')" icon="list" :tone="$__totalDepth > $maxQueueDepth ? 'danger' : null" />
        <x-ui.stat :value="(int) round($__oldest / 60).'m'" :label="___('Oldest job')" icon="history" :tone="$__oldest > $maxQueueAge ? 'danger' : null" />
    </section>

    <x-ui.card variant="flush" :title="___('Queue Health')">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.queue.clear-schedule-locks') }}"
                  data-ui-confirm="{{ ___('Release all scheduler locks? Only do this when the queue is stuck.') }}" data-ui-danger>
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" icon="lock-open">{{ ___('Release stuck scheduler locks') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Queue') }}</th>
                        <th>{{ ___('Waiting') }}</th>
                        <th>{{ ___('Oldest job') }}</th>
                        <th>{{ ___('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($queues as $q)
                        @php $__stalled = ($q['depth'] > $maxQueueDepth) || ($q['oldest_age'] !== null && $q['oldest_age'] > $maxQueueAge); @endphp
                        <tr>
                            <td data-primary data-label="{{ ___('Queue') }}"><x-ui.tag>{{ $q['queue'] }}</x-ui.tag></td>
                            <td data-label="{{ ___('Waiting') }}">{{ number_format($q['depth']) }}</td>
                            <td data-label="{{ ___('Oldest job') }}">{{ $q['oldest_age'] === null ? '—' : (int) round($q['oldest_age'] / 60).'m' }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$__stalled ? 'danger' : 'success'">{{ $__stalled ? ___('degraded') : ___('ok') }}</x-ui.tag></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">
                            @if ($queueDriver !== 'database')
                                {{ ___('Queue depth is only measurable on the database queue driver.') }}
                            @else
                                {{ ___('No queued jobs.') }}
                            @endif
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <section class="ui-kpis" aria-label="{{ ___('Failed Jobs') }}">
        <x-ui.stat :value="number_format($total)" :label="___('Failed jobs')" icon="circle-x" :tone="$total > 0 ? 'danger' : null" />
    </section>

    <x-ui.card variant="flush" :title="___('Failed Jobs')">
        @if ($total > 0)
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.failed-jobs.flush') }}" data-ui-confirm="{{ ___('Clear all failed jobs?') }}" data-ui-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Flush all') }}</x-ui.button>
                </form>
            </x-slot:actions>
        @endif
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Queue') }}</th>
                        <th>{{ ___('Exception') }}</th>
                        <th>{{ ___('Failed at') }}</th>
                        <th class="ui-end">{{ ___('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                        <tr>
                            <td data-primary data-label="{{ ___('Queue') }}"><x-ui.tag>{{ $job->connection }} / {{ $job->queue }}</x-ui.tag></td>
                            <td data-label="{{ ___('Exception') }}"><span class="ui-small ui-break">{{ \Illuminate\Support\Str::limit(strtok($job->exception, "\n"), 120) }}</span></td>
                            <td data-label="{{ ___('Failed at') }}"><span class="ui-small">{{ $job->failed_at }}</span></td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    <form method="POST" action="{{ route('admin.failed-jobs.retry', $job->uuid) }}">
                                        @csrf
                                        <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-success" title="{{ ___('Retry') }}" aria-label="{{ ___('Retry') }}"><x-ui.icon name="refresh" size="18" /></button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.failed-jobs.forget', $job->uuid) }}">
                                        @csrf
                                        <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">{{ ___('No failed jobs.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if (method_exists($jobs, 'hasPages') && $jobs->hasPages())
            <div class="ui-card__foot">{{ $jobs->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
