{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.card :title="___('Minutes used this month')" variant="flush">
@forelse($minutesUsage as $row)
    @if($loop->first)
    <table class="ui-table">
        <thead>
            <tr>
                <th>{{ ___('Location') }}</th>
                <th class="ui-table__num">{{ ___('Minutes used') }}</th>
                <th class="ui-table__num">{{ ___('Included') }}</th>
                <th class="ui-table__num">{{ ___('Estimated overage') }}</th>
            </tr>
        </thead>
        <tbody>
    @endif
        <tr>
            <td data-primary>{{ $row['title'] }}</td>
            <td class="ui-table__num" data-label="{{ ___('Minutes used') }}"><span class="ui-ltr">{{ $row['usage']['minutes'] }} / {{ $row['usage']['included'] }}</span></td>
            <td class="ui-table__num" data-label="{{ ___('Included') }}"><span class="ui-ltr">{{ $row['usage']['included'] }}</span></td>
            <td class="ui-table__num" data-label="{{ ___('Estimated overage') }}"><span class="ui-ltr">{{ number_format((float) $row['usage']['overage_charge'], 2) }} {{ $row['usage']['currency'] }}</span> <span class="ui-channels__muted">({{ number_format((int) $row['usage']['overage_minutes']) }} {{ ___('min') }})</span></td>
        </tr>
    @if($loop->last)
        </tbody>
    </table>
    <p class="ui-channels__muted">{{ ___('Estimated overage is shown at the current per-minute rate and is not an invoice.') }}</p>
    @endif
@empty
    <x-ui.empty icon="phone" :title="___('No usage yet this month.')" />
@endforelse
</x-ui.card>
