{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Kitchen Display'))
@section('page_title', ___('Kitchen Display'))
@section('page_sub', ___('Live open orders. Tap to advance status.'))

@section('page_actions')
    {{-- P1-3: write-through store selector — sets the shared session context so the
         page and the sidebar/topbar switchers can never disagree. --}}
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('kds')])

{{-- Alpine 3 calls the component's init() itself; a second call from x-init ran it twice (double polling / listeners; the customizer's second load() overwrote edits made meanwhile). --}}
<div x-data="kdsBoard({{ (int) $activeId }})" class="ui-scope ui-kds">
    {{-- Live summary: open tickets + per-state counts (owner UI kit). --}}
    <section class="ui-kds__summary" aria-live="polite">
        <div class="ui-kds__lead">
            <p class="ui-overline">{{ ___('Kitchen') }}</p>
            <h2 class="ui-kds__title" x-text="orders.length + ' ' + @js(___('open tickets'))">{{ ___('open tickets') }}</h2>
            <span class="ui-orders-muted">{{ ___('Polls every 6 seconds. Tap card to advance status.') }}</span>
        </div>
        <div class="ui-kds__counts">
            <div class="ui-stat" data-tone="warning"><span class="ui-stat__value" x-text="counts.new">0</span><span class="ui-stat__label">{{ ___('New') }}</span></div>
            <div class="ui-stat"><span class="ui-stat__value" x-text="counts.preparing">0</span><span class="ui-stat__label">{{ ___('Preparing') }}</span></div>
        </div>
    </section>

    {{-- T1: upcoming scheduled (pre-)orders not yet released to the kitchen --}}
    <section class="ui-card ui-card--flush ui-kds__scheduled" x-show="scheduled.length" x-cloak>
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Scheduled orders') }} <span class="ui-count" data-tone="warning" x-text="scheduled.length"></span></h2>
            <small class="ui-orders-muted">{{ ___('Released to the kitchen automatically at fire time.') }}</small>
        </div>
        <ul class="ui-kds__sched-list">
            <template x-for="s in scheduled" :key="s.id">
                <li>
                    <span>
                        <strong class="ui-ltr" x-text="'#'+s.id"></strong>
                        <span class="ui-orders-muted" x-text="(s.type === 'room_service' ? @js(___('Room'))+' '+(s.service_location || s.table_number) : (s.table_number ? @js(___('Table'))+' '+s.table_number : (s.customer_name || @js(___('Walk-in'))))) + ' · ' + s.item_count + '×'"></span>
                    </span>
                    <span class="ui-kds__sched-actions">
                        <span class="ui-orders-muted" x-text="@js(___('for'))+' '+fmt(s.scheduled_at)"></span>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="release(s.id)">{{ ___('Release now') }}</button>
                    </span>
                </li>
            </template>
        </ul>
    </section>

    <div class="ui-kds__grid" x-show="orders.length">
        <template x-for="o in orders" :key="o.id">
            <article class="ui-ticket" :data-status="o.status" :data-rush="o.is_rush ? '' : null" :data-late="isLate(o) ? '' : null">
                <header class="ui-ticket__head">
                    <span class="ui-ticket__id">
                        <strong class="ui-ltr" x-text="'#'+o.id"></strong>
                        {{-- D5: rush chip — a prioritised ticket sits first and is flagged red. --}}
                        <span class="ui-tag" data-tone="danger" x-show="o.is_rush" x-cloak>{{ ___('RUSH') }}</span>
                        {{-- P6-09 V-15: phone order, paid by link — only paid phone orders reach the kitchen. --}}
                        <span class="ui-tag" data-tone="success" x-show="o.phone_paid" x-cloak>{{ ___('Phone · paid') }}</span>
                        {{-- PARITY-RUN T2: the number the customer is watching for on the board. --}}
                        <span class="ui-tag" data-tone="accent" x-show="o.token_number" x-cloak x-text="@js(___('Number')) + ' ' + o.token_number"></span>
                    </span>
                    {{-- PARITY-RUN T4: a merged party names all of its tables.
                         C7: a room_service order names its room ("Room 204" / the
                         room label), never the ambiguous "Table N". --}}
                    <span class="ui-ticket__where" x-text="o.type === 'room_service' ? @js(___('Room'))+' '+(o.service_location || o.table_number) : (o.party_label ? @js(___('Table'))+' '+o.party_label : (o.table_number ? @js(___('Table'))+' '+o.table_number : (o.customer_name || @js(___('Walk-in')))))"></span>
                </header>
                <div class="ui-ticket__extra" x-show="o.voice_note_url">
                    <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--block" @click="playVoice(o.voice_note_url)">
                        <span class="ui-btn__icon"><x-ui.icon name="mic" /></span>
                        <span x-text="@js(___('Play voice note')) + (o.voice_note_duration ? ' · ' + o.voice_note_duration + 's' : '')"></span>
                    </button>
                    {{-- ZERO-TOUCH Track B3: transcript text under the play button. --}}
                    <p class="ui-ticket__note" x-show="o.voice_note_transcript" x-cloak
                       x-text="'📝 ' + (o.voice_note_transcript || '')" :title="@js(___('Voice note transcript'))"></p>
                </div>
                <div class="ui-ticket__extra" x-show="o.is_curbside">
                    <span class="ui-tag" data-tone="accent" x-text="o.arrived ? @js(___('Curbside · ARRIVED')) : @js(___('Curbside'))"></span>
                    <small class="ui-orders-muted" x-show="o.vehicle" x-text="o.vehicle"></small>
                </div>
                <ul class="ui-ticket__items">
                    <template x-for="item in o.items" :key="item.id">
                        <li>
                            <span class="ui-ticket__qty ui-ltr" x-text="item.qty + '×'"></span>
                            <span class="ui-ticket__item">
                                <strong x-text="item.name"></strong>
                                <small x-show="item.variant" x-text="item.variant"></small>
                                {{-- T6-4: choice-group selections (important on the line) --}}
                                <template x-for="opt in (item.options || [])" :key="opt">
                                    <small class="ui-ticket__opt" x-text="'» ' + opt"></small>
                                </template>
                                <em x-show="item.note" x-text="item.note"></em>
                            </span>
                        </li>
                    </template>
                </ul>
                <footer class="ui-ticket__foot">
                    {{-- ZERO-TOUCH Track C: elapsed + optional "~p50m" store target hint --}}
                    <span class="ui-ticket__time"><x-ui.icon name="clock" size="16" /><span class="ui-ltr" x-text="elapsed(o.created_at) + (o.prep_target_min ? ' · ~' + o.prep_target_min + 'm' : '')"></span></span>
                    <button type="button" class="ui-btn" :class="o.status === 'new' ? '' : 'ui-btn--success'" @click="bump(o.id)">
                        <span x-text="o.status === 'new' ? @js(___('Start')) : @js(___('Ready'))"></span>
                    </button>
                </footer>
            </article>
        </template>
    </div>

    <div class="ui-card" x-show="!orders.length">
        <x-ui.empty :title="___('No open tickets. New orders appear here automatically.')" icon="kitchen" />
    </div>
