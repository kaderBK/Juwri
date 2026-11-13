{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Kitchen ticket (KOT) for a thermal printer: big item lines, no prices. --}}
<x-ui.print :title="___('Kitchen ticket').' #'.$order->id" paper="receipt" autoprint>
    <div class="ui-print__center ui-print__huge">#{{ $order->id }} · {{ mb_strtoupper(order_type_label($order->type)) }}</div>
    @if (!empty($station))<div class="ui-print__center ui-print__strong">{{ $station }}</div>@endif
    {{-- PARITY-RUN T4: on a merged party the runner needs every table, not just
         the one holding the bill — "T5 + T7", falling back to the plain number. --}}
    @php $__party = \Juwri\Modules\Tables\Services\TableMergeService::partyLabelForOrder($order); @endphp
    @if ($__party)<div class="ui-print__center ui-print__strong">{{ ___('Table') }} {{ $__party }}</div>
    @elseif ($order->table_number)<div class="ui-print__center">{{ ___('Table') }} {{ $order->table_number }}</div>@endif
    <div class="ui-print__center">{{ optional($order->created_at)->format('H:i') }}</div>
    <hr class="ui-print__rule">
    @foreach ($order->items as $it)
        <div class="ui-print__strong ui-print__big">{{ $it->quantity }}× {{ optional($it->menu)->getRawOriginal('name') ?? '—' }}</div>
        @if ($it->variation && $it->variant)<div class="ui-print__indent">» {{ $it->variant->name ?? '' }}</div>@endif
        {{-- T6-4: choice-group selections — the most important info on a kitchen ticket --}}
        @foreach ((array) $it->options as $g)
            <div class="ui-print__indent ui-print__strong">» {{ $g['title'] ?? '' }}: {{ collect($g['selected'] ?? [])->pluck('option')->join(', ') }}</div>
        @endforeach
        @foreach ($it->itemExtras as $ex)
            @if ($ex->extra)<div class="ui-print__indent">+ {{ $ex->extra->getRawOriginal('title') }}</div>@endif
        @endforeach
        @if ($it->order_note)<div class="ui-print__indent">★ {{ $it->order_note }}</div>@endif
    @endforeach
</x-ui.print>
