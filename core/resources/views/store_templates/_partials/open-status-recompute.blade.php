{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Client-side open/closed recompute — shared by the self-contained vertical
     theme layouts (aurelia, zevoa, lumiere, maison, torque, carely, freshly),
     which do NOT extend the food store_templates/layout.blade.php but need the
     same cache-safe status chip. The food layout carries its own inline copy of
     this block; this partial is the reusable version for the vertical layouts.

     Open/closed status is recomputed CLIENT-side from the store's hours +
     timezone so the full-page cache never freezes a stale "Open now"/"Closed"
     into the HTML. The payload is time-INDEPENDENT (safe to cache); the script
     does the per-visitor calculation and updates every [data-mb-openchip].
     Absent for always-open stores. Uses @if-with-assignment (not a @php block,
     which does not compile in this region). --}}
@if($__mbHours = store_hours_payload($store ?? null))
<script type="application/json" id="mb-hours-data" nonce="{{ csp_nonce() }}">@json($__mbHours)</script>
<script nonce="{{ csp_nonce() }}">(function () {
    var el = document.getElementById('mb-hours-data');
    if (!el) { return; }
    var d;
    try { d = JSON.parse(el.textContent); } catch (e) { return; }
    var DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    var DOW = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };

    function nowInTz(tz) {
        var parts = new Intl.DateTimeFormat('en-GB', { timeZone: tz || 'UTC', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
        var m = {};
        parts.forEach(function (p) { m[p.type] = p.value; });
        var hh = (m.hour === '24') ? '00' : m.hour; // some engines emit 24 at midnight
        return { idx: DOW[m.weekday], cur: hh + ':' + m.minute };
    }
    function hoursStr(day) {
        // Mirrors storefront_hours_today(): today's window, LTR-isolated, or null.
        if (!day || day.closed || !day.open || !day.close) { return null; }
        return '⁦' + day.open + '–' + day.close + '⁩';
    }
    // Mirrors OpeningHoursService::isOpenAt() + the manual force-closed switch.
    function compute() {
        if (d.manualClosed) { return { open: false, hours: null }; }
        // P6-290: a closed branch_hour_exception for today overrides the weekly
        // entry. The server computes this once per render (safe to cache) and
        // hands the flag in the payload; honour it here.
        if (d.closedToday) { return { open: false, hours: null }; }
        if (!d.week) { return { open: true, hours: null }; }
        var n;
        try { n = nowInTz(d.tz); } catch (e) { return null; }
        if (n.idx == null) { return null; }
        var today = d.week[DAYS[n.idx]] || null;
        var prev = d.week[DAYS[(n.idx + 6) % 7]] || null;
        var todayHours = hoursStr(today);
        if (prev && !prev.closed && prev.open && prev.close && prev.close < prev.open && n.cur <= prev.close) {
            return { open: true, hours: todayHours };
        }
        if (today === null) { return null; }            // unknown → leave server value
        if (today.closed) { return { open: false, hours: null }; }
        if (!today.open || !today.close) { return null; }
        var overnight = today.close < today.open;
        var isOpen = overnight ? (n.cur >= today.open || n.cur <= today.close) : (n.cur >= today.open && n.cur <= today.close);
        return { open: isOpen, hours: todayHours };
    }
    function apply() {
        var st = compute();
        if (!st) { return; }                            // unknown → don't touch
        window.MB_STORE_OPEN = st.open;
        try { document.dispatchEvent(new CustomEvent('mb:store-open', { detail: { open: st.open } })); } catch (e) {}
        var chips = document.querySelectorAll('[data-mb-openchip]');
        for (var i = 0; i < chips.length; i++) {
            var chip = chips[i];
            var t = chip.querySelector('[data-mb-open-text]');
            if (!t) { continue; }
            t.textContent = (st.open ? d.labels.open : d.labels.closed) + (st.hours ? ' · ' + st.hours : '');
            var cc = chip.getAttribute('data-mb-closed-class') || 'closed';
            chip.classList.toggle(cc, !st.open);
        }
    }
    apply();
    setTimeout(apply, 0);                                // catch any late-rendered chip
})();</script>
@endif
