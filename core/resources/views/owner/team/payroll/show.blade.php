{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Pay run'))
@section('page_title', ___('Pay run'))
@section('page_sub', optional($run->period_start)->toDateString().' → '.optional($run->period_end)->toDateString())

@section('content')

@if(session('success'))<div class="ui-note" data-tone="success">{{ session('success') }}</div>@endif
@if(isset($errors) && $errors->any())<div class="ui-note" data-tone="danger">{{ $errors->first() }}</div>@endif

<div class="ui-card"><div class="ui-cluster ui-cluster--between ui-card__body">
    <div>
        <span class="ui-tag" data-tone="{{ ['draft'=>'neutral','pending_approval'=>'accent','approved'=>'success','paid'=>'success'][$run->status] ?? 'neutral' }}">{{ ___(ucfirst(str_replace('_',' ',$run->status))) }}</span>
        <strong>{{ ___('Total net') }}: {{ number_format((float)$run->total_net, 2) }}</strong>
    </div>
    <div>
        @if($run->status === 'draft')
            <form method="post" action="{{ route('stores.payroll.submit', $run->id) }}?store_id={{ $rid }}" class="ui-inline">@csrf<button class="ui-btn ui-btn--sm">{{ ___('Submit for approval') }}</button></form>
        @elseif($run->status === 'pending_approval')
            <form method="post" action="{{ route('stores.payroll.approve', $run->id) }}?store_id={{ $rid }}" class="ui-inline">@csrf<button class="ui-btn ui-btn--sm ui-btn--success">{{ ___('Approve') }}</button></form>
        @elseif($run->status === 'approved')
            <form method="post" action="{{ route('stores.payroll.markPaid', $run->id) }}?store_id={{ $rid }}" class="ui-inline">@csrf<button class="ui-btn ui-btn--sm ui-btn--dark">{{ ___('Mark paid') }}</button></form>
        @endif
        <a class="ui-btn ui-btn--sm ui-btn--secondary" href="{{ route('stores.payroll.bankFile', $run->id) }}?store_id={{ $rid }}">{{ ___('Bank file (CSV)') }}</a>
    </div>
</div></div>

@if(!$run->isLocked())
<div class="ui-card"><div class="ui-card__body">
    <h4 class="ui-section-title">{{ ___('Tip pooling') }}</h4>
    <form method="post" action="{{ route('stores.payroll.pool', $run->id) }}?store_id={{ $rid }}" class="ui-form-grid">
        @csrf
        <div><label class="ui-label ui-small">{{ ___('Method') }}</label>
            <select name="method" class="ui-input ui-input--sm">
                <option value="by_hours">{{ ___('By hours worked') }}</option>
                <option value="by_role_weight">{{ ___('By role weight') }}</option>
                <option value="custom_percent">{{ ___('Custom percent') }}</option>
            </select>
        </div>
        <div><button class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Pool & apply tips') }}</button></div>
    </form>
</div></div>
@endif

<div class="ui-card"><div class="ui-card__body">
    <div class="ui-table-wrap">
    <table class="ui-table">
        <thead><tr><th>{{ ___('Staff') }}</th><th class="ui-end">{{ ___('Worked') }}</th><th class="ui-end">{{ ___('Leave') }}</th><th class="ui-end">{{ ___('OT') }}</th><th class="ui-end">{{ ___('Base') }}</th><th class="ui-end">{{ ___('OT pay') }}</th><th class="ui-end">{{ ___('Tips') }}</th><th class="ui-end">{{ ___('Adj') }}</th><th class="ui-end">{{ ___('Net') }}</th><th></th></tr></thead>
        <tbody>
        @foreach($run->items as $item)
            <tr>
                <td data-label="{{ ___('Staff') }}">{{ optional($item->staff)->name }}</td>
                <td class="ui-end" data-label="{{ ___('Worked') }}">{{ number_format((float)$item->worked_hours, 1) }}</td>
                <td class="ui-end" data-label="{{ ___('Leave') }}">{{ number_format((float)$item->leave_hours, 1) }}</td>
                <td class="ui-end" data-label="{{ ___('OT') }}">{{ number_format((float)$item->overtime_hours, 1) }}</td>
                <td class="ui-end" data-label="{{ ___('Base') }}">{{ number_format((float)$item->base_pay, 2) }}</td>
                <td class="ui-end" data-label="{{ ___('OT pay') }}">{{ number_format((float)$item->overtime_pay, 2) }}</td>
                <td class="ui-end" data-label="{{ ___('Tips') }}">{{ number_format((float)$item->tips, 2) }}</td>
                <td class="ui-end" data-label="{{ ___('Adj') }}">{{ number_format((float)$item->bonuses - (float)$item->deductions, 2) }}</td>
                <td class="ui-end ui-strong" data-label="{{ ___('Net') }}">{{ number_format((float)$item->net, 2) }}</td>
                <td class="ui-end" data-label="">
                    <a class="ui-btn ui-btn--sm ui-btn--secondary" target="_blank" href="{{ route('stores.payroll.payslip', [$run->id, $item->id]) }}?store_id={{ $rid }}">{{ ___('Payslip') }}</a>
                    @if(!$run->isLocked())
                    <form method="post" action="{{ route('stores.payroll.adjust', $run->id) }}?store_id={{ $rid }}" class="ui-cluster">
                        @csrf
                        <input type="hidden" name="item_id" value="{{ $item->id }}">
                        <input name="label" class="ui-input ui-input--sm" placeholder="{{ ___('Reason') }}" style="width:90px">
                        <input name="amount" type="number" step="0.01" class="ui-input ui-input--sm" placeholder="±" style="width:70px">
                        <button class="ui-btn ui-btn--sm ui-btn--secondary">+</button>
                    </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div></div>

@endsection
