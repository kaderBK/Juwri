{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $statusLabels = [
        'pending_payment' => ___('Waiting for deposit'), 'confirmed' => ___('Confirmed'), 'pending_approval' => ___('Pending approval'),
        'cancelled' => ___('Cancelled'), 'completed' => ___('Done'), 'no_show' => ___('No-show'),
    ];
    $depositLabels = [
        'pending' => ___('Deposit due'), 'paid' => ___('Deposit paid'), 'refunded' => ___('Deposit refunded'),
        'kept' => ___('Deposit kept'), 'received_late' => ___('Paid late'),
    ];
    $tones = ['confirmed' => 'success', 'pending_payment' => 'warning', 'pending_approval' => 'warning', 'cancelled' => 'neutral', 'completed' => 'accent', 'no_show' => 'danger'];
@endphp
<table class="ui-table">
    <thead><tr>
        <th>{{ ___('When') }}</th><th>{{ ___('Customer') }}</th><th>{{ ___('Services') }}</th><th>{{ ___('Staff') }}</th>
        <th>{{ ___('Status') }}</th><th class="ui-table__num">{{ ___('Total') }}</th>
        @if($actions)<th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>@endif
    </tr></thead>
    <tbody>
    @foreach($rows as $r)
        <tr data-booking="{{ $r['number'] }}">
            <td data-primary><span class="ui-ltr">{{ $r['when'] }}</span><br><span class="ui-muted"><bdi>{{ $r['number'] }}</bdi></span></td>
            <td data-label="{{ ___('Customer') }}">{{ $r['customer'] }}@if($r['phone'])<br><span class="ui-ltr ui-muted">{{ $r['phone'] }}</span>@endif
                @if($r['location'] === 'at_home')<br><x-ui.pill tone="accent">{{ ___('At home') }}</x-ui.pill> {{ $r['address'] }}@endif
                @if($r['note'])<br><span class="ui-muted">{{ $r['note'] }}</span>@endif</td>
            <td data-label="{{ ___('Services') }}">{{ $r['services'] }}</td>
            <td data-label="{{ ___('Staff') }}">{{ $r['staff'] }}</td>
            <td data-label="{{ ___('Status') }}">
                <x-ui.tag :tone="$tones[$r['status']] ?? 'neutral'">{{ $statusLabels[$r['status']] ?? $r['status'] }}</x-ui.tag>
                @if(isset($depositLabels[$r['deposit_status']]))<br><span class="ui-muted">{{ $depositLabels[$r['deposit_status']] }} · {{ $r['deposit'] }}</span>@endif
                @if(! empty($r['refund_error']))<br><x-ui.tag tone="danger">{{ ___('Refund refused') }}</x-ui.tag> <span class="ui-muted">{{ $r['refund_error'] }}</span>@endif
                @if($r['no_show_fee'])<br><span class="ui-muted">{{ ___('No-show fee to collect') }}: {{ $r['no_show_fee'] }}</span>@endif
            </td>
            <td class="ui-table__num" data-label="{{ ___('Total') }}">{{ $r['total'] }}</td>
            @if($actions)
                <td class="ui-catalog__row-actions">
                    @foreach($actions as $do => $label)
                        @continue(in_array($do, ['complete', 'no_show'], true) && $r['status'] !== 'confirmed')
                        @continue($do === 'cancel' && ! in_array($r['status'], ['confirmed', 'pending_payment'], true))
                        <form method="post" action="{{ route('bookings.owner.action', ['booking' => $r['id'], 'store_id' => $store->id]) }}">
                            @csrf
                            <input type="hidden" name="do" value="{{ $do }}">
                            <x-ui.button type="submit" :variant="$do === 'cancel' ? 'secondary' : 'primary'" size="sm">{{ $label }}</x-ui.button>
                        </form>
                    @endforeach
                </td>
            @endif
        </tr>
    @endforeach
    </tbody>
</table>
