{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Service requests'))
@section('page_title', ___('Service requests'))
@section('page_sub', $store->title.' - '.___('Housekeeping, maintenance and guest calls board.'))

@section('page_actions')
    <x-ui.button type="button" icon="plus" x-data @click="$dispatch('req-add')">{{ ___('New request') }}</x-ui.button>
@endsection

@php
    $cols = ['open' => ___('Open'), 'in_progress' => ___('In progress'), 'done' => ___('Done')];
    $typeLabels = ['housekeeping'=>___('Housekeeping'),'maintenance'=>___('Maintenance'),'amenity'=>___('Amenity'),'staff_call'=>___('Staff call'),'custom'=>___('Custom')];
@endphp

@section('content')
{{-- HP-B5: KDS-idiom polling board — auto-refreshes every 6s, rings on a genuinely
     new open request (baseline set silently on first poll), shows card age +
     assignee, and offers assign / cancel. Server-rendered columns are the initial
     paint + no-JS fallback; the poll then drives them. --}}
{{-- Alpine 3 calls the component's init() itself; a second call from x-init ran it twice (double polling / listeners; the customizer's second load() overwrote edits made meanwhile). --}}
<div class="ui-stack" x-data="hotelRequestsBoard({{ $store->id }})"
     @req-add.window="document.getElementById('reqAddDlg').showModal()">
    <div class="ui-form-grid">
        @foreach($cols as $key => $title)
            <div>
                <div class="ui-card ui-fill">
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <h4 class="ui-section-title">{{ $title }}</h4>
                        <span class="ui-tag" data-tone="neutral" x-text="cols.{{ $key }}.length">{{ $columns[$key]->count() }}</span>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <template x-for="req in cols.{{ $key }}" :key="req.id">
                            <div class="ui-bordered">
                                <div class="ui-cluster ui-cluster--between ui-cluster--top">
                                    <div>
                                        <strong x-text="typeLabel(req.type)"></strong>
                                        <div class="ui-muted" x-text="req.room || @js(___('No room'))"></div>
                                        <div class="ui-muted" x-show="req.department"><span>{{ ___('Dept') }}:</span> <span x-text="req.department"></span></div>
                                        <div class="ui-small" x-show="req.note" x-text="req.note"></div>
                                        <div class="ui-muted">
                                            <span x-text="age(req.created_at)"></span>
                                            <template x-if="req.assignee"><span> · {{ ___('Assigned') }}: <span x-text="req.assignee"></span></span></template>
                                        </div>
                                    </div>
                                </div>
                                <div class="ui-cluster">
                                    <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" x-show="req.status !== 'open'" @click="setStatus(req.id, 'open')">{{ ___('Open') }}</button>
                                    <button type="button" class="ui-btn ui-btn--sm" x-show="req.status !== 'in_progress'" @click="setStatus(req.id, 'in_progress')">{{ ___('Start') }}</button>
                                    <button type="button" class="ui-btn ui-btn--sm ui-btn--success" x-show="req.status !== 'done'" @click="setStatus(req.id, 'done')">{{ ___('Done') }}</button>
                                    <button type="button" class="ui-btn ui-btn--sm ui-btn--danger" @click="cancel(req.id)">{{ ___('Cancel') }}</button>
                                    <template x-if="staff.length">
                                        <select class="ui-input ui-input--sm ui-w-auto" @change="assign(req.id, $event.target.value)">
                                            <option value="">{{ ___('Unassigned') }}</option>
                                            <template x-for="s in staff" :key="s.id">
                                                <option :value="s.id" :selected="req.assigned_to === s.id" x-text="s.name"></option>
                                            </template>
                                        </select>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <p class="ui-muted" x-show="!cols.{{ $key }}.length">{{ ___('Nothing here.') }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <dialog id="reqAddDlg" class="dialog" style="max-width:460px;">
        <form action="{{ route('hotel.requests.store') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('New request') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-ui-do="close-dialog" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Room / area') }}</label>
                    <select name="table_id" class="ui-input">
                        <option value="">{{ ___('None') }}</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}">#{{ $room->table_no }}@if($room->label) · {{ $room->label }}@endif</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Type') }}</label>
                    <select name="type" class="ui-input">
                        @foreach($typeLabels as $val => $lbl)<option value="{{ $val }}">{{ $lbl }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Department') }}</label>
                    <select name="department_id" class="ui-input">
                        <option value="">{{ ___('Auto (route by type)') }}</option>
                        @foreach($departments as $dept)<option value="{{ $dept->id }}">{{ $dept->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Note') }}</label>
                    <textarea name="note" class="ui-input" rows="2"></textarea>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" data-ui-do="close-dialog">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Create') }}</x-ui.button>
            </div>
        </form>
    </dialog>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function hotelRequestsBoard(rid) {
        return {
            storeId: rid,
            cols: @js($initial),
            staff: @js(\Juwri\Core\Support\Views\OwnerOpsViewHelpers::staffAsIdNameList($staff)),
            labels: @js($typeLabels),
            _seenIds: null, // null = baseline not set → no ring on first poll
            init() {
                if (window.NotifySounds) NotifySounds.init(@js(notification_sounds_config(function_exists('dashboard_current_store') ? (dashboard_current_store()?->id) : null, function_exists('dashboard_current_branch') ? (dashboard_current_branch()?->id) : null)));
                this.baseline();
                this.poll();
                setInterval(() => this.poll(), 6000);
            },
            typeLabel(t) { return this.labels[t] || t; },
            baseline() {
                // Seed seen-ids from the server-rendered open/in_progress so a page
                // open never rings for requests that were already on the board.
                const ids = new Set();
                for (const r of this.cols.open) ids.add(r.id);
                for (const r of this.cols.in_progress) ids.add(r.id);
                this._seenIds = ids;
            },
            age(ts) {
                if (!ts) return '';
                const then = new Date(ts).getTime();
                if (isNaN(then)) return '';
                let s = Math.max(0, Math.floor((Date.now() - then) / 1000));
                if (s < 60) return @js(___('just now'));
                const m = Math.floor(s / 60);
                if (m < 60) return m + @js(' '.___('min'));
                const h = Math.floor(m / 60);
                if (h < 24) return h + @js(' '.___('h'));
                return Math.floor(h / 24) + @js(' '.___('d'));
            },
            async poll() {
                try {
                    const res = await fetch(@js(route('hotel.requests')) + '?store_id=' + this.storeId, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                    });
                    const data = await res.json();
                    // Ring on a genuinely new active request (open or in_progress).
                    const ids = new Set();
                    for (const r of data.open) ids.add(r.id);
                    for (const r of data.in_progress) ids.add(r.id);
                    if (this._seenIds && window.NotifySounds) {
                        for (const id of ids) { if (!this._seenIds.has(id)) { NotifySounds.play('order_new'); break; } }
                    }
                    this._seenIds = ids;
                    this.cols = { open: data.open, in_progress: data.in_progress, done: data.done };
                } catch (e) {}
            },
            async post(id, payload) {
                try {
                    const body = new URLSearchParams(Object.assign({ id: id }, payload));
                    await fetch(@js(route('hotel.requests.status')), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        credentials: 'same-origin',
                        body: body.toString(),
                    });
                    this.poll();
                } catch (e) {}
            },
            setStatus(id, status) { this.post(id, { status: status }); },
            cancel(id) { this.post(id, { status: 'cancelled' }); },
            assign(id, staffId) {
                const req = this._find(id);
                this.post(id, { status: req ? req.status : 'open', assigned_staff_id: staffId || '' });
            },
            _find(id) {
                for (const k of ['open', 'in_progress', 'done']) {
                    const r = this.cols[k].find(x => x.id === id);
                    if (r) return r;
                }
                return null;
            },
        };
    }
</script>
@endpush
