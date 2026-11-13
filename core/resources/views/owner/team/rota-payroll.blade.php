{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Payroll'))
@section('page_title', ___('Payroll'))
@section('page_sub', ___('Scheduled vs worked hours, pay and tips. Report only — no payments.'))

@section('page_actions')
    <x-ui.button variant="secondary" icon="calendar" :href="route('stores.rota.index').'?store_id='.$rid">{{ ___('Back to rota') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-rota">
    <x-ui.card>
        <form method="get" action="{{ route('stores.rota.payroll') }}" class="ui-rota__form">
            <input type="hidden" name="store_id" value="{{ $rid }}">
            <div class="ui-rota__field">
                <label class="ui-label" for="rp-from">{{ ___('From') }}</label>
                <input id="rp-from" type="date" name="from" class="ui-input" value="{{ $from }}">
            </div>
            <div class="ui-rota__field">
                <label class="ui-label" for="rp-to">{{ ___('To') }}</label>
                <input id="rp-to" type="date" name="to" class="ui-input" value="{{ $to }}">
            </div>
            <div class="ui-rota__form-actions">
                <x-ui.button type="submit" icon="refresh">{{ ___('Run') }}</x-ui.button>
                <x-ui.button variant="secondary" icon="download" :href="route('stores.rota.payroll.csv').'?store_id='.$rid.'&from='.$from.'&to='.$to">{{ ___('Export CSV') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Payroll')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ count($report['rows']) }}</x-ui.tag></x-slot:actions>
        @if(empty($report['rows']))
            <x-ui.empty :title="___('No staff in this period.')" icon="users" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Staff') }}</th><th>{{ ___('Role') }}</th>
                    <th class="ui-table__num">{{ ___('Scheduled h') }}</th><th class="ui-table__num">{{ ___('Worked h') }}</th>
                    <th class="ui-table__num">{{ ___('Rate') }}</th><th class="ui-table__num">{{ ___('Pay') }}</th><th class="ui-table__num">{{ ___('Tips') }}</th>
                </tr></thead>
                <tbody>
                @foreach($report['rows'] as $r)
                    <tr>
                        <td data-primary>{{ $r['name'] }}</td>
                        <td data-label="{{ ___('Role') }}"><span class="ui-rota__role">{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::teamRoleLabel($r['role']) }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Scheduled h') }}"><span class="ui-ltr">{{ $r['scheduled_hours'] }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Worked h') }}">
                            <span class="ui-rota__worked">
                                <span class="ui-ltr">{{ $r['worked_hours'] }}</span>
                                @if($r['open_clock_pairs'] > 0)
                                    <x-ui.pill tone="warning" title="{{ ___('Still clocked in') }}"><span class="ui-ltr">{{ $r['open_clock_pairs'] }}</span> {{ ___('open') }}</x-ui.pill>
                                @endif
                            </span>
                        </td>
                        <td class="ui-table__num" data-label="{{ ___('Rate') }}"><span class="ui-ltr">{{ number_format($r['rate'], 2) }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Pay') }}"><strong class="ui-ltr">{{ number_format($r['pay'], 2) }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('Tips') }}"><span class="ui-ltr">{{ number_format($r['tips'], 2) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
