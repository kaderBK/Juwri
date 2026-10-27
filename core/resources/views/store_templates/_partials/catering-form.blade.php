{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // FIX-2 F2-6: owner-scoped — kill veto + (once plan_gated) the store owner's entitlement.
    $cateringEnabled = isset($store) && storefront_catering_enabled($store->id);
    $leadDays = max(0, (int) ($postOptions->catering_min_lead_days ?? 0));
    $minDate = now()->addDays($leadDays)->format('Y-m-d');
    /* A theme can host the form itself — crave puts it in a sheet behind a header
       button — in which case it owns the placement and the self-relocation below
       would tear the form back out of it.

       `cateringModal` is the shared overlay for the scrolling themes (velvet, feast,
       vivid, aurora) that have no sheet framework of their own: the header button
       ([data-catering-open]) opens the form as a centred modal instead of leaving
       it inline in the page (where it landed below the footer or mid-menu). The
       modal owns placement, so it implies inline (skip the placeBottom relocation).
       Panel surface/ink come from the customizer's global --tc-* tokens (with a
       per-theme literal fallback) so they resolve even though the fixed overlay is
       painted outside the theme's own token scope. */
    $cateringModal = $cateringModal ?? false;
    $cateringInline = $cateringInline ?? $cateringModal;
    $cateringSurface = $cateringSurface ?? 'var(--tc-surface, #ffffff)';
    $cateringInk = $cateringInk ?? 'var(--tc-text, inherit)';
    // Prefill the contact fields for a signed-in diner — the store already knows
    // who they are, so making them retype it is friction. A guest is filled in
    // client-side below, from whatever they already typed on the checkout.
    $cateringCustomer = $customer ?? null;
    $cateringName = old('name', $cateringCustomer->name ?? '');
    $cateringPhone = old('phone', $cateringCustomer->phone ?? '');
    $cateringEmail = old('email', $cateringCustomer->email ?? '');
@endphp
@if($cateringEnabled)
{{-- Heading and copy inherit their colour, which is only safe on the surface that
     colour was picked for — and this section now moves. Take the sampled ink
     instead; the fields and the submit button carry their own contrast. --}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.tp-catering{color:var(--mb-ink,inherit)}
@if($cateringModal)
/* Shared catering modal — a fixed, centred overlay for themes with no sheet
   framework. Structure is theme-neutral; the panel's surface/ink are injected
   inline from the theme's tokens. High z-index clears every theme's dock/topbar. */
.tp-cat-scrim{position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(2px);z-index:100000;opacity:0;transition:opacity .22s ease;pointer-events:none}
.tp-cat-scrim.is-open{opacity:1;pointer-events:auto}
.tp-cat-modal{position:fixed;inset:0;z-index:100001;display:flex;align-items:center;justify-content:center;padding:16px;pointer-events:none}
.tp-cat-modal.is-open{pointer-events:auto}
.tp-cat-panel{position:relative;width:100%;max-width:560px;max-height:88vh;overflow:auto;-webkit-overflow-scrolling:touch;
    border-radius:20px;box-shadow:0 40px 90px -25px rgba(0,0,0,.6);
    transform:translateY(18px) scale(.98);opacity:0;transition:transform .24s cubic-bezier(.2,.8,.2,1),opacity .24s ease;font-family:var(--tf-body,inherit)}
.tp-cat-modal.is-open .tp-cat-panel{transform:none;opacity:1}
.tp-cat-panel::-webkit-scrollbar{display:none}
.tp-cat-close{position:absolute;top:12px;inset-inline-end:14px;width:34px;height:34px;flex:none;border:0;border-radius:50%;
    background:rgba(127,127,127,.18);color:inherit;font-size:22px;line-height:1;cursor:pointer;z-index:2}
.tp-cat-close:hover{background:rgba(127,127,127,.3)}
.tp-cat-modal #catering{color:inherit}
@endif
</style>

@if($cateringModal)
<div class="tp-cat-scrim" id="tpCatScrim" hidden></div>
<div class="tp-cat-modal" id="tpCatModal" role="dialog" aria-modal="true" aria-labelledby="tpCateringTitle" hidden>
    <div class="tp-cat-panel" style="background:{{ $cateringSurface }};color:{{ $cateringInk }};">
        <button type="button" class="tp-cat-close" data-tp-cat-close aria-label="{{ ___('Close') }}">&times;</button>
@endif
<section id="catering" class="tp-catering" style="@if($cateringModal)padding:2.4rem 1.6rem;@else padding:3rem 1rem;max-width:720px;margin:0 auto;@endif">
    <h2 id="tpCateringTitle" style="text-align:center;margin-bottom:.5rem;">{{ ___('Catering & Events') }}</h2>
    <p style="text-align:center;opacity:.75;margin-bottom:1.5rem;">{{ ___('Planning something bigger? Send us the details.') }}</p>

    @if(session('catering_success'))
        <div style="background:#dcfce7;color:#166534;padding:.9rem 1rem;border-radius:.6rem;margin-bottom:1rem;">{{ session('catering_success') }}</div>
    @endif
    @if(isset($errors) && $errors->any())
        <div style="background:#fee2e2;color:#991b1b;padding:.9rem 1rem;border-radius:.6rem;margin-bottom:1rem;">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('publicView.catering.request', $store->slug) }}" style="display:grid;gap:.8rem;">
        @csrf
        <input name="name" value="{{ $cateringName }}" placeholder="{{ ___('Your name') }}" required autocomplete="name" data-catering-name style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
            <input name="phone" value="{{ $cateringPhone }}" placeholder="{{ ___('Phone') }}" autocomplete="tel" inputmode="tel" data-catering-phone style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;">
            <input name="email" type="email" value="{{ $cateringEmail }}" placeholder="{{ ___('Email') }}" autocomplete="email" data-catering-email style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
            <label style="display:grid;gap:.2rem;font-size:.85rem;opacity:.8;">{{ ___('Event date & time') }}
                <input name="event_at" type="datetime-local" min="{{ $minDate }}T00:00" required style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;">
            </label>
            <label style="display:grid;gap:.2rem;font-size:.85rem;opacity:.8;">{{ ___('Headcount') }}
                <input name="headcount" type="number" min="1" value="20" required style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;">
            </label>
        </div>
        <textarea name="message" rows="3" placeholder="{{ ___('Tell us about your event') }}" style="padding:.7rem;border:1px solid #ccc;border-radius:.5rem;"></textarea>
        {{-- Brand token, not a hardcoded near-black: on crave and the other dark
             themes a #111 button on a #0d0d0d surface is invisible. --}}
        <button type="submit" style="padding:.9rem;border:none;border-radius:.6rem;background:var(--brand,var(--theme-color-1,#111));color:#fff;font-weight:600;cursor:pointer;">{{ ___('Request catering') }}</button>
        {{-- C2: catering deposit is async — the owner sends a pay link after accepting.
             No payment UI here, just set the expectation when the store takes deposits. --}}
        @if(!empty($postOptions->catering_deposit_enabled))<small data-catering-deposit-note style="opacity:.7;text-align:center;">🔒 {{ ___('A deposit may be requested to confirm your event.') }}</small>@endif
        @if($leadDays > 0)<small style="opacity:.6;text-align:center;">{{ ___('We need at least :n day(s) notice.', ['n' => $leadDays]) }}</small>@endif
    </form>
</section>
@if($cateringModal)
    </div>
</div>
@endif
{{-- Guest prefill: a signed-in diner is filled server-side above; a guest who
     already typed their details on the checkout gets them copied here. Reads any
     filled input carrying the matching autocomplete role from OUTSIDE this form
     (the checkout contact fields), and only fills a catering field still empty.
     Re-runs when the opener marked [data-catering-open] is clicked, since the
     guest may fill the checkout after this markup loads. Theme-agnostic. --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var form = document.getElementById('catering');
    if (!form) { return; }
    var ROLES = { name: 'name', phone: 'tel', email: 'email' };
    function fill() {
        Object.keys(ROLES).forEach(function (k) {
            var target = form.querySelector('[data-catering-' + k + ']');
            if (!target || (target.value || '').trim()) { return; }
            var candidates = document.querySelectorAll('[autocomplete="' + ROLES[k] + '"]');
            for (var i = 0; i < candidates.length; i++) {
                var src = candidates[i];
                if (!form.contains(src) && (src.value || '').trim()) { target.value = src.value.trim(); break; }
            }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fill); }
    else { fill(); }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('[data-catering-open]') : null;
        if (t) { setTimeout(fill, 60); }
    }, true);
})();
</script>
@if($cateringModal)
{{-- Modal open/close: the header button ([data-catering-open]) opens the overlay;
     a backdrop click, the close button ([data-tp-cat-close]) and Escape dismiss it.
     preventDefault stops the button's legacy #catering jump. --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var modal = document.getElementById('tpCatModal');
    var scrim = document.getElementById('tpCatScrim');
    if (!modal || !scrim) { return; }
    var lastFocus = null;
    function open() {
        lastFocus = document.activeElement;
        modal.hidden = false; scrim.hidden = false;
        void modal.offsetWidth;                 // reflow so the transition runs
        modal.classList.add('is-open'); scrim.classList.add('is-open');
        document.addEventListener('keydown', onKey, true);
        var f = modal.querySelector('input,textarea,button:not([data-tp-cat-close])');
        if (f) { try { f.focus(); } catch (e) {} }
    }
    function close() {
        modal.classList.remove('is-open'); scrim.classList.remove('is-open');
        document.removeEventListener('keydown', onKey, true);
        setTimeout(function () { modal.hidden = true; scrim.hidden = true; }, 240);
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function onKey(e) {
        if (e.key === 'Escape' || e.keyCode === 27) { e.preventDefault(); close(); }
    }
    document.addEventListener('click', function (e) {
        var opener = e.target.closest ? e.target.closest('[data-catering-open]') : null;
        if (opener) { e.preventDefault(); open(); return; }
        if (e.target.closest && e.target.closest('[data-tp-cat-close]')) { close(); return; }
        if (e.target === scrim) { close(); }
    }, false);
})();
</script>
@else
@unless($cateringInline)
{{-- The form renders wherever the theme @includes it, which on every app-shell
     theme is a SIBLING of the fixed view panes — laid out, painted, and covered
     by the shell, on a document that does not scroll. Measured unreachable on
     crave and scoop; matcha, orbit, crumb and scoop share the structure. Move it
     to the end of the theme's own scrolling content, above the info footer that
     claims the same spot, and re-ink it for whatever surface it lands on. --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var host = document.getElementById('catering');
    if (!host || !window.mbAnchor) { return; }
    window.mbAnchor.onSettled(function () {
        window.mbAnchor.placeBottom(host, document.getElementById('mbInfoFoot'));
        window.mbAnchor.ink(host);
    }, host);
})();
</script>
@endunless
@endif
@endif
