{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Daily Scan Alert')" :heading="___('Daily Scan Alert')">
    <p style="margin:0 0 12px;">{{ ___('Hi :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 16px;">
        {{ ___('Today scans are down :drop% compared with your last :days-day average.', ['drop' => number_format($dropPercent, 1), 'days' => $comparisonWindow]) }}
    </p>
    <table role="presentation" class="jm-soft" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:10px;margin:0 0 16px;">
        <tr><td style="padding:14px 18px;">
            <p style="margin:0 0 6px;"><strong>{{ ___('Today') }}:</strong> {{ number_format($currentScans) }} {{ ___('scans') }}</p>
            <p style="margin:0;"><strong>{{ ___('Baseline average') }}:</strong> {{ number_format($baselineAverageScans, 1) }} {{ ___('scans per day') }}</p>
        </td></tr>
    </table>
    <p style="margin:0 0 16px;">
        {{ ___('This alert watches total scans across your :count restaurant account(s) and only fires once per day when the drop is 40% or more.', ['count' => $restaurantCount]) }}
    </p>

    <x-slot:footer>
        {{ ___('You can disable anomaly alerts any time from your billing settings.') }}
    </x-slot:footer>
</x-mail.frame>
