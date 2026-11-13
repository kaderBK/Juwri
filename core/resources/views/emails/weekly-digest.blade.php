{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $digestKpis = [
        [___('Total Scans'), number_format($totalScans)],
        [___('Unique Visitors'), number_format($uniqueVisitors)],
        [___('Total Revenue'), $currencySymbol.number_format($totalRevenue, $currencyDecimals ?? 2)],
        [___('Total Orders'), number_format($ordersCount)],
    ];
@endphp
<x-mail.frame :title="___('Your Weekly Analytics Digest')" :heading="___('Weekly Analytics Digest')" :preheader="___('Here is your restaurant\'s performance summary for the past week:')">
    <p style="margin:0 0 8px;">{{ ___('Hello') }} {{ $name }},</p>
    <p style="margin:0 0 16px;">{{ ___('Here is your restaurant\'s performance summary for the past week:') }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">
        @foreach (array_chunk($digestKpis, 2) as $kpiRow)
            <tr>
                @foreach ($kpiRow as [$kpiLabel, $kpiValue])
                    <td class="jm-cell" width="50%" style="padding:4px;">
                        <table role="presentation" class="jm-soft" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F7F6F2;border:1px solid #ECE9E2;border-radius:10px;">
                            <tr><td style="padding:14px 16px;">
                                <div class="jm-muted" style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#76767F;">{{ $kpiLabel }}</div>
                                <div class="jm-ink" style="font-size:22px;font-weight:700;color:#17171C;">{{ $kpiValue }}</div>
                            </td></tr>
                        </table>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <p style="margin:12px 0 0;">{{ ___('Conversion Rate (Scans to Orders):') }} <strong>{{ $conversionRate }}%</strong></p>
    <x-mail.button :href="route('analytics.index')">{{ ___('View Full Dashboard') }}</x-mail.button>

    <x-slot:footer>
        {{ ___('You received this email because you opted in to weekly analytics digests.') }}<br>
        &copy; {{ date('Y') }} {{ config('settings.site_title') }}.
    </x-slot:footer>
</x-mail.frame>
