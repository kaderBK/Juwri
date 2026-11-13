{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  GOL E2/E3 (rev/guest-order-linking): a shared, theme-agnostic toast shown
  after the auth flow reloads the storefront (all 17 themes reload on success,
  so the login/register/OTP JSON body is gone by the time this renders — the
  controllers stash the result in a session flash instead).

    gol_claimed_count       (int)    → "N previous orders added to your account"
    gol_pending_verification(string) → the check-your-inbox message

  Self-contained: inline styles + a tiny inline script, no theme CSS/JS
  dependency. Fixed to the viewport corner, RTL-aware, auto-dismisses.
--}}
@php
    $golClaimed = (int) session('gol_claimed_count', 0);
    $golPending = trim((string) session('gol_pending_verification', ''));
@endphp
@if ($golClaimed > 0 || $golPending !== '')
    {{-- Styles live in a nonced style block (not inline attrs) so a theme can restyle
         the banner by out-specifying #gol-claim-banner — soirée does exactly that. --}}
    <div id="gol-claim-banner" role="status" aria-live="polite">
        @if ($golPending !== '')
            <span class="gol-msg">{{ $golPending }}</span>
        @else
            <span class="gol-msg">{{ ___(':count previous orders were added to your account.', ['count' => $golClaimed]) }}</span>
        @endif
        <button type="button" class="gol-x" aria-label="{{ ___('Dismiss') }}" data-csp-remove-id="gol-claim-banner">&times;</button>
    </div>
    {{-- Themed off the Customizer v2 :root design tokens (--tc-*, theme-tokens.blade.php),
         so the toast adopts each theme's own surface / text / radius with no per-theme
         CSS. The literals are the fallback: a store with no token block (or a theme that
         opts out) renders the original dark toast. soirée additionally relocates + dresses
         it (light-mode aware) via its own stylesheet, which out-specifies these rules. --}}
    <style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
        /* Colours cascade through the common per-theme surface/text tokens (each read
           inside the theme root the partial renders in, so they even flip light/dark),
           then the Customizer --tc-* layer, then a dark literal for the handful of
           themes that name their surface something bespoke. */
        #gol-claim-banner{position:fixed;z-index:2147483000;inset-block-start:16px;inset-inline-end:16px;max-width:340px;
            background:var(--card,var(--paper,var(--panel,var(--au-bg-2,var(--vd-surface,var(--ff-bg,var(--v-bg-card,var(--cta-bg,var(--night-2,var(--tc-surface,#17171e))))))))));
            color:var(--ink,var(--au-text,var(--vd-text,var(--ff-ink,var(--v-text-primary,var(--tc-text,#fff))))));
            border:1px solid var(--stroke,rgba(128,128,128,.22));
            border-radius:var(--tr-card,16px);padding:14px 46px 14px 16px;
            box-shadow:0 14px 40px rgba(0,0,0,.34);
            font:600 13.5px/1.45 var(--tf-body,system-ui,-apple-system,Segoe UI,Roboto,sans-serif)}
        #gol-claim-banner .gol-x{position:absolute;inset-block-start:9px;inset-inline-end:9px;
            width:26px;height:26px;border-radius:50%;display:grid;place-items:center;
            background:transparent;border:0;color:var(--muted,var(--tc-text-muted,rgba(128,128,128,.72)));
            font-size:18px;line-height:1;cursor:pointer;transition:background .15s ease,color .15s ease}
        @media (hover:hover){#gol-claim-banner .gol-x:hover{background:rgba(128,128,128,.16);color:var(--ink,var(--tc-text,#fff))}}
        /* Once mbAnchor drops it in-flow at the top of the theme's content (so it
           sits INSIDE app-shell frames like crave/matcha, not the viewport margin),
           stop being a fixed corner toast and become a full-width top card. */
        #gol-claim-banner.mb-inflow{position:static;inset:auto;max-width:none;margin:12px 0 2px;
            box-shadow:0 10px 30px rgba(0,0,0,.20)}
    </style>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var b = document.getElementById('gol-claim-banner');
            if (!b) return;
            setTimeout(function () { if (b && b.parentNode) { b.style.transition = 'opacity .4s'; b.style.opacity = '0'; setTimeout(function () { if (b.parentNode) b.remove(); }, 400); } }, 6000);
            @unless(($postOptions->store_template ?? '') === 'soiree')
            /* Relocate in-flow to the top of the theme's scroll content. Deferred:
               mbAnchor is defined later in the layout than this per-theme include. */
            (function tryAnchor(tries) {
                if (!window.mbAnchor) { if (tries < 40) setTimeout(function () { tryAnchor(tries + 1); }, 50); return; }
                mbAnchor.onSettled(function () { if (mbAnchor.placeTop(b)) b.classList.add('mb-inflow'); }, b);
            })(0);
            @endunless
        })();
    </script>
@endif
