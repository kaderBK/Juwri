{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    GOOGLE-PARTNER Lane B / B5 — GBP Performance widget (30-day headline metrics).
    SELF-CONTAINED: fetches its own cached data from GbpPerformanceService, so any
    page can drop it in with a single line:  @include('owner.gbp.partials.performance', ['rid' => $rid])
    Owned by Lane B. A7 may include the same line on the GBP index page.
--}}
@php
    $gbpPerf = app(\Juwri\Modules\Integrations\Services\Gbp\GbpPerformanceService::class)->monthlySummary((int) $rid);
@endphp

<x-ui.card :title="___('Google performance')" class="ui-gbp__perf">
    <x-slot:actions><x-ui.tag>{{ ___('last 30 days') }}</x-ui.tag></x-slot:actions>
    @if(empty($gbpPerf['available']))
        <p class="ui-gbp__text">{{ ___('Performance data will appear here once your Google Business Profile has activity.') }}</p>
    @else
        <div class="ui-gbp__stats">
            <x-ui.stat :value="number_format($gbpPerf['views'])" :label="___('Views')" icon="search" />
            <x-ui.stat :value="number_format($gbpPerf['calls'])" :label="___('Calls')" icon="phone" />
            <x-ui.stat :value="number_format($gbpPerf['directions'])" :label="___('Directions')" icon="pin" />
            <x-ui.stat :value="number_format($gbpPerf['website_clicks'])" :label="___('Website clicks')" icon="external" />
        </div>
    @endif
</x-ui.card>