</div>
@endsection

@push('scripts_at_bottom')
@include('partials.live-updates')
<script nonce="{{ csp_nonce() }}">
    function kdsBoard(rid) {
        return {
            orders: [],
            scheduled: [],
            counts: { new: 0, preparing: 0 },
            storeId: rid,
            _cursor: Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 8), // P6-58 delta-poll token
            _polls: 0,
            _seenIds: null,   // ticket ids seen so far (null = baseline not set → no ring on first poll)
            init() {
                if (window.NotifySounds) NotifySounds.init(@js(notification_sounds_config(function_exists('dashboard_current_store') ? (dashboard_current_store()?->id) : null, function_exists('dashboard_current_branch') ? (dashboard_current_branch()?->id) : null)));
                this.poll();
                this.pollScheduled();
                setInterval(() => this.poll(), 6000);
                setInterval(() => this.pollScheduled(), 30000);
                // Live updates (Reverb, only when switched on): a ticket change refreshes the board right away;
                // the 6s poll above stays as the fallback if the socket is off or drops.
                if (window.JuwriLiveWhenReady && this.storeId) {
                    JuwriLiveWhenReady(() => JuwriLive.listen(this.storeId, 'kds', 'kds.changed', () => this.poll(), {{ \Juwri\Core\Broadcasting\LiveUpdates::branchScope() ?? 'null' }}));
                }
            },
            async pollScheduled() {
                try {
                    const res = await fetch(@json(route('kds.scheduled')) + '?store_id=' + this.storeId, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                    });
                    if (window.LivePoll && !LivePoll.guard(res)) return;
                    if (!res.ok) return; // 403/429/500 → keep the last good list, never poison x-for
                    const data = await res.json();
                    // x-for :key="s.id" requires an array of rows; an error payload
                    // ({message,exception,…}) would iterate object keys with undefined
                    // ids and crash Alpine's reorder. Only ever assign an array.
                    this.scheduled = Array.isArray(data) ? data : [];
                } catch (e) {}
            },
            async release(id) {
                try {
                    await fetch(@json(url('kds')) + '/' + id + '/release', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content, 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    this.pollScheduled();
                    this.poll();
                } catch (e) {}
            },
            fmt(ts) {
                if (!ts) return '';
                try { return new Date(ts).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }); } catch (e) { return ts; }
            },
            // FEATURES-5 Track C: play a customer voice note (authorized stream).
            _voiceAudio: null,
            playVoice(url) {
                if (!url) return;
                if (!this._voiceAudio) this._voiceAudio = new Audio();
                this._voiceAudio.src = url;
                this._voiceAudio.play().catch(() => {});
            },
            async poll() {
                try {
                    // P6-58: every 10th poll is a full resync (no cursor); the rest ask for changes only.
                    // A fresh token has no stored state server-side, so that poll returns the full list.
                    if ((this._polls++ % 10) === 0) { this._cursor = Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 8); }
                    const res = await fetch(@json(route('kds.poll')) + '?store_id=' + this.storeId + '&cursor=' + this._cursor, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                    });
                    // Session expired → stop the 6s loop + reload to login (LivePoll in the kit shell scripts).
                    if (window.LivePoll && !LivePoll.guard(res)) return;
                    let data = await res.json();
                    if (data && data.delta) {
                        const byId = new Map(this.orders.map(o => [o.id, o]));
                        for (const o of data.orders) byId.set(o.id, o);
                        data = data.order.map(id => byId.get(id)).filter(Boolean);
                    } else if (!Array.isArray(data)) {
                        return;
                    }
                    // Ring on a genuinely NEW ticket (id not seen before). First poll
                    // sets the baseline silently so a page open doesn't blast the kitchen.
                    const ids = new Set(data.map(o => o.id));
                    if (this._seenIds && window.NotifySounds) {
                        for (const id of ids) { if (!this._seenIds.has(id)) { NotifySounds.play('order_new'); break; } }
                    }
                    this._seenIds = ids;
                    this.orders = data;
                    this.counts.new = data.filter(o => o.status === 'new').length;
                    this.counts.preparing = data.filter(o => o.status === 'preparing').length;
                } catch (e) {}
            },
            async bump(id) {
                try {
                    await fetch(@json(url('kds')) + '/' + id + '/bump', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content, 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (window.NotifySounds) NotifySounds.play('order_ready'); // cook feedback: item done
                    this.poll();
                } catch (e) {}
            },
            // Past the store's prep target (or 20 min without one): flag the ticket.
            isLate(o) {
                if (!o || !o.created_at) return false;
                const m = (Date.now() - new Date(o.created_at).getTime()) / 60000;
                return m > (o.prep_target_min || 20);
            },
            elapsed(ts) {
                if (!ts) return '';
                const m = Math.round((Date.now() - new Date(ts).getTime()) / 60000);
                return m + 'm';
            },
        };
    }
</script>
@endpush
