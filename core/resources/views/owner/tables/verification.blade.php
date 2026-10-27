{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Table verification'))
@section('page_title', ___('Table verification'))
@section('page_sub', $store->title.' - '.___('Stop fake dine-in orders from off-premises scans.'))

@section('page_actions')
    <x-ui.button :href="route('stores.tables', $store->id)" variant="secondary" icon="qr">{{ ___('Tables') }}</x-ui.button>
    <x-ui.button :href="route('stores.tableVerify.tentCards', $store->id).($branchId ? '?branch='.$branchId : '')" target="_blank" variant="secondary" icon="print">{{ ___('Print tent cards') }}</x-ui.button>
@endsection

@section('content')
@php
    $modes = [
        'off' => [___('Off'), ___('Anyone who opens the menu can place a dine-in order. This is the default.')],
        'table_code' => [___('Table code'), ___('Each table gets a rotating 4-digit code. The first order of a visit must enter it.')],
        'sms_otp' => [___('SMS one-time code'), ___('The guest confirms a phone number by SMS before the first dine-in order.')],
        'staff_approve' => [___('Staff approval'), ___('Dine-in orders wait for a staff member to approve them before the kitchen sees them.')],
    ];
@endphp
<div class="ui-scope ui-stack ui-tverify">

    <x-ui.card :title="___('Verification mode')">
        <form action="{{ route('stores.tableVerify.mode', $store->id) }}" method="post" class="ui-stack">
            @csrf
            <fieldset class="ui-tverify__modes">
                <legend class="ui-visually-hidden">{{ ___('Verification mode') }}</legend>
                @foreach($modes as $value => [$label, $help])
                    <label @class(['ui-tverify__mode', 'is-current' => $mode === $value])>
                        <input type="radio" name="mode" value="{{ $value }}" @checked($mode === $value)>
                        <span>
                            <strong>{{ $label }}</strong>
                            <span class="ui-muted">{{ $help }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>

            @if($mode !== 'off' && $effectiveMode === 'off')
                <x-ui.banner tone="warning">
                    {{ ___('Table verification is not active on your current plan, so dine-in ordering is unchanged.') }}
                </x-ui.banner>
            @endif
            <div class="ui-actions">
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Table codes')" variant="flush">
        <x-slot:actions>
            <div class="ui-actions">
                @if($branches->count() > 1)
                    @foreach($branches as $b)
                        <a href="{{ route('stores.tableVerify.index', $store->id) }}?branch={{ $b->id }}"
                           class="ui-chip" @if((int) $branchId === (int) $b->id) aria-current="true" @endif>{{ $b->name }}</a>
                    @endforeach
                @endif
                <form action="{{ route('stores.tableVerify.rotateAll', $store->id) }}" method="post"
                      data-confirm="{{ ___('Generate a new code for every table? Guests who already verified will be asked again.') }}">
                    @csrf
                    <input type="hidden" name="branch" value="{{ $branchId }}">
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Regenerate all') }}</x-ui.button>
                </form>
            </div>
        </x-slot:actions>
        @if($tables->isEmpty())
            <x-ui.empty :title="___('No tables yet')" :body="___('Add tables to generate a unique QR code and link for each one.')" icon="qr" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Table') }}</th>
                    <th>{{ ___('Table code') }}</th>
                    <th class="ui-table__num">{{ ___('Actions') }}</th>
                </tr></thead>
                <tbody>
                    @foreach($tables as $table)
                        <tr>
                            <td data-label="{{ ___('Table') }}"><strong class="ui-ltr">#{{ $table->table_no }}</strong></td>
                            <td data-label="{{ ___('Table code') }}">
                                @if($table->verify_code)
                                    <code class="ui-tverify__code">{{ $table->verify_code }}</code>
                                @else
                                    <span class="ui-muted">{{ ___('Not set') }}</span>
                                @endif
                            </td>
                            <td class="ui-table__num" data-label="{{ ___('Actions') }}">
                                <form action="{{ route('stores.tableVerify.rotate', $store->id) }}" method="post">
                                    @csrf
                                    <input type="hidden" name="table_id" value="{{ $table->id }}">
                                    <x-ui.button type="submit" variant="ghost" size="sm" icon="refresh">{{ ___('Regenerate') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
