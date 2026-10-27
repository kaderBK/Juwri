{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('A/B Experiments'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :href="route('admin.ab-experiments.create')">{{ ___('New Experiment') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <x-ui.banner>
        <strong>{{ ___('Usage:') }}</strong> {{ ___('Call') }} <code class="ui-mono">ab_variant('experiment-name')</code> {{ ___("in any Blade view to get the current visitor's variant (A/B/…).") }}
        {{ ___('Call') }} <code class="ui-mono">ab_convert('experiment-name')</code> {{ ___('on goal completion (signup, purchase, etc.).') }}
    </x-ui.banner>

    @foreach ($experiments as $exp)
    @php $stats = $exp->stats(); @endphp
    <x-ui.card variant="flush">
        <div class="ui-card__head">
            <div class="ui-cluster">
                <code class="ui-mono ui-strong">{{ $exp->name }}</code>
                @if ($exp->status === 'active')
                    <x-ui.tag tone="success">{{ ___('Active') }}</x-ui.tag>
                @elseif ($exp->status === 'paused')
                    <x-ui.tag tone="warning">{{ ___('Paused') }}</x-ui.tag>
                @else
                    <x-ui.tag>{{ ___('Ended') }}</x-ui.tag>
                @endif
                <small class="ui-text-2">{{ $exp->description }}</small>
            </div>
            @if ($exp->status !== 'ended')
            <form method="POST" action="{{ route('admin.ab-experiments.destroy', $exp) }}" data-ui-confirm="{{ ___('End this experiment?') }}" data-ui-danger>
                @csrf @method('DELETE')
                <x-ui.button type="submit" variant="danger" size="sm">{{ ___('End') }}</x-ui.button>
            </form>
            @endif
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Variant') }}</th>
                        <th class="ui-end">{{ ___('Impressions') }}</th>
                        <th class="ui-end">{{ ___('Conversions') }}</th>
                        <th class="ui-end">{{ ___('Conv. Rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($exp->variants as $variant)
                @php
                    $s = $stats[$variant] ?? null;
                    $imp = $s['impressions'] ?? 0;
                    $conv = $s['conversions'] ?? 0;
                    $rate = $imp > 0 ? round($conv / $imp * 100, 1) : 0;
                @endphp
                <tr>
                    <td data-primary data-label="{{ ___('Variant') }}"><strong>{{ $variant }}</strong></td>
                    <td class="ui-end" data-label="{{ ___('Impressions') }}">{{ number_format($imp) }}</td>
                    <td class="ui-end" data-label="{{ ___('Conversions') }}">{{ number_format($conv) }}</td>
                    <td class="ui-end" data-label="{{ ___('Conv. Rate') }}">
                        <span class="{{ $rate >= 5 ? 'ui-text-success ui-strong' : ($rate > 0 ? 'ui-text-warning' : 'ui-text-2') }}">
                            {{ $rate }}%
                        </span>
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="ui-card__foot ui-small ui-text-2">
            {{ ___('Started') }} {{ $exp->started_at?->diffForHumans() ?? '—' }}
            @if ($exp->ended_at) · {{ ___('Ended') }} {{ $exp->ended_at->diffForHumans() }} @endif
        </div>
    </x-ui.card>
    @endforeach

    @if ($experiments->isEmpty())
        <x-ui.card>
            <x-ui.empty icon="chart" :title="___('No experiments yet. Create one to start tracking variants.')">
                <x-ui.button size="sm" icon="plus" :href="route('admin.ab-experiments.create')">{{ ___('New Experiment') }}</x-ui.button>
            </x-ui.empty>
        </x-ui.card>
    @endif
</div>
@endsection
