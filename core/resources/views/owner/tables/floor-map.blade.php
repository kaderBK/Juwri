{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Floor map'))
@section('page_title', ___('Floor map'))
@section('page_sub', $store->title.' - '.___('Live table layout and seating status.'))

@section('page_actions')
    @if(!empty($stores) && count($stores) > 1)
    <select id="floor-restaurant" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Store') }}"
            data-ui-do="nav-tpl" data-ui-tpl="{{ url('restaurant') }}/__/floor-map">
        @foreach($stores as $r)
            <option value="{{ $r->id }}" @selected((int)$store->id === (int)$r->id)>{{ $r->title }}</option>
        @endforeach
    </select>
    @endif
    @if(!empty($branches) && count($branches) >= 1)
    <form method="get" class="ui-fm__branch">
        <label class="ui-visually-hidden" for="floor-branch">{{ ___('Branch') }}</label>
        <select id="floor-branch" name="branch" class="ui-input ui-input--sm ui-input--auto" data-ui-do="submit">
            @foreach($branches as $b)
                <option value="{{ $b->id }}" @selected((int)($branchId ?? 0) === (int)$b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
    </form>
    @endif
    {{-- FP3: editor tools — zoom, undo, snap-to-grid --}}
    <div class="fm-tools ui-fm__tools">
        <x-ui.button type="button" id="fm-zoom-out" variant="ghost" size="sm" title="{{ ___('Zoom out') }}" aria-label="{{ ___('Zoom out') }}">−</x-ui.button>
        <span id="fm-zoom-label" class="ui-fm__zoom ui-num">100%</span>
        <x-ui.button type="button" id="fm-zoom-in" variant="ghost" size="sm" title="{{ ___('Zoom in') }}" aria-label="{{ ___('Zoom in') }}">+</x-ui.button>
        <x-ui.button type="button" id="fm-undo" variant="ghost" size="sm" icon="back" title="{{ ___('Undo move') }}" disabled>{{ ___('Undo') }}</x-ui.button>
        <label class="ui-fm__toggle">
            <input type="checkbox" id="fm-snap" checked>{{ ___('Snap') }}
        </label>
        {{-- FP7 #39: assign a section + server to tables --}}
        <label class="ui-fm__toggle">
            <input type="checkbox" id="fm-assign">{{ ___('Assign servers') }}
        </label>
        {{-- FP7 #41: colour tiles by section + show coverage --}}
        <label class="ui-fm__toggle">
            <input type="checkbox" id="fm-overlay">{{ ___('Section overlay') }}
        </label>
        {{-- FP9 #48/#49: turn-time analytics panel --}}
        <label class="ui-fm__toggle">
            <input type="checkbox" id="fm-turntimes">{{ ___('Turn times') }}
        </label>
        {{-- PARITY-RUN T4: merge mode (multi-select tables onto one bill) --}}
        @if(!empty($canMerge))
        <label class="ui-fm__toggle">
            <input type="checkbox" id="fm-merge">{{ ___('Merge tables') }}
        </label>
        @endif
    </div>
    <x-ui.button type="button" id="fm-add" variant="secondary" icon="plus">{{ ___('Add table') }}</x-ui.button>
    <x-ui.button type="button" id="fm-save" icon="check">{{ ___('Save layout') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-stack ui-fm"
     data-restaurant="{{ $store->id }}"
     data-branch="{{ $branchId }}"
     data-csrf="{{ csrf_token() }}"
     data-add-url="{{ url('restaurant/'.$store->id.'/floor-map/tables') }}?branch={{ $branchId }}"
     data-save-url="{{ route('floor-map.save', $store->id) }}?branch={{ $branchId }}"
     data-table-base="{{ url('restaurant/'.$store->id.'/floor-map/tables') }}"
     data-turntimes-url="{{ route('floor-map.turnTimes', $store->id) }}?branch={{ $branchId }}"
     {{-- PARITY-RUN T4 --}}
     data-can-merge="{{ !empty($canMerge) ? '1' : '' }}"
     data-merges-url="{{ route('floor-map.merges', $store->id) }}?branch={{ $branchId }}"
     data-merge-url="{{ route('floor-map.merge', $store->id) }}?branch={{ $branchId }}"
     data-unmerge-base="{{ url('restaurant/'.$store->id.'/floor-map/merges') }}"
     id="fm-root">

    <section class="ui-card ui-card--inset ui-fm__hero">
        <div>
            <p class="ui-overline">{{ ___('Floor map') }}</p>
            <h2 class="ui-card__title">{{ ___('Add, drag, save') }}</h2>
            <p class="ui-muted">{{ ___('Add tables, drag them into place, tap the gear to set status, then Save layout.') }}</p>
        </div>
        <dl class="ui-facts">
            <div><dt>{{ ___('Tables') }}</dt><dd id="fm-count">{{ $tables->count() }}</dd></div>
            <div><dt>{{ ___('Seats') }}</dt><dd id="fm-seats">{{ $tables->sum('seats') }}</dd></div>
        </dl>
    </section>

    <section class="ui-card">
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Layout') }}</h2>
            <div class="floor-legend ui-fm__legend">
                @foreach(['free'=>'#16a34a','seated'=>'#2563eb','ordering'=>'#7c3aed','food'=>'#d97706','bill'=>'#dc2626','dirty'=>'#6b7280'] as $s=>$c)
                    <span style="display:inline-flex;align-items:center;gap:.3rem;"><i style="width:10px;height:10px;border-radius:2px;background:{{ $c }};display:inline-block;"></i>{{ ___(ucfirst($s)) }}</span>
                @endforeach
            </div>
        </div>
        <p style="opacity:.6;font-size:.8rem;margin:0 0 .5rem;">{{ ___('Drag to move · gear = set status · QR = table QR & link · double-click = edit seats · × = delete. Then Save layout.') }}</p>

        <div class="floor-canvas" id="fm-canvas" style="position:relative;min-height:460px;border:1px dashed rgba(120,120,140,.3);border-radius:12px;background:rgba(120,120,140,.04);">
            @foreach ($tables as $t)
                <div class="fm-tile floor-tile--{{ $t->shape ?? 'square' }}"
                     data-table-id="{{ $t->id }}"
                     data-table-no="{{ $t->table_no }}"{{-- PARITY-RUN T4: merge-bar labels --}}
                     data-seats="{{ $t->seats }}"
                     data-shape="{{ $t->shape ?? 'square' }}"
                     data-status="{{ $t->status ?? 'free' }}"
                     data-section="{{ $t->section ?? '' }}"
                     data-server-staff-id="{{ $t->server_staff_id ?? '' }}"
                     data-note="{{ $t->note ?? '' }}"
                     data-floor-label="{{ $t->floor_label ?? '' }}"
                     data-min-covers="{{ $t->min_covers ?? '' }}"
                     data-max-covers="{{ $t->max_covers ?? '' }}"
                     data-table-key="{{ $t->key }}"
                     style="position:absolute;left:{{ ($t->map_x ?? 0) }}px;top:{{ ($t->map_y ?? 0) }}px;width:78px;height:78px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:12px;background:#1b1f27;color:#e7e9ee;cursor:move;user-select:none;">
                    <button type="button" class="fm-del" title="{{ ___('Delete') }}" style="position:absolute;top:-8px;right:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#dc2626;color:#fff;cursor:pointer;line-height:1;">×</button>
                    <button type="button" class="fm-status-btn" title="{{ ___('Status') }}" style="position:absolute;top:-8px;left:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#334155;color:#fff;cursor:pointer;line-height:1;">⚙</button>
                    <button type="button" class="fm-qr-btn" title="{{ ___('Table QR & link') }}" style="position:absolute;bottom:-8px;left:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#0ea5e9;color:#fff;cursor:pointer;line-height:1;font-size:.55rem;font-weight:700;">QR</button>
                    {{-- Own key, not the shared ___('T'): that letter also drove picnic's
                         weekday strip, so any calendar value corrupted this prefix. --}}
                    <strong>{{ ___('table_no_prefix') }}{{ $t->table_no }}</strong>
                    <small class="fm-seats-label">{{ $t->seats }} {{ ___('seats') }}</small>
                    <small class="fm-cap-label" style="font-size:.6rem;opacity:.7;min-height:.7rem;">{{ ($t->min_covers && $t->max_covers) ? $t->min_covers.'–'.$t->max_covers.' '.___('covers') : '' }}</small>
                    <small class="fm-room-label" style="font-size:.6rem;opacity:.7;min-height:.7rem;">{{ $t->floor_label ?? '' }}</small>
                    <small class="fm-section-label" style="font-size:.62rem;opacity:.75;min-height:.7rem;">{{ $t->section ?? '' }}</small>
                    @if(!empty($t->note))<span class="fm-note-flag" title="{{ $t->note }}" style="position:absolute;bottom:-8px;right:-8px;font-size:.7rem;">📌</span>@endif
                </div>
            @endforeach
        </div>

        {{-- PARITY-RUN T4: merge-mode action bar + live merged parties.
             Every figure below is the server's (the party bill is the sum of each
             order's own Order::getTotalAttribute()); nothing is summed here. --}}
        @if(!empty($canMerge))
        <div id="fm-merge-bar" style="display:none;margin-top:.75rem;padding:.6rem .75rem;border:1px solid rgba(120,120,140,.3);border-radius:10px;background:rgba(14,165,233,.06);">
            <div class="ui-cluster ui-fm__merge-row">
                <span id="fm-merge-hint" class="ui-muted ui-fm__grow">{{ ___('Tap the tables to merge. The first one keeps the bill.') }}</span>
                <x-ui.button type="button" id="fm-merge-clear" variant="ghost" size="sm">{{ ___('Clear') }}</x-ui.button>
                <x-ui.button type="button" id="fm-merge-go" size="sm" disabled>{{ ___('Merge tables') }}</x-ui.button>
            </div>
            <p id="fm-merge-error" class="ui-fm__error" style="display:none;"></p>
        </div>

        <div id="fm-merge-panel" style="display:none;margin-top:.75rem;padding-top:.75rem;border-top:1px solid rgba(120,120,140,.2);">
            <h4 style="margin:0 0 .5rem;font-size:.85rem;">{{ ___('Merged tables') }}</h4>
            <div id="fm-merge-list" style="display:flex;flex-wrap:wrap;gap:.6rem;font-size:.78rem;"></div>
        </div>
        @endif

        {{-- FP7 #40/#41: section coverage — servers, table counts, covers (seats) --}}
        <div id="fm-coverage" style="display:none;margin-top:.75rem;padding-top:.75rem;border-top:1px solid rgba(120,120,140,.2);">
            <h4 style="margin:0 0 .5rem;font-size:.85rem;">{{ ___('Section coverage') }}</h4>
            <div id="fm-coverage-body" style="display:flex;flex-wrap:wrap;gap:.6rem;font-size:.78rem;"></div>
        </div>

        {{-- FP9 #48/#49: turn-time + utilization analytics (last 30 days) --}}
        <div id="fm-turntimes-panel" style="display:none;margin-top:.75rem;padding-top:.75rem;border-top:1px solid rgba(120,120,140,.2);">
            <h4 style="margin:0 0 .5rem;font-size:.85rem;">{{ ___('Turn times & utilization') }} <span style="opacity:.6;font-weight:400;">({{ ___('last 30 days') }})</span></h4>
            <div id="fm-tt-body" style="font-size:.78rem;"><em style="opacity:.6;">{{ ___('Loading…') }}</em></div>
        </div>
    </section>
</div>

{{-- Table QR / share-link picker --}}
<div id="fm-qr-menu" style="display:none;position:fixed;z-index:60;background:#1b1b26;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:.4rem;box-shadow:0 8px 30px rgba(0,0,0,.4);">
    <button type="button" id="fm-qr-open" style="display:block;width:100%;text-align:left;padding:.35rem .7rem;border:0;background:transparent;color:#e7e9ee;cursor:pointer;border-radius:6px;">{{ ___('Open QR builder') }}</button>
    <button type="button" id="fm-qr-copy" style="display:block;width:100%;text-align:left;padding:.35rem .7rem;border:0;background:transparent;color:#e7e9ee;cursor:pointer;border-radius:6px;">{{ ___('Copy link') }}</button>
</div>

{{-- Status picker --}}
<div id="fm-status-menu" style="display:none;position:fixed;z-index:60;background:#1b1b26;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:.4rem;box-shadow:0 8px 30px rgba(0,0,0,.4);">
    @foreach(['free'=>'#16a34a','seated'=>'#2563eb','ordering'=>'#7c3aed','food'=>'#d97706','bill'=>'#dc2626','dirty'=>'#6b7280'] as $s=>$c)
        <button type="button" class="fm-status-opt" data-status="{{ $s }}" style="display:block;width:100%;text-align:left;padding:.35rem .7rem;border:0;background:transparent;color:#e7e9ee;cursor:pointer;border-radius:6px;">
            <i style="width:10px;height:10px;border-radius:2px;background:{{ $c }};display:inline-block;margin-inline-end:.5rem;"></i>{{ ___(ucfirst($s)) }}
        </button>
    @endforeach
</div>
<style nonce="{{ csp_nonce() }}">
    .fm-tile{touch-action:none;} /* FP3: let Pointer Events drive touch-drag instead of scrolling */
    .floor-tile--circle{border-radius:50% !important;}
    .fm-tile[data-status="free"]{outline:2px solid #16a34a;}
    .fm-tile[data-status="seated"]{outline:2px solid #2563eb;}
    .fm-tile[data-status="ordering"]{outline:2px solid #7c3aed;}
    .fm-tile[data-status="food"]{outline:2px solid #d97706;}
    .fm-tile[data-status="bill"]{outline:2px solid #dc2626;}
    .fm-tile[data-status="dirty"]{outline:2px solid #6b7280;opacity:.7;}
    .fm-status-opt:hover{background:rgba(255,255,255,.08);}
    /* PARITY-RUN T4: linked/shaded merged tables + merge-mode selection. */
    .fm-tile[data-merge-role="secondary"]{opacity:.6;box-shadow:inset 0 0 0 2px #0ea5e9;}
    .fm-tile[data-merge-role="primary"]{box-shadow:inset 0 0 0 2px #0ea5e9;}
    .fm-merge-badge{position:absolute;bottom:-8px;right:-8px;padding:0 .35rem;border-radius:8px;background:#0ea5e9;color:#fff;font-size:.55rem;font-weight:700;line-height:1.4;}
    .fm-tile.fm-picked{outline:3px solid #0ea5e9 !important;}
    .fm-pick-no{position:absolute;top:-8px;left:14px;min-width:18px;height:18px;border-radius:999px;background:#0ea5e9;color:#fff;font-size:.6rem;font-weight:700;line-height:18px;text-align:center;}
    .fm-merge-card{padding:.5rem .7rem;border:1px solid rgba(120,120,140,.3);border-radius:10px;display:flex;align-items:center;gap:.6rem;}
</style>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
// Bind on both DOMContentLoaded and Turbo's turbo:load: the owner shell uses
// Turbo (SPA-style nav), which swaps the <body> WITHOUT firing DOMContentLoaded,
// so arriving via the sidebar link left the whole floor map inert (no drag/add/
// save/status poll). The WeakSet keyed on #fm-root makes init run exactly once
// per DOM instance (first load, each Turbo visit, and cache restore).
(function () {
    var fmReady = (window.__fmReady = window.__fmReady || new WeakSet());
    function fmInit() {
    var root = document.getElementById('fm-root');
    if (!root || fmReady.has(root)) return;
    fmReady.add(root);
    var canvas = document.getElementById('fm-canvas');
    var csrf = root.dataset.csrf;
    var H = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' };

    function recount() {
        var tiles = canvas.querySelectorAll('.fm-tile');
        document.getElementById('fm-count').textContent = tiles.length;
        var seats = 0; tiles.forEach(function (t) { seats += parseInt(t.dataset.seats || 0, 10); });
        document.getElementById('fm-seats').textContent = seats;
    }

    // FP3: zoom + snap-to-grid + undo state
    var scale = 1, GRID = 20, TILE = 78;
    var undoStack = [];
    // WIRING-RUN A8: setSnapPref() was an empty placeholder, so the Snap toggle reset
    // to checked on every visit. snap() already reads the checkbox live — all that was
    // missing was remembering the owner's choice between sessions.
    var SNAP_PREF_KEY = 'fm-snap-pref';
    function setSnapPref() {
        try { localStorage.setItem(SNAP_PREF_KEY, document.getElementById('fm-snap').checked ? '1' : '0'); } catch (e) {}
    }
    (function restoreSnapPref() {
        var box = document.getElementById('fm-snap');
        if (!box) return;
        try {
            var saved = localStorage.getItem(SNAP_PREF_KEY);
            if (saved !== null) box.checked = saved === '1';
        } catch (e) {}
        box.addEventListener('change', setSnapPref);
    })();
    function snap(v) { return document.getElementById('fm-snap').checked ? Math.round(v / GRID) * GRID : v; }
    function applyZoom() {
        canvas.style.transformOrigin = 'top left';
        canvas.style.transform = 'scale(' + scale + ')';
        document.getElementById('fm-zoom-label').textContent = Math.round(scale * 100) + '%';
    }
    function pushUndo(tile) {
        undoStack.push({ id: tile.dataset.tableId, left: tile.style.left, top: tile.style.top });
        if (undoStack.length > 50) undoStack.shift();
        document.getElementById('fm-undo').disabled = false;
    }

    // drag — Pointer Events so mouse, touch (tablet) and pen all work; scale-aware.
    var drag = null;
    canvas.addEventListener('pointerdown', function (e) {
        var tile = e.target.closest('.fm-tile');
        if (!tile || e.target.closest('button')) return;
        // PARITY-RUN T4: in merge mode a tap SELECTS, so dragging is suspended —
        // otherwise every pick would nudge the layout by a pixel or two.
        if (mergeMode) return;
        pushUndo(tile); // snapshot BEFORE the move so Undo restores it
        drag = { tile: tile, startX: e.clientX, startY: e.clientY, origLeft: tile.offsetLeft, origTop: tile.offsetTop, moved: false };
        tile.style.zIndex = 10;
        try { tile.setPointerCapture(e.pointerId); } catch (err) {}
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var maxX = canvas.clientWidth - TILE, maxY = canvas.clientHeight - TILE;
        var x = Math.max(0, Math.min(maxX, drag.origLeft + (e.clientX - drag.startX) / scale));
        var y = Math.max(0, Math.min(maxY, drag.origTop + (e.clientY - drag.startY) / scale));
        drag.tile.style.left = x + 'px'; drag.tile.style.top = y + 'px';
        drag.moved = true;
    });
    function endDrag() {
        if (!drag) return;
        if (drag.moved) { // snap to grid on drop
            drag.tile.style.left = Math.max(0, snap(drag.tile.offsetLeft)) + 'px';
            drag.tile.style.top = Math.max(0, snap(drag.tile.offsetTop)) + 'px';
        } else {
            undoStack.pop(); // no actual move → discard the snapshot
            if (!undoStack.length) document.getElementById('fm-undo').disabled = true;
            // FP7 #39: a tap (no move) in Assign mode opens the section/server picker.
            if (document.getElementById('fm-assign').checked) { assignTile(drag.tile); }
        }
        drag.tile.style.zIndex = ''; drag = null;
    }
    canvas.addEventListener('pointerup', endDrag);
    canvas.addEventListener('pointercancel', endDrag);

    // FP7 #39: assign a section name + a server (from this restaurant's staff) to a table.
    var STAFF = @json($staffList ?? []);
    async function assignTile(tile) {
        var section = await window.dashPrompt({ body: '{{ ___('Section name (blank to clear)') }}', value: tile.dataset.section || '' });
        if (section === null) return;
        tile.dataset.section = section.trim();
        var label = tile.querySelector('.fm-section-label'); if (label) label.textContent = section.trim();

        var menu = STAFF.map(function (s, i) { return (i + 1) + ') ' + s.name; }).join('\n');
        var pick = await window.dashPrompt({ body: '{{ ___('Assign server — enter a number, 0 for none:') }}\n0) {{ ___('None') }}\n' + menu, value: '0' });
        if (pick === null) return;
        var idx = parseInt(pick, 10);
        tile.dataset.serverStaffId = (idx >= 1 && STAFF[idx - 1]) ? STAFF[idx - 1].id : '';

        // FP8 #46: a short table note (VIP, birthday, allergy).
        var note = await window.dashPrompt({ body: '{{ ___('Table note (VIP, birthday, allergy — blank to clear):') }}', value: tile.dataset.note || '' });
        if (note !== null) {
            tile.dataset.note = note.trim();
            var flag = tile.querySelector('.fm-note-flag');
            if (note.trim()) {
                if (!flag) { flag = document.createElement('span'); flag.className = 'fm-note-flag'; flag.style.cssText = 'position:absolute;bottom:-8px;right:-8px;font-size:.7rem;'; flag.textContent = '📌'; tile.appendChild(flag); }
                flag.title = note.trim();
            } else if (flag) { flag.remove(); }
        }
        if (document.getElementById('fm-overlay').checked) applyOverlay();
    }

    // FP7 #41: deterministic colour per section name.
    function sectionColor(name) {
        if (!name) return '';
        var h = 0; for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
        return 'hsl(' + h + ',60%,55%)';
    }
    function applyOverlay() {
        var on = document.getElementById('fm-overlay').checked;
        document.getElementById('fm-coverage').style.display = on ? 'block' : 'none';
        canvas.querySelectorAll('.fm-tile').forEach(function (t) {
            if (on && t.dataset.section) {
                t.style.boxShadow = 'inset 0 0 0 3px ' + sectionColor(t.dataset.section);
            } else {
                t.style.boxShadow = '';
            }
        });
        if (on) renderCoverage();
    }
    // FP7 #40: covers per server (table count + seats), plus unassigned.
    function renderCoverage() {
        var byServer = {}; // id -> {name, tables, covers}
        var nameFor = {}; STAFF.forEach(function (s) { nameFor[s.id] = s.name; });
        canvas.querySelectorAll('.fm-tile').forEach(function (t) {
            var sid = t.dataset.serverStaffId || 'none';
            var seats = parseInt(t.dataset.seats || 0, 10) || 0;
            if (!byServer[sid]) byServer[sid] = { name: sid === 'none' ? '{{ ___('Unassigned') }}' : (nameFor[sid] || ('#' + sid)), tables: 0, covers: 0 };
            byServer[sid].tables++; byServer[sid].covers += seats;
        });
        var body = document.getElementById('fm-coverage-body');
        body.innerHTML = Object.keys(byServer).map(function (sid) {
            var s = byServer[sid];
            return '<span style="border:1px solid rgba(120,120,140,.3);border-radius:8px;padding:.35rem .6rem;">'
                + '<b>' + escTt(s.name) + '</b> · ' + s.tables + ' {{ ___('tables') }} · ' + s.covers + ' {{ ___('covers') }}</span>';
        }).join('');
    }
    document.getElementById('fm-overlay').addEventListener('change', applyOverlay);

    // zoom controls
    document.getElementById('fm-zoom-in').addEventListener('click', function () { scale = Math.min(2, scale + 0.1); applyZoom(); });
    document.getElementById('fm-zoom-out').addEventListener('click', function () { scale = Math.max(0.5, scale - 0.1); applyZoom(); });

    // undo last move
    document.getElementById('fm-undo').addEventListener('click', function () {
        var last = undoStack.pop();
        if (!last) return;
        var tile = canvas.querySelector('.fm-tile[data-table-id="' + last.id + '"]');
        if (tile) { tile.style.left = last.left; tile.style.top = last.top; }
        if (!undoStack.length) this.disabled = true;
    });

    // add
    document.getElementById('fm-add').addEventListener('click', function () {
        fetch(root.dataset.addUrl, { method: 'POST', headers: H, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (t) { canvas.appendChild(makeTile(t)); recount(); });
    });

    function makeTile(t) {
        var d = document.createElement('div');
        d.className = 'fm-tile floor-tile--' + (t.shape || 'square');
        d.dataset.tableId = t.id; d.dataset.tableNo = t.table_no; d.dataset.seats = t.seats; d.dataset.shape = t.shape || 'square'; d.dataset.status = t.status || 'free';
        d.dataset.section = t.section || ''; d.dataset.serverStaffId = t.server_staff_id || ''; d.dataset.note = t.note || '';
        d.dataset.floorLabel = t.floor_label || ''; d.dataset.minCovers = t.min_covers || ''; d.dataset.maxCovers = t.max_covers || '';
        d.style.cssText = 'position:absolute;left:' + (t.map_x || 60) + 'px;top:' + (t.map_y || 60) + 'px;width:78px;height:78px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-radius:12px;background:#1b1f27;color:#e7e9ee;cursor:move;user-select:none;';
        d.dataset.tableKey = t.key || '';
        d.innerHTML = '<button type="button" class="fm-del" style="position:absolute;top:-8px;right:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#dc2626;color:#fff;cursor:pointer;line-height:1;">×</button>'
            + '<button type="button" class="fm-status-btn" style="position:absolute;top:-8px;left:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#334155;color:#fff;cursor:pointer;line-height:1;">⚙</button>'
            + '<button type="button" class="fm-qr-btn" style="position:absolute;bottom:-8px;left:-8px;width:20px;height:20px;border-radius:50%;border:0;background:#0ea5e9;color:#fff;cursor:pointer;line-height:1;font-size:.55rem;font-weight:700;">QR</button>'
            + '<strong>T' + escTt(t.table_no) + '</strong><small class="fm-seats-label">' + escTt(t.seats) + ' seats</small>'
            + '<small class="fm-cap-label" style="font-size:.6rem;opacity:.7;min-height:.7rem;"></small>'
            + '<small class="fm-room-label" style="font-size:.6rem;opacity:.7;min-height:.7rem;"></small>'
            + '<small class="fm-section-label" style="font-size:.62rem;opacity:.75;min-height:.7rem;">' + escTt(t.section) + '</small>';
        return d;
    }

    /* ==================================================================
       PARITY-RUN T4 — merge tables onto one bill.

       The server owns every rule (same branch, no split bill mid-payment,
       one party per table) and every figure: a merge MOVES the secondary
       tables' open orders onto the primary, and the party's total is the
       sum of those orders' own server-side totals. This block only
       collects taps and renders what comes back.
       ================================================================== */
    var mergeMode = false;
    var mergePicks = [];   // table ids, in tap order — [0] keeps the bill
    var mergeGroups = [];
    var mergeBox = document.getElementById('fm-merge-bar');
    var mergePanel = document.getElementById('fm-merge-panel');
    var canMerge = root.dataset.canMerge === '1';

    function escFm(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function tileById(id) { return canvas.querySelector('.fm-tile[data-table-id="' + id + '"]'); }

    function paintPicks() {
        canvas.querySelectorAll('.fm-tile').forEach(function (t) {
            t.classList.remove('fm-picked');
            var n = t.querySelector('.fm-pick-no');
            if (n) n.remove();
        });
        mergePicks.forEach(function (id, i) {
            var tile = tileById(id);
            if (!tile) return;
            tile.classList.add('fm-picked');
            var b = document.createElement('span');
            b.className = 'fm-pick-no';
            b.textContent = String(i + 1);
            tile.appendChild(b);
        });
        var go = document.getElementById('fm-merge-go');
        if (go) go.disabled = mergePicks.length < 2;
        var hint = document.getElementById('fm-merge-hint');
        if (hint && mergePicks.length) {
            hint.textContent = @js(___('Selected tables')) + ': ' + mergePicks.map(function (id) {
                var tile = tileById(id);
                return @js(___('table_no_prefix')) + (tile ? tile.dataset.tableNo : id);
            }).join(' · ');
        } else if (hint) {
            hint.textContent = @js(___('Tap the tables to merge. The first one keeps the bill.'));
        }
    }

    function paintGroups() {
        canvas.querySelectorAll('.fm-tile').forEach(function (t) {
            delete t.dataset.mergeRole;
            var b = t.querySelector('.fm-merge-badge');
            if (b) b.remove();
        });
        mergeGroups.forEach(function (g) {
            (g.table_ids || []).forEach(function (id) {
                var tile = tileById(id);
                if (!tile) return;
                tile.dataset.mergeRole = (Number(id) === Number(g.primary_table_id)) ? 'primary' : 'secondary';
                var badge = document.createElement('span');
                badge.className = 'fm-merge-badge';
                badge.textContent = g.label;
                tile.appendChild(badge);
            });
        });
        if (!mergePanel) return;
        mergePanel.style.display = mergeGroups.length ? 'block' : 'none';
        document.getElementById('fm-merge-list').innerHTML = mergeGroups.map(function (g) {
            {{-- Labels go through the JS-encoding directive, never a quoted echo:
                 a translation carrying an apostrophe (fr) would otherwise close
                 the surrounding JS string. escFm() covers the server label. --}}
            return '<div class="fm-merge-card"><strong>' + escFm(g.label) + '</strong>'
                 + '<span style="opacity:.7;">' + g.seats + ' ' + escFm(@js(___('seats'))) + ' · ' + g.order_count + ' ' + escFm(@js(___('orders'))) + ' · ' + Number(g.total || 0).toFixed(2) + '</span>'
                 + '<button type="button" class="fm-unmerge ui-btn ui-btn--sm ui-btn--secondary" data-group="' + g.id + '">' + escFm(@js(___('Unmerge'))) + '</button></div>';
        }).join('');
    }

    function loadMerges() {
        if (!canMerge) return;
        fetch(root.dataset.mergesUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { groups: [] }; })
            .then(function (d) { mergeGroups = d.groups || []; paintGroups(); })
            .catch(function () { /* a dead network must not blank the floor */ });
    }

    function mergeError(msg) {
        var el = document.getElementById('fm-merge-error');
        if (!el) return;
        el.textContent = msg || '';
        el.style.display = msg ? 'block' : 'none';
    }

    if (canMerge) {
        var mergeToggle = document.getElementById('fm-merge');
        if (mergeToggle) {
            mergeToggle.addEventListener('change', function () {
                mergeMode = this.checked;
                mergePicks = [];
                mergeError('');
                if (mergeBox) mergeBox.style.display = mergeMode ? 'block' : 'none';
                paintPicks();
            });
        }
        document.getElementById('fm-merge-clear').addEventListener('click', function () {
            mergePicks = []; mergeError(''); paintPicks();
        });
        document.getElementById('fm-merge-go').addEventListener('click', function () {
            if (mergePicks.length < 2) return;
            var btn = this;
            btn.disabled = true;
            mergeError('');
            fetch(root.dataset.mergeUrl, {
                method: 'POST', headers: H, credentials: 'same-origin',
                body: JSON.stringify({ primary_table_id: mergePicks[0], table_ids: mergePicks.slice(1) }),
            })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    if (!res.ok) { mergeError(res.d.message || @js(___('Error'))); return; }
                    mergeGroups = res.d.groups || [];
                    mergePicks = [];
                    paintPicks();
                    paintGroups();
                })
                .catch(function () { mergeError(@js(___('Error'))); })
                .then(function () { btn.disabled = mergePicks.length < 2; });
        });
        // Scoped to the panel, not the document: a Turbo visit builds a new
        // #fm-root, and a document-level listener from the previous instance
        // would keep firing against a detached canvas.
        mergePanel.addEventListener('click', async function (e) {
            var un = e.target.closest('.fm-unmerge');
            if (!un) return;
            e.preventDefault();
            if (!(await window.dashConfirm(@js(___('Release this merged party?'))))) return;
            fetch(root.dataset.unmergeBase + '/' + un.dataset.group + '?branch=' + root.dataset.branch, {
                method: 'DELETE', headers: H, credentials: 'same-origin',
            })
                .then(function (r) { return r.ok ? r.json() : { groups: mergeGroups }; })
                .then(function (d) { mergeGroups = d.groups || []; paintGroups(); })
                .catch(function () {});
        });
        loadMerges();
    }

    // delete + status open
    var menu = document.getElementById('fm-status-menu');
    var menuTile = null;
    canvas.addEventListener('click', function (e) {
        // PARITY-RUN T4: merge mode owns the tap (before delete/status/QR).
        if (mergeMode) {
            var picked = e.target.closest('.fm-tile');
            if (!picked) return;
            e.preventDefault();
            e.stopPropagation();
            var pid = parseInt(picked.dataset.tableId, 10);
            var at = mergePicks.indexOf(pid);
            if (at === -1) mergePicks.push(pid); else mergePicks.splice(at, 1);
            paintPicks();
            return;
        }
        var del = e.target.closest('.fm-del');
        if (del) {
            var tile = del.closest('.fm-tile');
            fetch(root.dataset.tableBase + '/' + tile.dataset.tableId + '?branch=' + root.dataset.branch, { method: 'DELETE', headers: H, credentials: 'same-origin' })
                .then(function () { tile.remove(); recount(); });
            return;
        }
        var sb = e.target.closest('.fm-status-btn');
        if (sb) {
            e.stopPropagation();
            menuTile = sb.closest('.fm-tile');
            var r = sb.getBoundingClientRect();
            menu.style.left = r.left + 'px'; menu.style.top = (r.bottom + 4) + 'px'; menu.style.display = 'block';
            return;
        }
        var qb = e.target.closest('.fm-qr-btn');
        if (qb) {
            e.stopPropagation();
            qrKey = qb.closest('.fm-tile').dataset.tableKey || '';
            var qr = qb.getBoundingClientRect();
            qrCopyBtn.textContent = @js(___('Copy link'));
            qrMenu.style.left = qr.left + 'px'; qrMenu.style.top = (qr.bottom + 4) + 'px'; qrMenu.style.display = 'block';
        }
    });

    // Per-table QR + share link (same links as the Tables & QR screen)
    var QR_BUILDER = @js(route('stores.qrDesigner', $store->id));
    var PUB_LINK = @js(store_public_qr_url($store, '__TABLE_KEY__'));
    var qrMenu = document.getElementById('fm-qr-menu');
    var qrCopyBtn = document.getElementById('fm-qr-copy');
    var qrKey = '';
    // position:fixed is hijacked by transformed ancestors — hoist the popovers to <body>.
    document.body.appendChild(qrMenu);
    document.body.appendChild(document.getElementById('fm-status-menu'));
    document.getElementById('fm-qr-open').addEventListener('click', function () {
        if (qrKey) window.open(QR_BUILDER + '?table=' + encodeURIComponent(qrKey), '_blank');
        qrMenu.style.display = 'none';
    });
    qrCopyBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!qrKey) return;
        navigator.clipboard.writeText(PUB_LINK.replace('__TABLE_KEY__', encodeURIComponent(qrKey))).then(function () {
            qrCopyBtn.textContent = @js(___('Copied'));
            setTimeout(function () { qrMenu.style.display = 'none'; }, 700);
        });
    });

    // edit seats, capacity range (FP4 #26) and room/zone (FP4 #22)
    canvas.addEventListener('dblclick', async function (e) {
        var tile = e.target.closest('.fm-tile');
        if (!tile) return;
        var seats = await window.dashPrompt({ body: '{{ ___('Seats') }}', value: tile.dataset.seats });
        if (seats && !isNaN(seats)) {
            tile.dataset.seats = parseInt(seats, 10);
            tile.querySelector('.fm-seats-label').textContent = tile.dataset.seats + ' seats';
            recount();
        }
        // FP4 #26: capacity range as "min-max" (blank clears → falls back to 1..seats).
        var curCap = (tile.dataset.minCovers && tile.dataset.maxCovers) ? (tile.dataset.minCovers + '-' + tile.dataset.maxCovers) : '';
        var cap = await window.dashPrompt({ body: '{{ ___('Capacity range (min-max covers, blank to clear):') }}', value: curCap });
        if (cap !== null) {
            var m = cap.match(/^\s*(\d+)\s*-\s*(\d+)\s*$/);
            var capLabel = tile.querySelector('.fm-cap-label');
            if (m) {
                tile.dataset.minCovers = m[1]; tile.dataset.maxCovers = m[2];
                if (capLabel) capLabel.textContent = m[1] + '–' + m[2] + ' covers';
            } else {
                tile.dataset.minCovers = ''; tile.dataset.maxCovers = '';
                if (capLabel) capLabel.textContent = '';
            }
        }
        // FP4 #22: room/zone label (Patio, Bar, Main…).
        var room = await window.dashPrompt({ body: '{{ ___('Room / zone (blank to clear):') }}', value: tile.dataset.floorLabel || '' });
        if (room !== null) {
            tile.dataset.floorLabel = room.trim();
            var roomLabel = tile.querySelector('.fm-room-label');
            if (roomLabel) roomLabel.textContent = room.trim();
        }
    });

    document.addEventListener('click', function () { menu.style.display = 'none'; qrMenu.style.display = 'none'; });
    menu.querySelectorAll('.fm-status-opt').forEach(function (opt) {
        opt.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!menuTile) return;
            var status = opt.dataset.status, tile = menuTile;
            fetch(root.dataset.tableBase + '/' + tile.dataset.tableId + '/status?branch=' + root.dataset.branch, { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({ status: status }) })
                .then(function (r) { if (r.ok) tile.dataset.status = status; });
            menu.style.display = 'none';
        });
    });

    // FP9 #48/#49: turn-time + utilization analytics panel
    var ttLoaded = false;
    function escTt(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    document.getElementById('fm-turntimes').addEventListener('change', function () {
        var panel = document.getElementById('fm-turntimes-panel');
        panel.style.display = this.checked ? '' : 'none';
        if (this.checked && !ttLoaded) { ttLoaded = true; loadTurnTimes(); }
    });
    function loadTurnTimes(){
        var body = document.getElementById('fm-tt-body');
        fetch(root.dataset.turntimesUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { renderTurnTimes(d); })
            .catch(function () { body.innerHTML = '<em style="opacity:.6;">{{ ___('Could not load analytics') }}</em>'; });
    }
    function renderTurnTimes(d){
        var body = document.getElementById('fm-tt-body');
        if (!d.total_orders) { body.innerHTML = '<em style="opacity:.6;">{{ ___('No completed table orders yet.') }}</em>'; return; }
        var maxHour = Math.max.apply(null, d.by_hour) || 1;
        var bars = d.by_hour.map(function (c, h) {
            var pct = Math.round((c / maxHour) * 100);
            return '<div title="' + h + ':00 — ' + c + '" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px;">'
                 + '<div style="width:70%;height:' + Math.max(2, pct) + '%;background:var(--brand,#7c3aed);border-radius:2px;opacity:' + (0.35 + 0.65*(c/maxHour)) + ';"></div>'
                 + '<span style="font-size:.5rem;opacity:.5;">' + h + '</span></div>';
        }).join('');
        var tables = (d.tables || []).slice(0, 8).map(function (t) {
            return '<div style="display:flex;justify-content:space-between;gap:1rem;padding:.15rem 0;"><span>T' + escTt(t.table_no) + '</span><span style="opacity:.7;">' + t.orders + ' · ' + t.avg_minutes + 'm</span></div>';
        }).join('');
        var sections = (d.sections || []).map(function (s) {
            return '<div style="display:flex;justify-content:space-between;gap:1rem;padding:.15rem 0;"><span>' + escTt(s.section) + '</span><span style="opacity:.7;">' + s.orders + ' · ' + s.avg_minutes + 'm</span></div>';
        }).join('');
        body.innerHTML =
            '<div style="margin-bottom:.6rem;">{{ ___('Overall avg turn') }}: <strong>' + d.overall_avg + ' {{ ___('min') }}</strong> · ' + d.total_orders + ' {{ ___('orders') }}</div>'
          + '<div style="display:flex;gap:1.5rem;flex-wrap:wrap;">'
          + '<div style="min-width:180px;"><div style="opacity:.6;margin-bottom:.25rem;">{{ ___('Busiest tables') }}</div>' + tables + '</div>'
          + '<div style="min-width:180px;"><div style="opacity:.6;margin-bottom:.25rem;">{{ ___('By section') }}</div>' + sections + '</div>'
          + '</div>'
          + '<div style="margin-top:.7rem;"><div style="opacity:.6;margin-bottom:.25rem;">{{ ___('Orders by hour') }}</div>'
          + '<div style="display:flex;align-items:flex-end;gap:2px;height:60px;">' + bars + '</div></div>';
    }

    // save layout
    document.getElementById('fm-save').addEventListener('click', function () {
        var btn = this;
        var tables = Array.prototype.map.call(canvas.querySelectorAll('.fm-tile'), function (t) {
            return { id: parseInt(t.dataset.tableId, 10), map_x: parseInt(t.offsetLeft, 10), map_y: parseInt(t.offsetTop, 10), shape: t.dataset.shape || 'square', seats: parseInt(t.dataset.seats, 10) || 1,
                floor_label: t.dataset.floorLabel || null, // FP4 #22
                section: t.dataset.section || null, server_staff_id: t.dataset.serverStaffId ? parseInt(t.dataset.serverStaffId, 10) : null,
                note: t.dataset.note || null,
                min_covers: t.dataset.minCovers ? parseInt(t.dataset.minCovers, 10) : null, // FP4 #26
                max_covers: t.dataset.maxCovers ? parseInt(t.dataset.maxCovers, 10) : null };
        });
        fetch(root.dataset.saveUrl, { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({ tables: tables }) })
            .then(function (r) { btn.textContent = r.ok ? '{{ ___('Saved ✓') }}' : '{{ ___('Error') }}'; setTimeout(function () { btn.textContent = '{{ ___('Save layout') }}'; }, 1500); });
    });

    recount();
    }
    document.addEventListener('DOMContentLoaded', fmInit);
    document.addEventListener('turbo:load', fmInit);
})();
</script>
@endpush
