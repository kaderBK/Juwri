{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  F14-2 — shared Web Share button for storefront themes without a native one.

  A menu is exactly the page a diner forwards to a group chat, yet navigator.share
  is wired into only a handful of themes (aurora, crumb, lime, modern-theme, scoop).
  Rather than copy a share control into every hand-written theme, this renders once
  from the shared layout and STANDS DOWN the moment it detects a theme's own share
  control on the page — so themes that already ship one keep it and nothing renders
  twice.

  Self-contained by necessity: injected into themes whose CSS/JS bundles it knows
  nothing about, so it ships its own scoped styles (all class names prefixed
  `mbshare-`) and plain ES5 script with no framework or bundle dependency.

  OPT-IN, default OFF: gated on the per-store `share.fallback_button` theme setting
  (Dashboard → Customize → Share). No live storefront changes appearance until an
  owner turns it on.
--}}
@php
    $mbShareOn = isset($store) && theme_setting($store->id, 'share.fallback_button');
@endphp

@if($mbShareOn)
<button class="mbshare-btn" id="mbShareBtn" type="button" hidden
        aria-label="{{ ___('Share this menu') }}" title="{{ ___('Share this menu') }}">
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">
        <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/>
    </svg>
    <span class="mbshare-label">{{ ___('Share') }}</span>
</button>

<div class="mbshare-toast" id="mbShareToast" role="status" aria-live="polite" hidden>{{ ___('Link copied') }}</div>

<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbshare-btn{position:fixed;right:16px;bottom:16px;z-index:2147483000;display:inline-flex;align-items:center;gap:8px;
  padding:11px 16px;border:0;border-radius:999px;cursor:pointer;font:600 14px/1 inherit;color:#fff;
  background:var(--tc-primary,#3fd463);box-shadow:0 6px 20px rgba(0,0,0,.28);-webkit-tap-highlight-color:transparent}
.mbshare-btn[hidden]{display:none!important}
.mbshare-btn:active{transform:translateY(1px)}
.mbshare-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}
.mbshare-label{white-space:nowrap}
[dir="rtl"] .mbshare-btn{right:auto;left:16px}
.mbshare-toast{position:fixed;left:50%;bottom:78px;transform:translateX(-50%);z-index:2147483000;
  background:#111;color:#fff;padding:10px 16px;border-radius:10px;font:500 13px/1 inherit;box-shadow:0 6px 20px rgba(0,0,0,.3);
  opacity:0;transition:opacity .2s;pointer-events:none}
.mbshare-toast[hidden]{display:none}
.mbshare-toast.is-on{opacity:1}
@media print{.mbshare-btn,.mbshare-toast{display:none!important}}
</style>

<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    // Stand down when the theme ships its own share control (covers native-share
    // themes like aurora/crumb/lime/modern-theme/scoop). Detect by DOM, not an
    // allow-list, so it self-removes wherever a real control already exists.
    if (document.querySelector('[data-share], .share-btn, #shareBtn, [data-web-share]')) return;

    var btn = document.getElementById('mbShareBtn');
    var toast = document.getElementById('mbShareToast');
    if (!btn) return;
    btn.hidden = false;

    /* Pin to the theme's centered content column, not the raw viewport. On desktop
       every modern theme is a centered frame (crave's 430px phone column … crumb's
       1178), so a viewport-anchored button sat far out in the letterbox — reported
       as "mbShareBtn is outside the frame". Align its inline edge to the frame's;
       on mobile / a full-width theme the band spans the viewport and the guard
       restores the CSS default (right:16px, or left:16px in RTL). This button lives
       on the frame's END edge (bottom-right LTR / bottom-left RTL), mirroring the
       combo FAB which takes the START edge, so the two never collide. Same
       mbAnchor.frameBand() source the combo FAB and info footer align to. */
    function positionShare() {
        var band = window.mbAnchor && window.mbAnchor.frameBand && window.mbAnchor.frameBand();
        var rtl = (document.documentElement.getAttribute('dir') || document.dir) === 'rtl';
        if (band && band.width < window.innerWidth - 40) {
            if (rtl) {
                btn.style.left = (band.left + 16) + 'px';
                btn.style.right = 'auto';
            } else {
                btn.style.right = (window.innerWidth - (band.left + band.width) + 16) + 'px';
                btn.style.left = 'auto';
            }
            if (toast) { toast.style.left = (band.left + band.width / 2) + 'px'; }
        } else {
            btn.style.left = '';
            btn.style.right = '';
            if (toast) { toast.style.left = ''; }
        }
    }
    positionShare();
    window.addEventListener('resize', positionShare);
    window.addEventListener('load', positionShare);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', positionShare, { once: true });
    }

    var toastTimer;
    function showToast(msg) {
        if (!toast) return;
        toast.textContent = msg;
        toast.hidden = false;
        // Force reflow so the fade restarts even on rapid taps.
        void toast.offsetWidth;
        toast.className = 'mbshare-toast is-on';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.className = 'mbshare-toast';
            setTimeout(function () { toast.hidden = true; }, 220);
        }, 1800);
    }

    btn.addEventListener('click', function () {
        var data = { title: document.title, url: location.href };
        if (navigator.share) {
            navigator.share(data).catch(function () { /* user cancelled — no-op */ });
            return;
        }
        // No Web Share (most desktop browsers): copy the link + toast.
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(location.href).then(function () {
                showToast(btn.getAttribute('data-copied') || '{{ ___('Link copied') }}');
            }).catch(function () { fallbackCopy(); });
        } else {
            fallbackCopy();
        }
    });

    function fallbackCopy() {
        try {
            var ta = document.createElement('textarea');
            ta.value = location.href;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('{{ ___('Link copied') }}');
        } catch (e) { /* give up silently */ }
    }
})();
</script>
@endif
