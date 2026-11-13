{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared notification-center panel (rev/notif-center-spec.md).
     The THEME renders its own bell <button id="mbNotifBell"> (+ optional dot
     <i id="mbNotifDot" hidden>) in its topbar, styled to match its icon row.
     This partial renders the panel + wires mb-notify.js (loaded in layout).
     Logged-in diner → account inbox endpoint; guest → window.MB_NOTIFY_RECENT
     (a theme may set it from its own recent-orders list; absent = the shared
     mb_recent_orders_<store> list the order-track page writes).

     Structure + geometry live here once (responsive: bottom sheet on phones, a
     centered dialog on desktop). Each theme only RECOLORS it, by setting the
     --nb-* custom properties on .mb-nb-card in its own stylesheet — so every
     storefront gets the same polished panel in its own palette.
     Include once per page, anywhere in the body. --}}
<div class="mb-nb-panel" id="mbNotifPanel" role="dialog" aria-modal="true"
     aria-label="{{ ___('Notifications') }}" hidden>
  <div class="mb-nb-card" tabindex="-1">
    <span class="mb-nb-grip" aria-hidden="true"></span>
    <div class="mb-nb-head">
      <span class="mb-nb-h-l">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <b data-mb-nb-title>{{ ___('Notifications') }}</b>
      </span>
      <span class="mb-nb-h-r">
        <button type="button" class="mb-nb-mark" data-mb-nb-mark hidden>{{ ___('Mark all read') }}</button>
        <button type="button" class="mb-nb-x" data-mb-nb-close aria-label="{{ ___('Close') }}">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
      </span>
    </div>
    <div class="mb-nb-list" data-mb-nb-list></div>
  </div>
</div>

