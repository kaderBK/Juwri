{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
@php
    // SP11 #50: honour the app locale + flip to RTL for Arabic/Urdu/Hebrew/Persian.
    $appLocale = app()->getLocale();
    $isRtl = is_rtl($appLocale);
@endphp
<html lang="{{ $appLocale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#12121a">
    <link rel="manifest" href="/staff-app/manifest.webmanifest">
    <title>juwri Staff</title>
    <style nonce="{{ csp_nonce() }}">
        :root { --bg:#12121a; --card:#1c1c28; --line:#2a2a3a; --fg:#f4f4f8; --muted:#9a9ab0; --accent:#f5b54a; --ok:#3ecf8e; --warn:#f5b54a; --late:#ff6b6b; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body { margin:0; background:var(--bg); color:var(--fg); font:16px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; padding-bottom:env(safe-area-inset-bottom); }
        header { position:sticky; top:0; z-index:5; background:var(--bg); border-bottom:1px solid var(--line); padding:14px 16px; display:flex; align-items:center; justify-content:space-between; }
        header h1 { font-size:18px; margin:0; }
        header .dot { width:9px; height:9px; border-radius:50%; background:var(--muted); display:inline-block; margin-right:6px; }
        header .dot.live { background:var(--ok); }
        /* SP3 #15: filter chips */
        .filters { position:sticky; top:53px; z-index:4; background:var(--bg); display:flex; gap:8px; overflow-x:auto; padding:10px 12px; border-bottom:1px solid var(--line); -webkit-overflow-scrolling:touch; }
        .chip { flex:0 0 auto; background:var(--card); border:1px solid var(--line); color:var(--muted); border-radius:999px; padding:8px 14px; font-size:14px; font-weight:600; }
        .chip.active { background:var(--accent); color:#12121a; border-color:var(--accent); }
        main { padding:12px; display:grid; gap:12px; }
        .card { background:var(--card); border:1px solid var(--line); border-left:4px solid var(--line); border-radius:14px; padding:14px; }
        /* SP3 #11: colour by wait time */
        .card.fresh { border-left-color:var(--ok); }
        .card.warn  { border-left-color:var(--warn); }
        .card.late  { border-left-color:var(--late); }
        .card .top { display:flex; justify-content:space-between; align-items:baseline; gap:8px; margin-bottom:6px; }
        .card .id { font-weight:700; font-size:18px; }
        .card .meta { color:var(--muted); font-size:13px; }
        .card .timer { font-variant-numeric:tabular-nums; font-weight:700; font-size:14px; }
        .card.late .timer { color:var(--late); }
        .card.warn .timer { color:var(--warn); }
        .badge { font-size:12px; text-transform:uppercase; letter-spacing:.04em; padding:3px 8px; border-radius:999px; border:1px solid var(--line); white-space:nowrap; }
        .badge.new { color:var(--accent); border-color:var(--accent); }
        .badge.preparing { color:#7aa2ff; border-color:#7aa2ff; }
        .badge.ready { color:var(--ok); border-color:var(--ok); }
        .badge.call_bill { color:#ffb454; border-color:#ffb454; }
        button.bill { color:#ffb454; background:transparent; border:1px solid #ffb454; }
        button.move { color:#7aa2ff; background:transparent; border:1px solid #7aa2ff; }
        ul.items { list-style:none; margin:8px 0 0; padding:0; }
        ul.items li { padding:3px 0; color:#d7d7e3; }
        ul.items li b { color:var(--accent); }
        /* SP3 #12: order detail drawer */
        .detail { display:none; margin-top:10px; padding-top:10px; border-top:1px dashed var(--line); color:var(--muted); font-size:14px; }
        .card.open .detail { display:block; }
        .detail .row { display:flex; justify-content:space-between; padding:2px 0; }
        .detail .row b { color:var(--fg); }
        .actions { display:flex; gap:8px; margin-top:10px; }
        button.act { flex:1; border:0; border-radius:12px; padding:15px; font-size:16px; font-weight:700; }
        button.advance { color:#12121a; background:var(--accent); }
        button.accept { color:#12121a; background:var(--ok); }
        button.reject { color:#fff; background:transparent; border:1px solid var(--late); }
        button.act:active { transform:scale(.99); }
        .empty { text-align:center; color:var(--muted); padding:48px 16px; }
        .toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:#000; color:#fff; padding:10px 16px; border-radius:999px; font-size:14px; opacity:0; transition:opacity .2s; z-index:20; }
        .toast.show { opacity:.92; }
        .enable { background:none; border:1px solid var(--line); color:var(--muted); border-radius:999px; padding:6px 12px; font-size:13px; }
        /* FP7 #42: floor grid */
        #floor { grid-template-columns:repeat(auto-fill,minmax(96px,1fr)); }
        .ftile { border:1px solid var(--line); border-left:5px solid var(--muted); border-radius:12px; padding:12px; background:var(--card); text-align:center; }
        .ftile .tn { font-weight:700; font-size:16px; }
        .ftile .ts { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; margin-top:2px; }
        .ftile .sec { font-size:11px; color:var(--muted); margin-top:4px; }
        .ftile.free { border-left-color:var(--ok); }
        .ftile.preparing, .ftile.ordering { border-left-color:#7aa2ff; }
        .ftile.ready, .ftile.seated { border-left-color:var(--accent); }
        .ftile.call_bill { border-left-color:#ffb454; }
        .ftile.mine { box-shadow:inset 0 0 0 1px var(--accent); }
        /* SP5 #24-27: KDS tickets */
        #allday { padding:0 12px; display:flex; flex-wrap:wrap; gap:6px; }
        #allday.show { display:flex; }
        .ad { border:1px solid var(--line); border-radius:999px; padding:4px 10px; font-size:12px; background:var(--card); }
        .ad b { color:var(--accent); }
        .kt { border:1px solid var(--line); border-left:5px solid var(--muted); border-radius:12px; background:var(--card); overflow:hidden; }
        .kt-head { display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid var(--line); }
        .kt-head .where { font-weight:700; }
        .kt-head .ago { font-size:12px; color:var(--muted); }
        .kt-line { display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid var(--line); }
        .kt-line:last-child { border-bottom:0; }
        .kt-line .qn { flex:1; }
        .kt-line .qn .note { display:block; font-size:12px; color:var(--muted); }
        .kt-line .st { font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); min-width:64px; text-align:end; }
        .kt-line.queued { border-left:3px solid #7aa2ff; }
        .kt-line.preparing { border-left:3px solid #ffb454; }
        .kt-line.ready { border-left:3px solid var(--ok); }
        .kb { border:1px solid var(--line); background:none; color:var(--fg); border-radius:8px; padding:8px 10px; font-size:13px; font-weight:600; }
        .kb.bump { border-color:var(--accent); color:var(--accent); }
        /* SP8 #36/#37: checklist */
        .tk { display:flex; align-items:center; gap:12px; border:1px solid var(--line); border-radius:12px; padding:14px; background:var(--card); }
        .tk .box { width:26px; height:26px; border-radius:8px; border:2px solid var(--line); flex:none; display:flex; align-items:center; justify-content:center; font-weight:800; }
        .tk.done .box { background:var(--ok); border-color:var(--ok); color:#0b0b12; }
        .tk .tt { flex:1; }
        .tk.done .tt { color:var(--muted); text-decoration:line-through; }
        .tk .tag { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
        .tk .when { font-size:11px; color:var(--muted); }
        /* SP9 #41/#42: comms */
        #comms { gap:0; }
        #commsThread { display:flex; flex-direction:column-reverse; gap:8px; padding-bottom:12px; }
        .cm { border:1px solid var(--line); border-radius:12px; padding:10px 12px; background:var(--card); }
        .cm.mine { border-color:var(--accent); }
        .cm.broadcast { border-left:4px solid var(--accent); background:rgba(122,162,255,.08); }
        .cm .who { font-size:11px; color:var(--muted); margin-bottom:2px; }
        .cm .who .b { color:var(--accent); font-weight:700; }
        .comms-compose { position:sticky; bottom:0; background:var(--bg); border-top:1px solid var(--line); padding:10px 0; display:grid; gap:8px; }
        .comms-actions { display:flex; gap:8px; }
        .comms-actions .advance { flex:1; }
        /* SP4 #18: new-order sheet */
        .sheet { position:fixed; inset:0; z-index:40; background:var(--bg); display:none; flex-direction:column; }
        .sheet.show { display:flex; }
        .sheet-head { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--line); }
        .sheet-body { flex:1; overflow:auto; padding:12px; }
        .sheet-foot { border-top:1px solid var(--line); padding:12px 16px; display:grid; gap:10px; }
        .no-input { width:100%; padding:14px; border-radius:12px; border:1px solid var(--line); background:var(--card); color:var(--fg); font-size:16px; margin-bottom:12px; }
        .no-menu { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .no-item { text-align:start; border:1px solid var(--line); background:var(--card); color:var(--fg); border-radius:12px; padding:12px; }
        .no-item .p { color:var(--muted); font-size:13px; margin-top:4px; }
        .no-item.qty { border-color:var(--accent); }
        .no-item .badge-qty { float:inline-end; color:var(--accent); font-weight:700; }
        .no-item[disabled] { opacity:.4; }
        .no-cart { color:var(--muted); font-size:14px; }
        /* SP10 #46: idle auto-lock overlay */
        .lock { position:fixed; inset:0; z-index:50; background:rgba(10,10,16,.94); display:none; flex-direction:column; align-items:center; justify-content:center; gap:14px; text-align:center; padding:24px; }
        .lock.show { display:flex; }
        .lock .big { font-size:22px; font-weight:700; }
        .lock .sub { color:var(--muted); }
        .lock button { border:0; border-radius:12px; padding:16px 28px; font-size:16px; font-weight:700; color:#12121a; background:var(--accent); }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
    <header>
        <h1><span class="dot" id="live"></span>Orders</h1>
        <div style="display:flex;gap:8px;align-items:center">
            <!-- SP11 #49: branch switcher (shown only when the restaurant has >1 branch) -->
            <select id="branchSel" class="enable" style="display:none;background:var(--card)" aria-label="Branch"></select>
            <button class="enable" id="newOrderBtn" style="border-color:var(--accent);color:var(--accent)">+ New order</button>
            <button class="enable" id="enablePush">Enable alerts</button>
            {{-- DOCS-PLATFORM D1-7: staff help — opens the staff-app guide. --}}
            <a href="{{ route('help.show', 'staff-app') }}" target="_blank" rel="noopener" title="Help" aria-label="Help" style="text-decoration:none;color:var(--muted);font-size:18px;align-self:center">?</a>
        </div>
    </header>
    <!-- FP7 #42: Orders / Floor view switch -->
    <nav class="filters" id="views">
        <button class="chip vchip active" data-v="orders">Orders</button>
        <button class="chip vchip" data-v="floor">Floor</button>
        <button class="chip vchip" data-v="kds">Kitchen</button>
        <button class="chip vchip" data-v="tasks">Tasks</button>
        <button class="chip vchip" data-v="comms">Comms</button>
        {{-- UI-W: Tableside is shown only when the store is entitled to waiter_mode AND
             this staff member has the `orders` permission (same test waiterGuard() runs). --}}
        @if (!empty($canWaiter))
            <button class="chip vchip" data-v="waiter">Tableside</button>
        @endif
        {{-- UI-FS: food-safety logging, shown only when the store is entitled. --}}
        @if (!empty($canFoodSafety))
            <button class="chip vchip" data-v="safety">Safety</button>
        @endif
    </nav>
    <nav class="filters" id="filters">
        <button class="chip active" data-f="all">All</button>
        <button class="chip" data-f="new">New</button>
        <button class="chip" data-f="preparing">Preparing</button>
        <button class="chip" data-f="ready">Ready</button>
        <button class="chip" data-f="dinein">Tables</button>
    </nav>
    <main id="list"><div class="empty">Loading…</div></main>

    <!-- FP7 #42: floor view -->
    <nav class="filters" id="floorbar" style="display:none">
        <label class="chip" style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
            <input type="checkbox" id="floorMine"> My tables
        </label>
    </nav>
    <main id="floor" style="display:none"><div class="empty">Loading…</div></main>

    <!-- SP5 #24-27: KDS on mobile -->
    <div id="allday" style="display:none"></div>
    <main id="kds" style="display:none"><div class="empty">Loading…</div></main>

    <!-- SP8 #36/#37: opening/closing checklists -->
    <main id="tasks" style="display:none"><div class="empty">Loading…</div></main>

    <!-- SP9 #41/#42: staff comms -->
    <main id="comms" style="display:none">
        <div id="commsThread"><div class="empty">Loading…</div></div>
        <div class="comms-compose">
            <input id="commsInput" class="no-input" style="margin:0" placeholder="Message the team…" maxlength="1000">
            <div class="comms-actions">
                <button class="advance" id="commsSend">Send</button>
                <button class="enable" id="commsBroadcast" style="display:none;border-color:var(--accent);color:var(--accent)">📢 Broadcast</button>
            </div>
        </div>
    </main>

    <!-- UI-W: waiter tableside (waiter_mode + `orders` permission) -->
    @if (!empty($canWaiter))
        <main id="waiter" style="display:none"><div class="empty">Loading…</div></main>

        <!-- UI-W: add items to an open order, with seat / course / hold -->
        <div class="sheet" id="wAdd">
            <div class="sheet-head">
                <b id="wAddTitle">Add items</b>
                <button class="enable" id="wAddClose">✕</button>
            </div>
            <div class="sheet-body">
                <div style="display:flex;gap:8px">
                    <input id="wSeat" class="no-input" inputmode="numeric" placeholder="Seat (1-99)" style="margin:0">
                    <input id="wCourse" class="no-input" inputmode="numeric" placeholder="Course (1-9)" style="margin:0">
                </div>
                <label class="chip" style="display:inline-flex;gap:6px;align-items:center;margin:10px 0">
                    <input type="checkbox" id="wHold"> Hold (fire later)
                </label>
                <div class="no-menu" id="wMenu"><div class="empty">Loading menu…</div></div>
            </div>
            <div class="sheet-foot">
                <div class="no-cart" id="wCart">No items yet</div>
                <button class="advance" id="wAddSend" disabled>Add to order</button>
            </div>
        </div>
    @endif

    <!-- UI-FS: food-safety logging (food_safety entitlement) -->
    @if (!empty($canFoodSafety))
        <main id="safety" style="display:none"><div class="empty">Loading…</div></main>
    @endif

    <!-- SP4 #18: tableside order create -->
    <div class="sheet" id="newOrder">
        <div class="sheet-head">
            <b>New table order</b>
            <button class="enable" id="newClose">✕</button>
        </div>
        <div class="sheet-body">
            <input id="noTable" class="no-input" inputmode="numeric" placeholder="Table number (optional)">
            <div class="no-menu" id="noMenu"><div class="empty">Loading menu…</div></div>
        </div>
        <div class="sheet-foot">
            <div class="no-cart" id="noCart">No items yet</div>
            <button class="advance" id="noPlace" disabled>Place order</button>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <!-- SP10 #46: idle lock -->
    <div class="lock" id="lock">
        <div class="big">Screen locked</div>
        <div class="sub">Locked after inactivity. Tap to resume — you stay signed in.</div>
        <button id="unlock">Resume</button>
    </div>

    {{-- Shared notification-sound engine (same as dashboard/POS). --}}
    <script src="{{ asset('assets/js/howler.min.js') }}?v={{ filemtime(public_path('assets/js/howler.min.js')) }}"></script>
    <script src="{{ asset('assets/js/notify-sounds.js') }}?v={{ filemtime(public_path('assets/js/notify-sounds.js')) }}"></script>
    <script nonce="{{ csp_nonce() }}">
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const VAPID_PUBLIC = @json($vapidPublicKey);
        if (window.NotifySounds) NotifySounds.init(@json($notifSounds));
        let SOUND_SEEN = null; // order ids seen (null = baseline unset → silent first poll)
        const NEXT = { new:'preparing', preparing:'ready', ready:'delivered', call_bill:'completed' };
        const LABEL = { new:'Start preparing', preparing:'Mark ready', ready:'Mark delivered', call_bill:'Mark paid' };
        const LOCK_MS = 3 * 60 * 1000; // SP10: auto-lock after 3 min idle

        let ORDERS = [];        // last fetched orders
        let FILTER = 'all';     // active filter chip
        let SKEW = 0;           // serverNow - clientNow (ms), corrects device clock drift
        const OPEN = new Set(); // expanded card ids (persist across polls)

        function toast(m){ const t=document.getElementById('toast'); t.textContent=m; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),1800); }
        function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

        // server-corrected "now"
        function now(){ return Date.now() + SKEW; }
        function waitClass(ms){ const m = ms/60000; return m >= 15 ? 'late' : (m >= 5 ? 'warn' : 'fresh'); }
        function fmt(ms){ const s=Math.max(0,Math.floor(ms/1000)); const m=Math.floor(s/60); return m+':'+String(s%60).padStart(2,'0'); }

        // SP11 #49: active branch (null = all/default). Appended to branch-scoped fetches.
        let BRANCH = null;
        function branchQ(prefix){ return BRANCH ? (prefix + 'branch=' + BRANCH) : ''; }

        async function poll(){
            try {
                const r = await fetch('/staff-app/orders' + branchQ('?'), { headers:{ 'Accept':'application/json' } });
                if (r.status === 401){ location.href='/staff/login'; return; }
                const d = await r.json();
                if (d.server_time){ SKEW = new Date(d.server_time).getTime() - Date.now(); }
                ORDERS = d.orders || [];
                // Ring on a genuinely new order (waiter gets audibly notified).
                const ids = new Set(ORDERS.map(o => o.id));
                if (SOUND_SEEN && window.NotifySounds){
                    for (const id of ids){ if (!SOUND_SEEN.has(id)){ NotifySounds.play('order_new'); break; } }
                }
                SOUND_SEEN = ids;
                render();
                document.getElementById('live').classList.add('live');
            } catch(e){ document.getElementById('live').classList.remove('live'); }
        }

        function visible(){
            return ORDERS.filter(o => {
                if (FILTER === 'all') return true;
                if (FILTER === 'dinein') return !!o.table_number;
                return o.status === FILTER;
            });
        }

        function render(){
            const list = document.getElementById('list');
            const rows = visible();
            if (!rows.length){ list.innerHTML = '<div class="empty">No matching orders 🎉</div>'; return; }
            list.innerHTML = rows.map(o => {
                const created = o.created_at ? new Date(o.created_at).getTime() : now();
                const wc = waitClass(now() - created);
                const items = (o.items||[]).map(i => `<li><b>${i.qty}×</b> ${escapeHtml(i.name)}</li>`).join('');
                const where = o.table_number ? ('Table ' + o.table_number) : (o.type||'');
                const openCls = OPEN.has(o.id) ? ' open' : '';
                // SP3 #13: new orders get accept/reject; others advance
                let actions;
                if (o.status === 'new'){
                    actions = `<div class="actions">
                        <button class="act accept" data-act="accept" data-ev data-args='[${o.id}]'>Accept</button>
                        <button class="act reject" data-act="reject" data-ev data-args='[${o.id}]'>Reject</button>
                    </div>`;
                } else if (o.status === 'call_bill'){
                    actions = `<div class="actions"><button class="act advance" data-act="advance" data-ev data-args='[${o.id},"completed"]'>Mark paid</button></div>`;
                } else if (NEXT[o.status]){
                    // SP4 #21/#22: dine-in orders can request the bill or move table
                    const dinein = o.table_number ? `<button class="act bill" data-act="requestBill" data-ev data-args='[${o.id}]'>Request bill</button><button class="act move" data-act="transferOrder" data-ev data-args='[${o.id}]'>Move</button>` : '';
                    actions = `<div class="actions"><button class="act advance" data-act="advance" data-ev data-args='[${o.id},"${NEXT[o.status]}"]'>${LABEL[o.status]}</button>${dinein}</div>`;
                } else { actions = ''; }
                const total = (o.total !== null && o.total !== undefined && o.total !== '') ? `<div class="row"><span>Total</span><b>${escapeHtml(String(o.total))}</b></div>` : '';
                return `<div class="card ${wc}${openCls}" data-id="${o.id}" data-act="toggle" data-args='[${o.id}]'>
                    <div class="top">
                        <span class="id">#${o.id}</span>
                        <span class="timer" id="t${o.id}" data-created="${created}">${fmt(now()-created)}</span>
                        <span class="badge ${o.status}">${o.status}</span>
                    </div>
                    <div class="meta">${escapeHtml(where)}${o.customer_name ? ' · ' + escapeHtml(o.customer_name) : ''}</div>
                    ${o.voice_note_url ? `<button class="act voice" data-act="playVoice" data-ev data-args='[${JSON.stringify(String(o.voice_note_url).replace(/['"]/g,''))}]'>🎤 Voice note${o.voice_note_duration ? ' · ' + o.voice_note_duration + 's' : ''}</button>` : ''}
                    ${o.voice_note_transcript ? `<div class="meta" style="white-space:normal">📝 ${escapeHtml(o.voice_note_transcript)}</div>` : ''}
                    <ul class="items">${items}</ul>
                    <div class="detail">
                        <div class="row"><span>Type</span><b>${escapeHtml(o.type||'—')}</b></div>
                        ${o.table_number ? `<div class="row"><span>Table</span><b>${escapeHtml(String(o.table_number))}</b></div>` : ''}
                        ${o.customer_name ? `<div class="row"><span>Customer</span><b>${escapeHtml(o.customer_name)}</b></div>` : ''}
                        ${total}
                    </div>
                    ${actions}
                </div>`;
            }).join('');
        }

        // FEATURES-5 Track C: play a customer voice note (signed stream URL).
        let VOICE_AUDIO = null;
        function playVoice(ev, url){
            if (ev) ev.stopPropagation();
            if (!url) return;
            if (!VOICE_AUDIO) VOICE_AUDIO = new Audio();
            VOICE_AUDIO.src = url;
            VOICE_AUDIO.play().catch(function(){});
        }

        // SP3 #12: expand/collapse detail (ignore taps that originate on a button)
        function toggle(id){
            if (event && event.target && event.target.closest('button')) return;
            if (OPEN.has(id)) OPEN.delete(id); else OPEN.add(id);
            const el = document.querySelector('.card[data-id="'+id+'"]');
            if (el) el.classList.toggle('open', OPEN.has(id));
        }

        // SP3 #11: tick timers + wait colour every second without a full re-render
        function tick(){
            document.querySelectorAll('.timer').forEach(t => {
                const created = Number(t.dataset.created||0);
                const ms = now() - created;
                t.textContent = fmt(ms);
                const card = t.closest('.card');
                if (card){ card.classList.remove('fresh','warn','late'); card.classList.add(waitClass(ms)); }
            });
        }

        async function store(id, body, okMsg){
            try {
                const r = await fetch('/staff-app/orders/'+id+'/status', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify(body)
                });
                if (!r.ok){ toast('Could not update'); return; }
                toast(okMsg); poll();
            } catch(e){ toast('Network error'); }
        }
        function advance(e, id, status){ e.stopPropagation(); store(id, { status }, 'Order #'+id+' → '+status); }
        async function transferOrder(e, id){
            e.stopPropagation();
            const table = prompt('Move order #'+id+' to which table?');
            if (table === null || table.trim() === '') return;
            try {
                const r = await fetch('/staff-app/orders/'+id+'/transfer', {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify({ table_number: table.trim() })
                });
                if (!r.ok){ toast('Could not move'); return; }
                toast('Order #'+id+' → table '+table.trim()); poll();
            } catch(e){ toast('Network error'); }
        }
        async function requestBill(e, id){
            e.stopPropagation();
            try {
                const r = await fetch('/staff-app/orders/'+id+'/request-bill', {
                    method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' }
                });
                if (!r.ok){ toast('Could not request bill'); return; }
                toast('Bill requested for #'+id); poll();
            } catch(e){ toast('Network error'); }
        }
        function accept(e, id){ e.stopPropagation(); store(id, { status:'preparing' }, 'Order #'+id+' accepted'); }
        function reject(e, id){
            e.stopPropagation();
            const reason = prompt('Reject order #'+id+' — reason?');
            if (reason === null) return; // cancelled
            store(id, { status:'cancelled', reason }, 'Order #'+id+' rejected');
        }

        // filter chips
        document.getElementById('filters').addEventListener('click', e => {
            const chip = e.target.closest('.chip'); if (!chip) return;
            FILTER = chip.dataset.f;
            document.querySelectorAll('.chip').forEach(c => c.classList.toggle('active', c===chip));
            render();
        });

        // --- SP10 #46: idle auto-lock (session stays valid; this is a screen guard) ---
        let lastActive = Date.now();
        function touch(){ lastActive = Date.now(); }
        ['pointerdown','keydown','touchstart'].forEach(ev => document.addEventListener(ev, touch, { passive:true }));
        function lockCheck(){
            const locked = document.getElementById('lock').classList.contains('show');
            if (!locked && Date.now() - lastActive > LOCK_MS){ document.getElementById('lock').classList.add('show'); }
        }
        document.getElementById('unlock').addEventListener('click', () => { document.getElementById('lock').classList.remove('show'); touch(); poll(); });

        // --- Web Push (VAPID) ---
        function urlB64ToUint8(base64){
            const pad = '='.repeat((4 - base64.length % 4) % 4);
            const b64 = (base64 + pad).replace(/-/g,'+').replace(/_/g,'/');
            const raw = atob(b64); return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)));
        }
        document.getElementById('enablePush').addEventListener('click', async () => {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !VAPID_PUBLIC){ toast('Push not supported'); return; }
            try {
                const reg = await navigator.serviceWorker.ready;
                const perm = await Notification.requestPermission();
                if (perm !== 'granted'){ toast('Alerts blocked'); return; }
                const sub = await reg.pushManager.subscribe({ userVisibleOnly:true, applicationServerKey: urlB64ToUint8(VAPID_PUBLIC) });
                await fetch('/staff-app/push/subscribe', {
                    method:'POST',
                    headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF },
                    body: JSON.stringify(sub.toJSON())
                });
                toast('Alerts enabled');
            } catch(e){ toast('Could not enable alerts'); }
        });

        // --- SP4 #18: tableside order create ---
        let MENU = [], CART = {}; // CART: {itemId: qty}
        const sheet = document.getElementById('newOrder');
        function openNew(table){ CART = {}; document.getElementById('noTable').value = (typeof table === 'string' ? table : ''); sheet.classList.add('show'); loadMenu(); renderCart(); }
        function closeNew(){ sheet.classList.remove('show'); }
        document.getElementById('newOrderBtn').addEventListener('click', openNew);
        document.getElementById('newClose').addEventListener('click', closeNew);

        async function loadMenu(){
            const box = document.getElementById('noMenu');
            if (MENU.length){ renderMenu(); return; }
            try {
                const d = await (await fetch('/staff-app/menu', { headers:{ Accept:'application/json' } })).json();
                MENU = d.items || []; renderMenu();
            } catch(e){ box.innerHTML = '<div class="empty">Could not load menu</div>'; }
        }
        function renderMenu(){
            const box = document.getElementById('noMenu');
            if (!MENU.length){ box.innerHTML = '<div class="empty">No items</div>'; return; }
            box.innerHTML = MENU.map(m => {
                const q = CART[m.id] || 0;
                const dis = m.sold_out ? 'disabled' : '';
                return `<button class="no-item ${q?'qty':''}" ${dis} data-act="addItem" data-args='[${m.id}]'>
                    ${q?`<span class="badge-qty">${q}×</span>`:''}
                    <div>${escapeHtml(m.name)}</div>
                    <div class="p">${m.price}${m.sold_out?' · sold out':''}</div>
                </button>`;
            }).join('');
        }
        function addItem(id){ CART[id] = (CART[id]||0) + 1; renderMenu(); renderCart(); }
        function renderCart(){
            const n = Object.values(CART).reduce((a,b)=>a+b,0);
            document.getElementById('noCart').textContent = n ? (n + ' item' + (n>1?'s':'') + ' in order') : 'No items yet';
            document.getElementById('noPlace').disabled = n === 0;
        }
        document.getElementById('noPlace').addEventListener('click', async function(){
            const items = Object.entries(CART).map(([id,q]) => ({ item_id: Number(id), quantity: q }));
            if (!items.length) return;
            this.disabled = true;
            try {
                const r = await fetch('/staff-app/table-orders', {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify({ table_number: document.getElementById('noTable').value || null, items })
                });
                if (r.ok){ const d = await r.json(); toast('Order #'+d.id+' created'); closeNew(); poll(); }
                else { const e = await r.json().catch(()=>({})); toast(e.error || 'Could not create'); this.disabled=false; }
            } catch(e){ toast('Network error'); this.disabled=false; }
        });

        // --- FP7 #42: Orders / Floor view ---
        let VIEW = 'orders';
        document.getElementById('views').addEventListener('click', function(e){
            const c = e.target.closest('.vchip'); if (!c) return;
            VIEW = c.dataset.v;
            document.querySelectorAll('.vchip').forEach(x => x.classList.toggle('active', x===c));
            const floor = VIEW === 'floor', kds = VIEW === 'kds', tasks = VIEW === 'tasks', comms = VIEW === 'comms';
            const waiter = VIEW === 'waiter', safety = VIEW === 'safety';
            const alt = floor || kds || tasks || comms || waiter || safety;
            document.getElementById('filters').style.display = alt ? 'none' : '';
            document.getElementById('list').style.display = alt ? 'none' : '';
            document.getElementById('floorbar').style.display = floor ? '' : 'none';
            document.getElementById('floor').style.display = floor ? '' : 'none';
            document.getElementById('kds').style.display = kds ? '' : 'none';
            document.getElementById('allday').style.display = kds ? 'flex' : 'none';
            document.getElementById('tasks').style.display = tasks ? '' : 'none';
            document.getElementById('comms').style.display = comms ? '' : 'none';
            const wEl = document.getElementById('waiter'), sEl = document.getElementById('safety');
            if (wEl) wEl.style.display = waiter ? '' : 'none';
            if (sEl) sEl.style.display = safety ? '' : 'none';
            if (floor) loadFloor();
            if (kds) loadKds();
            if (tasks) loadTasks();
            if (comms) loadComms();
            if (waiter && wEl) loadWaiter();
            if (safety && sEl) loadSafety();
        });
        document.getElementById('floorMine').addEventListener('change', loadFloor);
        async function loadFloor(){
            const box = document.getElementById('floor');
            const mineOn = document.getElementById('floorMine').checked;
            const params = [mineOn ? 'mine=1' : '', BRANCH ? 'branch=' + BRANCH : ''].filter(Boolean).join('&');
            const qs = params ? ('?' + params) : '';
            try {
                const d = await (await fetch('/staff-app/floor' + qs, { headers:{ Accept:'application/json' } })).json();
                renderFloor(d.tables || []);
            } catch(e){ box.innerHTML = '<div class="empty">Could not load floor</div>'; }
        }
        let FLOOR_TILES = []; // XSS-safe: tap handler indexes this array, never inlines the object
        function renderFloor(tables){
            const box = document.getElementById('floor');
            if (!tables.length){ box.innerHTML = '<div class="empty">No tables</div>'; return; }
            FLOOR_TILES = tables;
            box.innerHTML = tables.map((t, i) => `<div class="ftile ${t.status} ${t.mine?'mine':''}" data-act="floorTap" data-args='[${i}]'>
                <div class="tn">T${escapeHtml(String(t.table_no))}</div>
                <div class="ts">${escapeHtml(t.status)}</div>
                ${t.section ? `<div class="sec">${escapeHtml(t.section)}</div>` : ''}
                ${t.note ? `<div class="sec" title="${escapeHtml(t.note)}">📌 ${escapeHtml(t.note)}</div>` : ''}
            </div>`).join('');
        }
        // FP8 #43/#47: tap a floor tile to act on it.
        function floorTap(i){
            const t = FLOOR_TILES[i];
            if (!t) return;
            if (t.order_id){
                if (t.status !== 'call_bill' && confirm('Request bill for table '+t.table_no+'?')){
                    fetch('/staff-app/orders/'+t.order_id+'/request-bill', { method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' } })
                        .then(r => { toast(r.ok ? 'Bill requested' : 'Could not request'); loadFloor(); });
                }
            } else {
                // free table → start an order for it
                openNew(String(t.table_no));
            }
        }

        // --- SP5 #24-27: KDS on mobile ---
        const KNEXT = { queued:'Start', preparing:'Bump ready', ready:'Done' };
        async function loadKds(){
            const box = document.getElementById('kds');
            try {
                const d = await (await fetch('/staff-app/kds', { headers:{ Accept:'application/json' } })).json();
                renderAllDay(d.all_day || []);
                renderKds(d.tickets || []);
            } catch(e){ box.innerHTML = '<div class="empty">Could not load kitchen</div>'; }
        }
        function renderAllDay(rows){
            const bar = document.getElementById('allday');
            bar.innerHTML = rows.length ? rows.map(r => `<span class="ad">${escapeHtml(r.name)} <b>${r.qty}</b></span>`).join('') : '';
        }
        function renderKds(tickets){
            const box = document.getElementById('kds');
            if (!tickets.length){ box.innerHTML = '<div class="empty">Kitchen is clear 🎉</div>'; return; }
            box.innerHTML = tickets.map(t => {
                const created = t.placed_at ? new Date(t.placed_at).getTime() : now();
                const where = t.table_number ? ('Table ' + t.table_number) : (t.type || '—');
                const lines = (t.items||[]).map(i => {
                    const act = i.status === 'ready'
                        ? `<button class="kb bump" data-act="kdsStep" data-ev data-args='[${i.id},"bump"]'>Done</button>`
                        : `<button class="kb bump" data-act="kdsStep" data-ev data-args='[${i.id},"bump"]'>${KNEXT[i.status]||'Bump'}</button>`;
                    const back = i.status !== 'queued' ? `<button class="kb" data-act="kdsStep" data-ev data-args='[${i.id},"recall"]'>↩</button>` : '';
                    return `<div class="kt-line ${i.status}">
                        <span class="qn"><b>${i.quantity}×</b> ${escapeHtml(i.name||'—')}${i.note ? `<span class="note">${escapeHtml(i.note)}</span>` : ''}</span>
                        <span class="st">${escapeHtml(i.status)}</span>
                        ${back}${act}
                    </div>`;
                }).join('');
                return `<div class="kt">
                    <div class="kt-head"><span class="where">${escapeHtml(where)} · #${t.order_id}</span><span class="ago">${fmt(now()-created)}</span></div>
                    ${lines}
                </div>`;
            }).join('');
        }
        async function kdsStep(e, itemId, action){
            e.stopPropagation();
            try {
                const r = await fetch('/staff-app/kds/items/'+itemId+'/'+action, { method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' } });
                if (!r.ok){ toast('Could not update'); return; }
                loadKds();
            } catch(err){ toast('Could not update'); }
        }

        // --- SP8 #36/#37: opening/closing checklists ---
        async function loadTasks(){
            const box = document.getElementById('tasks');
            try {
                const d = await (await fetch('/staff-app/tasks', { headers:{ Accept:'application/json' } })).json();
                renderTasks(d.tasks || []);
            } catch(e){ box.innerHTML = '<div class="empty">Could not load tasks</div>'; }
        }
        function renderTasks(tasks){
            const box = document.getElementById('tasks');
            if (!tasks.length){ box.innerHTML = '<div class="empty">No checklist items 🎉</div>'; return; }
            box.innerHTML = tasks.map(t => {
                const when = t.completed_at ? new Date(t.completed_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
                const tag = [t.section, t.role].filter(Boolean).map(escapeHtml).join(' · ');
                return `<div class="tk ${t.done?'done':''}" data-act="toggleTask" data-args='[${t.id},${t.done?1:0}]'>
                    <div class="box">${t.done?'✓':''}</div>
                    <div class="tt">${escapeHtml(t.title)}${tag?`<div class="tag">${tag}</div>`:''}</div>
                    ${t.done?`<div class="when">${when}</div>`:''}
                </div>`;
            }).join('');
        }
        async function toggleTask(id, done){
            const action = done ? 'uncomplete' : 'complete';
            try {
                const r = await fetch('/staff-app/tasks/'+id+'/'+action, { method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' } });
                if (!r.ok){ toast('Could not update'); return; }
                loadTasks();
            } catch(e){ toast('Could not update'); }
        }

        // --- SP9 #41/#42: staff comms ---
        const IS_MANAGER = @json(($staffRole ?? '') === 'manager');
        if (IS_MANAGER) { document.getElementById('commsBroadcast').style.display = ''; }
        async function loadComms(){
            const box = document.getElementById('commsThread');
            try {
                const d = await (await fetch('/staff-app/messages', { headers:{ Accept:'application/json' } })).json();
                renderComms(d.messages || []);
            } catch(e){ box.innerHTML = '<div class="empty">Could not load messages</div>'; }
        }
        function renderComms(msgs){
            const box = document.getElementById('commsThread');
            if (!msgs.length){ box.innerHTML = '<div class="empty">No messages yet</div>'; return; }
            // messages arrive newest-first; column-reverse renders them oldest→newest visually
            box.innerHTML = msgs.map(m => {
                const t = m.created_at ? new Date(m.created_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
                const who = m.kind === 'broadcast' ? ('<span class="b">📢 ' + escapeHtml(m.author||'Manager') + '</span>') : escapeHtml(m.author||'—');
                return `<div class="cm ${m.mine?'mine':''} ${m.kind==='broadcast'?'broadcast':''}">
                    <div class="who">${who} · ${t}</div>
                    <div>${escapeHtml(m.body)}</div>
                </div>`;
            }).join('');
        }
        async function sendComms(broadcast){
            const input = document.getElementById('commsInput');
            const body = input.value.trim();
            if (!body) return;
            try {
                const r = await fetch(broadcast ? '/staff-app/broadcast' : '/staff-app/messages', {
                    method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Content-Type':'application/json', 'Accept':'application/json' },
                    body: JSON.stringify({ body })
                });
                if (!r.ok){ toast(r.status===403?'Managers only':'Could not send'); return; }
                input.value = '';
                if (broadcast) toast('Broadcast sent');
                loadComms();
            } catch(e){ toast('Could not send'); }
        }
        document.getElementById('commsSend').addEventListener('click', () => sendComms(false));
        document.getElementById('commsBroadcast').addEventListener('click', () => sendComms(true));
        document.getElementById('commsInput').addEventListener('keydown', e => { if (e.key==='Enter') sendComms(false); });

        // --- UI-W: waiter tableside -------------------------------------------------
        // Contracts (StaffAppController::waiter*, all behind waiterGuard()):
        //   GET  /staff-app/waiter/floor                    -> {tables:[{table,order_id,state}]}
        //   GET  /staff-app/waiter/calls                    -> {calls:[{id,table_no,type}]}
        //   POST /staff-app/waiter/calls/{id}/ack           -> {ok:true}
        //   POST /staff-app/waiter/orders/{id}/items        {items:[{item_id,quantity,seat,course}],hold} -> 201 {added}
        //   POST /staff-app/waiter/orders/{id}/fire         {course:int 1..9} -> {fired:int}
        //   GET  /staff-app/waiter/orders/{id}/split-seat   -> {seats:[{seat,subtotal}]}
        //   POST /staff-app/waiter/orders/{id}/merge        {into_order_id:int} -> {ok,into}
        const CAN_WAITER = @json(!empty($canWaiter));
        let WTABLES = [], WCALLS = [];
        async function loadWaiter(){
            const box = document.getElementById('waiter');
            if (!box) return;
            try {
                const [f, c] = await Promise.all([
                    fetch('/staff-app/waiter/floor', { headers:{ Accept:'application/json' } }),
                    fetch('/staff-app/waiter/calls', { headers:{ Accept:'application/json' } })
                ]);
                if (f.status === 403 || c.status === 403){ box.innerHTML = '<div class="empty">Tableside is not available for this store</div>'; return; }
                WTABLES = (await f.json()).tables || [];
                WCALLS  = (await c.json()).calls || [];
                renderWaiter();
            } catch(e){ box.innerHTML = '<div class="empty">Could not load tableside</div>'; }
        }
        function renderWaiter(){
            const box = document.getElementById('waiter');
            const calls = WCALLS.length
                ? WCALLS.map(c => `<div class="card warn"><div class="top"><span class="id">T${escapeHtml(String(c.table_no))}</span><span class="badge new">${escapeHtml(String(c.type||'call'))}</span></div><div class="actions"><button class="act advance" data-act="wAck" data-ev data-args='[${c.id}]'>Acknowledge</button></div></div>`).join('')
                : '';
            const tables = WTABLES.length
                ? WTABLES.map((t, i) => `<div class="card" data-wt="${i}">
                    <div class="top"><span class="id">T${escapeHtml(String(t.table))}</span><span class="badge ${escapeHtml(String(t.state))}">${escapeHtml(String(t.state))}</span></div>
                    <div class="meta">Order #${t.order_id}</div>
                    <div class="actions">
                        <button class="act advance" data-act="wOpenAdd" data-ev data-args='[${t.order_id},${JSON.stringify(String(t.table).replace(/['"]/g,''))}]'>Add items</button>
                        <button class="act bill" data-act="wFire" data-ev data-args='[${t.order_id}]'>Fire course</button>
                        <button class="act move" data-act="wSplit" data-ev data-args='[${t.order_id}]'>Split by seat</button>
                        <button class="act move" data-act="wMerge" data-ev data-args='[${t.order_id}]'>Merge</button>
                    </div>
                </div>`).join('')
                : '<div class="empty">No open tables</div>';
            box.innerHTML = calls + tables;
        }
        async function wAck(e, id){
            e.stopPropagation();
            try {
                const r = await fetch('/staff-app/waiter/calls/'+id+'/ack', { method:'POST', headers:{ 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' } });
                toast(r.ok ? 'Call acknowledged' : 'Could not acknowledge');
                if (r.ok) loadWaiter();
            } catch(err){ toast('Network error'); }
        }
        async function wFire(e, orderId){
            e.stopPropagation();
            const course = prompt('Fire which course? (1-9)', '1');
            if (course === null) return;
            const n = parseInt(course, 10);
            if (!(n >= 1 && n <= 9)){ toast('Course must be 1-9'); return; }
            try {
                const r = await fetch('/staff-app/waiter/orders/'+orderId+'/fire', {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify({ course: n })
                });
                if (!r.ok){ toast('Could not fire course'); return; }
                const d = await r.json();
                toast('Fired ' + (d.fired || 0) + ' item(s)');
                loadWaiter();
            } catch(err){ toast('Network error'); }
        }
        async function wSplit(e, orderId){
            e.stopPropagation();
            try {
                const r = await fetch('/staff-app/waiter/orders/'+orderId+'/split-seat', { headers:{ Accept:'application/json' } });
                if (!r.ok){ toast('Could not split'); return; }
                const seats = (await r.json()).seats || [];
                const box = document.getElementById('waiter');
                const rows = seats.length
                    ? seats.map(s => `<div class="row"><span>Seat ${escapeHtml(String(s.seat))}</span><b>${escapeHtml(String(s.subtotal))}</b></div>`).join('')
                    : '<div class="row"><span>No seated items</span><b>—</b></div>';
                box.insertAdjacentHTML('afterbegin', `<div class="card open"><div class="top"><span class="id">#${orderId} split</span></div><div class="detail">${rows}</div></div>`);
            } catch(err){ toast('Network error'); }
        }
        async function wMerge(e, orderId){
            e.stopPropagation();
            const into = prompt('Merge order #'+orderId+' into which order id?');
            if (into === null) return;
            const n = parseInt(into, 10);
            if (!n){ toast('Enter an order id'); return; }
            try {
                const r = await fetch('/staff-app/waiter/orders/'+orderId+'/merge', {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify({ into_order_id: n })
                });
                if (!r.ok){ toast('Could not merge'); return; }
                toast('Merged into #' + n);
                loadWaiter();
            } catch(err){ toast('Network error'); }
        }
        // add-items sheet (reuses the same /staff-app/menu feed as the new-order sheet)
        let WORDER = null, WCART = {};
        function wOpenAdd(e, orderId, table){
            if (e) e.stopPropagation();
            WORDER = orderId; WCART = {};
            document.getElementById('wAddTitle').textContent = 'Add to #' + orderId + (table ? ' · T' + table : '');
            document.getElementById('wAdd').classList.add('show');
            wRenderCart();
            if (MENU.length) wRenderMenu(); else loadMenu().then(wRenderMenu);
        }
        function wRenderMenu(){
            const box = document.getElementById('wMenu');
            if (!box) return;
            if (!MENU.length){ box.innerHTML = '<div class="empty">No items</div>'; return; }
            box.innerHTML = MENU.map(m => {
                const q = WCART[m.id] || 0;
                return `<button class="no-item ${q?'qty':''}" ${m.sold_out?'disabled':''} data-act="wAddItem" data-args='[${m.id}]'>
                    ${q?`<span class="badge-qty">${q}×</span>`:''}
                    <div>${escapeHtml(m.name)}</div>
                    <div class="p">${m.price}${m.sold_out?' · sold out':''}</div>
                </button>`;
            }).join('');
        }
        function wAddItem(id){ WCART[id] = (WCART[id]||0) + 1; wRenderMenu(); wRenderCart(); }
        function wRenderCart(){
            const n = Object.values(WCART).reduce((a,b)=>a+b,0);
            document.getElementById('wCart').textContent = n ? (n + ' item' + (n>1?'s':'') + ' to add') : 'No items yet';
            document.getElementById('wAddSend').disabled = n === 0;
        }
        if (CAN_WAITER){
            document.getElementById('wAddClose').addEventListener('click', () => document.getElementById('wAdd').classList.remove('show'));
            document.getElementById('wAddSend').addEventListener('click', async function(){
                if (!WORDER) return;
                const seat = parseInt(document.getElementById('wSeat').value, 10);
                const course = parseInt(document.getElementById('wCourse').value, 10);
                const items = Object.entries(WCART).map(([id, q]) => {
                    const line = { item_id: Number(id), quantity: q };
                    if (seat >= 1 && seat <= 99) line.seat = seat;       // validator: nullable|integer|min:1|max:99
                    if (course >= 1 && course <= 9) line.course = course; // validator: nullable|integer|min:1|max:9
                    return line;
                });
                if (!items.length) return;
                this.disabled = true;
                try {
                    const r = await fetch('/staff-app/waiter/orders/'+WORDER+'/items', {
                        method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                        body: JSON.stringify({ items, hold: document.getElementById('wHold').checked })
                    });
                    if (!r.ok){ toast('Could not add items'); this.disabled = false; return; }
                    const d = await r.json();
                    toast('Added ' + (d.added || 0) + ' item(s)');
                    document.getElementById('wAdd').classList.remove('show');
                    WCART = {}; wRenderCart(); loadWaiter(); poll();
                } catch(err){ toast('Network error'); this.disabled = false; }
            });
        }

        // --- UI-FS: food-safety logging ---------------------------------------------
        // Contracts (StaffAppController, all 403 without the food_safety entitlement):
        //   GET  /staff-app/food-safety            -> {checks:[{id,name,kind,target_min,target_max}]}
        //   POST /staff-app/food-safety/{checkId}  {value?:numeric, note?:string<=500} -> {ok,breach}
        //   POST /staff-app/food-safety-incident   {note:string required <=1000}       -> {ok}
        const CAN_SAFETY = @json(!empty($canFoodSafety));
        let CHECKS = [];
        async function loadSafety(){
            const box = document.getElementById('safety');
            if (!CAN_SAFETY || !box) return;
            try {
                const r = await fetch('/staff-app/food-safety', { headers:{ Accept:'application/json' } });
                if (r.status === 403){ box.innerHTML = '<div class="empty">Food safety is not available for this store</div>'; return; }
                CHECKS = (await r.json()).checks || [];
                renderSafety();
            } catch(e){ box.innerHTML = '<div class="empty">Could not load checks</div>'; }
        }
        function renderSafety(){
            const box = document.getElementById('safety');
            const list = CHECKS.length ? CHECKS.map(c => {
                const range = (c.target_min !== null && c.target_min !== undefined) || (c.target_max !== null && c.target_max !== undefined)
                    ? `<div class="meta">Target ${escapeHtml(String(c.target_min ?? '—'))} – ${escapeHtml(String(c.target_max ?? '—'))}</div>` : '';
                return `<div class="card">
                    <div class="top"><span class="id">${escapeHtml(c.name)}</span><span class="badge ${escapeHtml(String(c.kind||''))}">${escapeHtml(String(c.kind||''))}</span></div>
                    ${range}
                    <div class="actions"><button class="act advance" data-act="logCheck" data-ev data-args='[${c.id}]'>Log reading</button></div>
                </div>`;
            }).join('') : '<div class="empty">All checks logged for today 🎉</div>';
            box.innerHTML = list + `<div class="actions" style="padding:12px"><button class="act reject" data-act="logIncident" data-ev>Report incident</button></div>`;
        }
        async function logCheck(e, checkId){
            e.stopPropagation();
            const raw = prompt('Reading (number, blank for none)?');
            if (raw === null) return;
            const note = prompt('Note (optional)?') || '';
            const body = {};
            if (raw.trim() !== ''){
                const v = Number(raw);
                if (Number.isNaN(v)){ toast('Reading must be a number'); return; } // validator: nullable|numeric
                body.value = v;
            }
            if (note.trim() !== '') body.note = note.trim().slice(0, 500); // validator: max:500
            try {
                const r = await fetch('/staff-app/food-safety/'+checkId, {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify(body)
                });
                if (!r.ok){ toast(r.status === 403 ? 'Not available' : 'Could not log'); return; }
                const d = await r.json();
                toast(d.breach ? '⚠️ Out of range — breach logged' : 'Logged');
                loadSafety();
            } catch(err){ toast('Network error'); }
        }
        async function logIncident(e){
            if (e) e.stopPropagation();
            const note = prompt('Describe the incident');
            if (note === null || note.trim() === '') return; // validator: required|string|max:1000
            try {
                const r = await fetch('/staff-app/food-safety-incident', {
                    method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN':CSRF, 'Accept':'application/json' },
                    body: JSON.stringify({ note: note.trim().slice(0, 1000) })
                });
                toast(r.ok ? 'Incident reported' : 'Could not report');
            } catch(err){ toast('Network error'); }
        }

        // --- SP11 #49: branch switcher ---
        async function loadBranches(){
            try {
                const d = await (await fetch('/staff-app/branches', { headers:{ Accept:'application/json' } })).json();
                const branches = d.branches || [];
                if (branches.length < 2) return; // nothing to switch between
                BRANCH = d.active || branches[0].id;
                const sel = document.getElementById('branchSel');
                sel.innerHTML = branches.map(b => `<option value="${b.id}" ${b.id===BRANCH?'selected':''}>${escapeHtml(b.name)}</option>`).join('');
                sel.style.display = '';
                sel.addEventListener('change', function(){
                    BRANCH = parseInt(this.value, 10) || null;
                    poll();                         // re-scope the live feed
                    if (VIEW === 'floor') loadFloor();
                });
            } catch(e){ /* single-branch or offline: no switcher */ }
        }
        loadBranches();

        if ('serviceWorker' in navigator){ navigator.serviceWorker.register('/staff-app/sw.js', { scope:'/staff-app' }); }
        poll();
        setInterval(poll, 8000);
        setInterval(tick, 1000);
        setInterval(lockCheck, 1000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    </script>
</body>
</html>
