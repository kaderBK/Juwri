{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Room QR sheet: one QR per room, three per row. --}}
<x-ui.print :title="___('Room QR sheet').' · '.$store->title">
    <h1 class="ui-print__title">{{ $store->title }} — {{ ___('Room QR codes') }}</h1>
    @if ($rooms->isEmpty())
        <p>{{ ___('No rooms yet.') }}</p>
    @else
        <div class="ui-print__grid">
            @foreach ($rooms as $room)
                <div class="ui-print__cell">
                    <img src="{{ route('stores.qrSvg', $store->id).'?table='.$room->key }}" alt="QR #{{ $room->table_no }}">
                    <div class="ui-print__huge">#{{ $room->table_no }}</div>
                    @if ($room->label)<div>{{ $room->label }}</div>@endif
                    @if ($room->floor_label)<div class="ui-print__meta">{{ $room->floor_label }}</div>@endif
                </div>
            @endforeach
        </div>
    @endif
</x-ui.print>