<style nonce="{{ csp_nonce() }}">
  /* Plain .mb-nb-* selectors (NOT :where) on purpose: several themes ship a
     universal reset like `.theme *{margin:0;padding:0}` (specificity 0,1,0) which
     would flatten the panel's own padding if these rules were zero-specificity.
     Matching that specificity + loading after the theme sheet (this inline block
     is in the body) wins the tie by source order. Theme skins only set the --nb-* custom
     properties consumed below, so they never collide with these structural rules.
     Every color reads a --nb-* prop with a system fallback → an unskinned template
     still renders cleanly. box-sizing is pinned so a theme reset can't resize the
     card. Never use inline styles here. */
  /* CLOSED-STATE SAFETY: the panel is a full-viewport fixed layer. This class rule
     would otherwise beat the [hidden] attribute (same specificity, later in source),
     leaving an invisible opacity:0 layer over the whole page that swallows every
     click. Honour [hidden] (mb-notify.js sets panel.hidden), and keep it
     click-through until it is actually open (covers the fade-out window too). */
  .mb-nb-panel[hidden]{display:none}
  .mb-nb-panel{position:fixed;inset:0;z-index:9000;display:flex;justify-content:center;align-items:flex-end;
    background:rgba(10,12,16,.46);opacity:0;pointer-events:none;transition:opacity .18s ease}
  @supports (backdrop-filter:blur(2px)){.mb-nb-panel{backdrop-filter:saturate(1.05) blur(2px)}}
  .mb-nb-panel.is-open{opacity:1;pointer-events:auto}
  .mb-nb-panel *,.mb-nb-panel{box-sizing:border-box}

  .mb-nb-card{--_r:var(--nb-radius,18px);color-scheme:light dark;
    width:min(440px,100vw);max-height:min(80vh,620px);
    background:var(--nb-surface,Canvas);color:var(--nb-ink,CanvasText);
    border:1px solid var(--nb-line,rgba(128,128,128,.22));border-bottom:0;
    border-radius:var(--_r) var(--_r) 0 0;box-shadow:0 -22px 60px -24px rgba(0,0,0,.55);
    display:flex;flex-direction:column;overflow:hidden;
    transform:translateY(20px);transition:transform .24s cubic-bezier(.22,1,.36,1);
    padding:0 0 env(safe-area-inset-bottom,0px)}
  .mb-nb-panel.is-open .mb-nb-card{transform:none}

  .mb-nb-grip{flex:none;width:40px;height:4px;border-radius:99px;margin:9px auto 1px;
    background:var(--nb-ink,#888);opacity:.22}

  .mb-nb-head{display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:15px 14px 14px 18px;border-bottom:1px solid var(--nb-line,rgba(128,128,128,.16))}
  .mb-nb-h-l{display:flex;align-items:center;gap:9px;min-width:0;font-size:15.5px;font-weight:700}
  .mb-nb-h-l svg{flex:none;width:18px;height:18px;color:var(--nb-accent,currentColor)}
  .mb-nb-h-r{display:flex;align-items:center;gap:2px;flex:none}
  .mb-nb-mark{background:none;border:0;cursor:pointer;font:inherit;font-size:12px;font-weight:600;
    color:var(--nb-accent,currentColor);padding:7px 9px;border-radius:9px;white-space:nowrap}
  .mb-nb-mark:hover{background:var(--nb-surface-2,rgba(128,128,128,.14))}
  .mb-nb-mark[hidden]{display:none}
  .mb-nb-x{background:none;border:0;color:inherit;cursor:pointer;opacity:.55;padding:7px;border-radius:9px;display:flex}
  .mb-nb-x:hover{opacity:1;background:var(--nb-surface-2,rgba(128,128,128,.14))}

  .mb-nb-list{overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:8px 10px 10px}
  .mb-nb-item{display:flex;gap:12px;align-items:flex-start;text-decoration:none;color:inherit;
    padding:13px 12px;border-radius:14px;position:relative}
  .mb-nb-item + .mb-nb-item{margin-top:3px}
  .mb-nb-item:hover{background:var(--nb-surface-2,rgba(128,128,128,.1))}
  .mb-nb-ic{flex:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    background:var(--nb-surface-2,rgba(128,128,128,.16));color:var(--nb-accent,currentColor)}
  .mb-nb-ic svg{width:19px;height:19px}
  .mb-nb-tx{min-width:0;flex:1;display:flex;flex-direction:column;gap:2px;padding-inline-end:14px}
  .mb-nb-item-t{font-size:13.5px;font-weight:650;line-height:1.3}
  .mb-nb-item-b{font-size:12.5px;line-height:1.35;color:var(--nb-muted,inherit);opacity:.94}
  .mb-nb-item-a{font-size:11px;color:var(--nb-muted,inherit);opacity:.7;margin-top:1px}
  .mb-nb-item.is-unread{background:var(--nb-surface-2,rgba(128,128,128,.12))}
  .mb-nb-item.is-unread .mb-nb-item-t{font-weight:750}
  .mb-nb-item.is-unread::after{content:"";position:absolute;top:14px;inset-inline-end:11px;
    width:7px;height:7px;border-radius:50%;background:var(--nb-accent,currentColor)}

  .mb-nb-empty{display:flex;flex-direction:column;align-items:center;gap:11px;text-align:center;
    color:var(--nb-muted,inherit);opacity:.72;padding:48px 20px;font-size:13px}
  .mb-nb-empty svg{width:30px;height:30px;opacity:.55}

  @media (min-width:620px){
    /* Desktop: a centered dialog. NOT top-anchored — several themes wrap the
       storefront in a transformed phone-frame, so position:fixed resolves to that
       frame; a top offset would clip the header. Centering is safe in both. */
    .mb-nb-panel{align-items:center;padding:18px}
    .mb-nb-card{width:384px;max-height:min(74vh,560px);border-bottom:1px solid var(--nb-line,rgba(128,128,128,.22));
      border-radius:var(--_r);transform:translateY(8px) scale(.98);box-shadow:0 26px 72px -22px rgba(0,0,0,.5)}
    .mb-nb-grip{display:none}
  }
  @media (prefers-reduced-motion:reduce){.mb-nb-panel,.mb-nb-card{transition:none}}
</style>

<script nonce="{{ csp_nonce() }}">
  document.addEventListener('DOMContentLoaded', function () {
    if (!window.mbNotify || !document.getElementById('mbNotifBell')) return;
    window.mbNotify.init({
      bellSel:  '#mbNotifBell',
      dotSel:   '#mbNotifDot',
      panelSel: '#mbNotifPanel',
      isLoggedIn: @json((bool) ($customer ?? false)),
      listUrl:  @json(route('publicView.notifications.index', $store->id)),
      readUrl:  @json(route('publicView.notifications.read', $store->id)),
      csrf:     @json(csrf_token()),
      recent:   Array.isArray(window.MB_NOTIFY_RECENT) ? window.MB_NOTIFY_RECENT : [],
      strings: {
        title:    @json(___('Notifications')),
        empty:    @json(___('No notifications yet')),
        markRead: @json(___('Mark all read')),
        unreadLabel: @json(___('Notifications (:count unread)'))
      }
    });
  });
</script>

{{-- Live bell on the MENU page. The track page has its own 5s poller; on the menu a
     diner's order used to go stale until they reopened the track page or reloaded.
     This keeps the guest recent-orders list (which feeds the bell) fresh by polling
     each ACTIVE order's own track URL (returns status JSON for XHR), then calling
     window.mbNotify.refresh(). Deliberately gentle + bounded: guests only, only when
     they actually have a non-terminal order, ~30s foreground / 90s hidden (vs the
     track page's 5s — the menu is a secondary surface, and the host is CPU-limited),
     and it STOPS once every watched order is terminal. Skips the track page entirely
     (window.mbTrackPoll present there).

     PLAN-GATED (live_order_alerts): a paid-plan upgrade. Free-plan stores keep the
     passive bell (updates on reload / the track page); only stores whose plan grants
     the key get this live poller. The bell + its guest recent-orders list are
     unchanged for everyone — this only adds/removes the live refresh. --}}
@if (! auth('customer')->check() && \App\Services\FeatureAccess::storeAllows((int) ($store->id ?? 0), 'live_order_alerts'))
@php
    $__nbLabels = [
        'unpaid'           => ___('Awaiting Payment'),
        'new'              => ___('Received'),
        'pending'          => ___('Received'),
        'pending_approval' => ___('Awaiting approval'),
        'preparing'        => ___('Preparing'),
        'ready'            => ___('Ready'),
        'out_for_delivery' => ___('On the Way'),
        'delivered'        => ___('Delivered'),
        'completed'        => ___('Completed'),
        'call_bill'        => ___('Bill requested'),
        'cancelled'        => ___('Cancelled'),
    ];
@endphp
<script nonce="{{ csp_nonce() }}">
(function (w, d) {
    "use strict";
    var TERMINAL = { completed: 1, cancelled: 1, delivered: 1 };
    var LABELS   = @json($__nbLabels);
    // Gentle cadence: the menu bell is a secondary surface (the track page owns the
    // fast 5s poll), and each poll pays a full framework boot on a CPU-limited host,
    // so 30s foreground / 90s hidden — ~⅓ fewer requests than a 20s/60s loop.
    var FG = 30000, BG = 90000;

    function boot() {
        // Only where the bell lives, and never on the track page (its own 5s poller
        // owns it). Deferred to DOMContentLoaded so window.MB_STORE (set late in the
        // layout) is available for the store-scoped localStorage key.
        if (!d.getElementById('mbNotifBell') || w.mbTrackPoll) { return; }
        var KEY = 'mb_recent_orders_' + String(w.MB_STORE || 'default');
        var timer = null, inFlight = false;

        function readList() { try { return JSON.parse(w.localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; } }
        function writeList(l) { try { w.localStorage.setItem(KEY, JSON.stringify(l.slice(0, 10))); } catch (e) {} }
        function label(s) { return LABELS[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : ''); }
        function actives() { return readList().filter(function (o) { return o && o.url && o.status && !TERMINAL[o.status]; }); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function arm(ms) { if (timer) { clearInterval(timer); } timer = setInterval(tick, ms); }

        function pollOne(o) {
            return fetch(o.url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.status || res.status === o.status) { return false; }
                    var list = readList(), hit = false;
                    for (var i = 0; i < list.length; i++) {
                        if (list[i] && list[i].url === o.url) {
                            var body = String(list[i].body || ''), sep = body.indexOf(' · ');
                            list[i].status = res.status;
                            list[i].body = label(res.status) + (sep > -1 ? body.slice(sep) : '');
                            list[i].at = new Date().toISOString();
                            hit = true;
                            break;
                        }
                    }
                    if (hit) { writeList(list); }
                    return hit;
                })
                .catch(function () { return false; });
        }

        function tick() {
            if (inFlight) { return; }
            var list = actives();
            if (!list.length) { stop(); return; }
            inFlight = true;
            Promise.all(list.map(pollOne)).then(function (results) {
                inFlight = false;
                if (results.indexOf(true) > -1 && w.mbNotify && w.mbNotify.refresh) { w.mbNotify.refresh(); }
                if (!actives().length) { stop(); }
            });
        }

        if (!actives().length) { return; }              // nothing to watch → never poll
        d.addEventListener('visibilitychange', function () {
            if (d.hidden) { arm(BG); } else { tick(); arm(FG); }
        });
        tick();
        arm(d.hidden ? BG : FG);
    }

    if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
</script>
@endif
