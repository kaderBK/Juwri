{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Fiscal Compliance'))
@section('content')
<div class="ui-stack">
    @unless ($masterOn)
        <x-ui.banner tone="warning">{{ ___('Fiscalization master switch is OFF. Enable it in Feature Controls.') }}</x-ui.banner>
    @endunless

    {{-- P6-186 (J44): platform switch "force VAT per line for all KSA stores" (J44a: default ON). Lock each KSA owner's toggle. --}}
    <x-ui.card :title="___('KSA VAT per line')">
        <form method="POST" action="{{ route('admin.fiscal.ksa-vat-per-line') }}" class="ui-stack ui-stack--sm">
            @csrf
            <label class="ui-check">
                <input type="checkbox" name="force" value="1" @checked($ksaForceVatPerLine)>
                <span>{{ ___('Force VAT per line for all KSA stores') }}</span>
            </label>
            <small class="ui-text-2">{{ ___('When on, new orders of every Saudi store use VAT per line and owners cannot turn it off. Orders already placed keep their VAT.') }}</small>
            <div>
                <x-ui.button type="submit" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <section class="ui-kpis" aria-label="{{ ___('Fiscal Compliance') }}">
        @foreach ([
            [number_format($agg['submitted']), ___('Documents submitted'), 'send', null],
            [number_format($agg['accepted']), ___('Accepted'), 'circle-check', 'success'],
            [number_format($agg['pending']), ___('Pending'), 'clock', null],
            [number_format($agg['rejected']), ___('Rejected'), 'alert', 'danger'],
        ] as [$val, $label, $icon, $tone])
            <x-ui.stat :value="$val" :label="$label" :icon="$icon" :tone="$tone" />
        @endforeach
    </section>

    <x-ui.card :title="___('Per-restaurant compliance')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Restaurant') }}</th><th>{{ ___('Country') }}</th><th>{{ ___('Driver') }}</th>
                    <th>{{ ___('Identity') }}</th><th>{{ ___('Enabled') }}</th>
                    <th>{{ ___('Accepted') }}</th><th>{{ ___('Pending') }}</th><th>{{ ___('Rejected') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td data-primary data-label="{{ ___('Restaurant') }}">{{ $r['title'] }}</td>
                        <td data-label="{{ ___('Country') }}">{{ $r['countryName'] }} ({{ $r['country'] }})</td>
                        <td data-label="{{ ___('Driver') }}"><code class="ui-mono">{{ $r['driver'] }}</code></td>
                        <td data-label="{{ ___('Identity') }}">@if ($r['identityComplete'])<x-ui.tag tone="success">{{ ___('Complete') }}</x-ui.tag>@else<x-ui.tag tone="warning">{{ ___('Incomplete') }}</x-ui.tag>@endif</td>
                        <td data-label="{{ ___('Enabled') }}">@if ($r['countryEnabled'])<x-ui.tag tone="success">{{ ___('On') }}</x-ui.tag>@else<x-ui.tag>{{ ___('Off') }}</x-ui.tag>@endif</td>
                        <td data-label="{{ ___('Accepted') }}">{{ $r['accepted'] }}</td>
                        <td data-label="{{ ___('Pending') }}">{{ $r['pending'] }}</td>
                        <td data-label="{{ ___('Rejected') }}">{{ $r['rejected'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ui-center ui-text-2">{{ ___('No restaurants have selected a fiscal country yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
