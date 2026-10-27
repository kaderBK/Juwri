{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Dispatch map'))
@section('page_title', ___('Dispatch map'))
@section('page_sub', ___('Live drivers and active deliveries.'))

@section('page_actions')
    <x-ui.button :href="route('stores.dispatch.report').'?store_id='.$rid" variant="secondary" size="sm" icon="chart">{{ ___('Delivery report') }}</x-ui.button>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('dispatch')])

<div class="ui-scope ui-dmap">
    <section class="ui-card ui-card--flush ui-dmap__stage">
        <div id="dmap" class="ui-dmap__canvas"></div>
    </section>
    <aside class="ui-dmap__side">
        <x-ui.card :title="___('Unassigned orders')">
            <x-slot:actions><x-ui.pill tone="success" :live="true">{{ ___('Live') }}</x-ui.pill></x-slot:actions>
            <div id="unassigned" class="ui-dmap__list" aria-live="polite">{{ ___('Loading…') }}</div>
        </x-ui.card>
        <x-ui.card :title="___('Drivers')">
            <div id="drivers" class="ui-dmap__list" aria-live="polite">{{ ___('Loading…') }}</div>
        </x-ui.card>
    </aside>
</div>

<script nonce="{{ csp_nonce() }}">
const FEED = '{{ route('stores.dispatch.map.feed') }}?store_id={{ $rid }}';
async function refresh() {
    try {
        const r = await fetch(FEED, { headers: { 'Accept':'application/json' } });
        const d = await r.json();
        document.getElementById('drivers').innerHTML = (d.drivers||[]).map(x =>
            `<div class="ui-dmap__driver"><span class="ui-dmap__dot${x.stale ? ' is-stale' : ''}" aria-hidden="true"></span><strong>${x.name}</strong> <span class="ui-dmap__gps ui-ltr">${x.lat ? '('+x.lat.toFixed(3)+', '+x.lng.toFixed(3)+')' : '{{ ___('no GPS') }}'}</span></div>`).join('') || '<p class="ui-dmap__none">{{ ___('No drivers.') }}</p>';
        document.getElementById('unassigned').innerHTML = (d.unassigned||[]).map(o => {
            const sug = (o.suggestions||[]).map(s => {
                const staleTag = s.stale ? ' <span class="ui-tag" data-tone="warning">{{ ___('GPS old') }}</span>' : '';
                return `<form method="post" action="{{ url('dispatch') }}/${o.id}/assign?store_id={{ $rid }}">@csrf<input type="hidden" name="driver_id" value="${s.driver_id}"><button class="ui-btn ui-btn--secondary ui-btn--sm">${s.name}${s.distance_km!=null?' <span class="ui-ltr">('+s.distance_km.toFixed(1)+'km)</span>':''}${staleTag}</button></form>`;
            }).join('');
            return `<div class="ui-dmap__order"><p class="ui-dmap__order-head"><strong class="ui-ltr">#${o.id}</strong> <span>${o.address||''}</span></p><div class="ui-dmap__sugs">${sug || '<span class="ui-dmap__none">{{ ___('no drivers') }}</span>'}</div></div>`;
        }).join('') || '<p class="ui-dmap__none">{{ ___('None.') }}</p>';
    } catch(e) {}
}
refresh();
setInterval(refresh, 15000);
</script>

@endsection
