{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $slipRows = [
        [___('Worked hours'), number_format((float) $item->worked_hours, 2)],
        [___('Paid leave hours'), number_format((float) $item->leave_hours, 2)],
        [___('Overtime hours'), number_format((float) $item->overtime_hours, 2)],
        [___('Base pay'), number_format((float) $item->base_pay, 2)],
        [___('Overtime pay'), number_format((float) $item->overtime_pay, 2)],
        [___('Tips'), number_format((float) $item->tips, 2)],
        [___('Bonuses'), number_format((float) $item->bonuses, 2)],
        [___('Deductions'), '-'.number_format((float) $item->deductions, 2)],
    ];
@endphp
<x-ui.print :title="___('Payslip').' — '.optional($item->staff)->name">
    <h1 class="ui-print__title">{{ ___('Payslip') }}</h1>
    <p class="ui-print__sub">{{ optional($item->staff)->name }} · <span class="ui-print__ltr">{{ optional($run->period_start)->toDateString() }} → {{ optional($run->period_end)->toDateString() }}</span></p>
    <table class="ui-print__table">
        <tbody>
            @foreach ($slipRows as [$slipLabel, $slipValue])
                <tr><td>{{ $slipLabel }}</td><td class="is-num">{{ $slipValue }}</td></tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="is-total"><td>{{ ___('Net pay') }}</td><td class="is-num">{{ number_format((float) $item->net, 2) }}</td></tr>
        </tfoot>
    </table>
</x-ui.print>
