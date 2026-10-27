{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PARITY-RUN T3: printable A6 tent cards — QR + the table's current verification
     code, one card per table, four per A4 sheet. The QR image is the store's
     server-rendered restaurants.qrSvg endpoint (same as every other QR). --}}
<x-ui.print :title="___('Table tent cards').' · '.$store->title" paper="a6">
    <div class="ui-print__screen">
        <h1 class="ui-print__title">{{ $store->title }} — {{ ___('Table tent cards') }}</h1>
        <p class="ui-print__sub">{{ ___('Four cards per A4 sheet. Cut along the dashed lines.') }}</p>
    </div>

    @if ($tables->isEmpty())
        <p>{{ ___('No tables yet') }}</p>
    @else
        <div class="ui-print__grid ui-print__grid--2">
            @foreach ($tables as $table)
                <div class="ui-print__cell ui-print__cell--cut">
                    <div class="ui-print__strong">{{ $store->title }}</div>
                    <img src="{{ route('stores.qrSvg', $store->id).'?table='.$table->key }}" alt="{{ ___('Table') }} {{ $table->table_no }}">
                    <div class="ui-print__huge">{{ ___('Table') }} {{ $table->table_no }}</div>
                    @if ($mode === \Juwri\Modules\Tables\Services\TableSessionService::MODE_TABLE_CODE && $table->verify_code)
                        <div class="ui-print__label">{{ ___('Table code') }}</div>
                        <div class="ui-print__code">{{ $table->verify_code }}</div>
                        <div class="ui-print__note">{{ ___('Enter the code shown on your table.') }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-ui.print>
