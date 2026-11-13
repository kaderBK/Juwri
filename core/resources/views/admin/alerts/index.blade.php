{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Platform Alerts'))
@section('content')
<div class="ui-stack">
    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('Platform Alerts') }}">
        <x-ui.stat :value="number_format($openCount)" :label="___('Open alerts')" icon="bell" tone="danger" />
        <x-ui.stat :value="number_format($weekCount)" :label="___('Alerts (7 days)')" icon="clock" />
        {{-- WI-M6: payment failures reach an operator here instead of a discarded Log::info. --}}
        <x-ui.stat :value="number_format($paymentsOpen ?? 0)" :label="___('Open payment alerts')" icon="card" :tone="($paymentsOpen ?? 0) ? 'danger' : null" />
    </section>

    <x-ui.card variant="flush" :title="___('Platform Alerts')">
        <x-slot:actions>
            <nav class="ui-chips" aria-label="{{ ___('Platform Alerts') }}">
                <x-ui.chip :href="route('admin.alerts.index')" :active="$show === 'open'">{{ ___('Open') }}</x-ui.chip>
                <x-ui.chip :href="route('admin.alerts.index', ['show' => 'resolved'])" :active="$show === 'resolved'">{{ ___('Resolved') }}</x-ui.chip>
                {{-- WI-M6: one-click filter to the payments failure stream. --}}
                <x-ui.chip :href="route('admin.alerts.index', ['kind' => 'payment'])" :active="($kind ?? null) === 'payment'">{{ ___('Payments') }}</x-ui.chip>
            </nav>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Kind') }}</th>
                        <th>{{ ___('Message') }}</th>
                        <th>{{ ___('When') }}</th>
                        <th class="ui-end">{{ ___('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($alerts as $alert)
                        <tr>
                            <td data-primary data-label="{{ ___('Kind') }}"><x-ui.tag>{{ $alert->kind }}</x-ui.tag></td>
                            <td data-label="{{ ___('Message') }}">{{ $alert->message }}</td>
                            <td data-label="{{ ___('When') }}"><span class="ui-small">{{ optional($alert->created_at)->diffForHumans() }}</span></td>
                            <td class="ui-end" data-label="">
                                @if (! $alert->resolved_at)
                                    <form method="POST" action="{{ route('admin.alerts.resolve', $alert->id) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="check">{{ ___('Resolve') }}</x-ui.button>
                                    </form>
                                @else
                                    <x-ui.tag tone="success">{{ ___('Resolved') }}</x-ui.tag>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">{{ ___('No alerts.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($alerts->hasPages())
            <div class="ui-card__foot">{{ $alerts->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
