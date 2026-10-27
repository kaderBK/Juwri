{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Review translations'))
@section('page_title', ___('Review translations'))
@section('page_sub', ___('Edit and approve machine translations. Approved edits are remembered.'))

@section('page_actions')
    <x-ui.button :href="route('stores.menu', $store->id)" variant="secondary" icon="back">{{ ___('Back to menu') }}</x-ui.button>
    <x-ui.button type="button" icon="check" data-ui-call="approveAllTr">{{ ___('Approve all') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-tr">
    @php
        $tone = ['missing' => 'neutral', 'machine' => 'accent', 'stale' => 'warning', 'lowconf' => 'danger', 'approved' => 'success'];
        $labelize = ['missing' => ___('Missing'), 'machine' => ___('AI'), 'stale' => ___('Outdated'), 'lowconf' => ___('Low confidence'), 'approved' => ___('Approved')];
    @endphp

    @forelse($rows as $row)
        <section class="ui-card ui-tr__card">
            <header class="ui-tr__head">
                <h3 class="ui-tr__title">{{ $row['source']['title'] }}</h3>
                <x-ui.tag>{{ $row['type'] === 'item' ? ___('Item') : ___('Category') }}</x-ui.tag>
            </header>
            @if(!empty($row['source']['description']))
                <p class="ui-tr__source">{{ \Illuminate\Support\Str::limit($row['source']['description'], 140) }}</p>
            @endif
            @foreach($targets as $code)
                @php $l = $row['langs'][$code]; @endphp
                <div class="tr-block ui-tr__block" data-type="{{ $row['type'] }}" data-id="{{ $row['id'] }}" data-lang="{{ $code }}">
                    <div class="ui-tr__meta">
                        <strong class="ui-tr__lang">{{ strtoupper($code) }}</strong>
                        <x-ui.pill :tone="$tone[$l['state']] ?? 'neutral'">{{ $labelize[$l['state']] ?? $l['state'] }}</x-ui.pill>
                        <span class="tr-msg ui-tr__msg" role="status"></span>
                    </div>
                    <input class="tr-title ui-input" value="{{ $l['title'] }}" placeholder="{{ ___('Translation') }}" aria-label="{{ ___('Translation') }} ({{ strtoupper($code) }})" lang="{{ $code }}">
                    @if($row['type'] === 'item')
                        <input class="tr-desc ui-input" value="{{ $l['description'] }}" placeholder="{{ ___('Description translation') }}" aria-label="{{ ___('Description translation') }} ({{ strtoupper($code) }})" lang="{{ $code }}">
                    @endif
                    <div>
                        <x-ui.button type="button" size="sm" variant="secondary" icon="check" data-ui-call="saveTr">{{ ___('Save & approve') }}</x-ui.button>
                    </div>
                </div>
            @endforeach
        </section>
    @empty
        <div class="ui-card"><x-ui.empty :title="___('Nothing to review')" :body="___('All translations are approved, or there are no extra languages yet.')" icon="check" /></div>
    @endforelse
</div>

<script nonce="{{ csp_nonce() }}">
    const TR_SAVE = @js(route('stores.translations.save', $store->id));
    const TR_APPROVE = @js(route('stores.translations.approve', $store->id));
    const TR_TOKEN = @js(csrf_token());

    function saveTr(btn) {
        const b = btn.closest('.tr-block');
        const descEl = b.querySelector('.tr-desc');
        const payload = {
            type: b.dataset.type, id: b.dataset.id, lang: b.dataset.lang,
            title: b.querySelector('.tr-title').value,
            description: descEl ? descEl.value : ''
        };
        const msg = b.querySelector('.tr-msg');
        fetch(TR_SAVE, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TR_TOKEN, 'Accept': 'application/json' }, body: JSON.stringify(payload) })
            .then(r => r.json()).then(d => { msg.textContent = d.success ? @js(___('Saved ✓')) : (d.error || 'Error'); })
            .catch(() => { msg.textContent = 'Error'; });
    }

    async function approveAllTr() {
        if (!(await window.dashConfirm(@js(___('Approve all AI translations as-is?'))))) return;
        fetch(TR_APPROVE, { method: 'POST', headers: { 'X-CSRF-TOKEN': TR_TOKEN, 'Accept': 'application/json' } })
            .then(r => r.json()).then(d => {
                window.dashToast((d.approved || 0) + ' ' + @js(___('approved')), { type: 'success' });
                setTimeout(function () { location.reload(); }, 700);
            });
    }
</script>
@endsection
