{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  AL-10/11 (auto-locale v1) — first-visit language SUGGEST banner for every
  storefront theme. Client-only by contract (rev/currency-locale KICKOFF rule 3):
  the markup is IDENTICAL for every visitor and every crawler (no server variance,
  no Vary, SEO-safe); a script reveals it ONLY when the visitor's own
  navigator.languages matches an offered language that is not the current one.

  Suppressed server-side (AL-12) for the owner/preview render, kiosk mode, and
  when the store's language picker is off or offers ≤1 language — nothing to
  suggest. The CTA is a plain [data-lang-code] control, so the existing
  language-switch.blade.php capture handler performs the actual switch (cookie +
  localized URL); this partial never duplicates that machinery. That same handler
  writes qc_lang_choice, so an explicit pick (here or in the theme picker)
  permanently silences this banner.

  Server-rendered [hidden]; revealed ONLY after (a) the page has fully loaded
  (window load) and (b) mbAnchor has relocated it in-flow to the TOP of the
  theme — it never flashes the transient fixed-bottom bar it used to show before
  jumping top (2026-09-01 owner report, all 17 themes). If the anchor system is
  absent or placeTop finds no host, it stays hidden rather than sit at the
  bottom. The fixed-bottom styles below are the literal fallback layer for the
  in-flow card's token cascade.
--}}
@php
    $__lsOffered = \Juwri\Core\Support\Views\StoreViewHelpers::localeSuggestOffered($menu_languages ?? [], (string) get_lang());

    $__lsShow = ! auth()->check()                       // AL-12: never nag the owner…
        && ! request()->boolean('preview_theme')        // …or the customizer preview
        && ! request()->boolean('kiosk_mode')           // …or a kiosk/signage screen
        && ! empty($settings->userlangsel ?? null)      // picker must be enabled (mirrors layout gate)
        && count($menu_languages ?? []) > 1             // >1 language actually offered
        && $__lsOffered->isNotEmpty();
@endphp

@if ($__lsShow)
    <div id="qc-lang-suggest" role="status" aria-live="polite" hidden>
        <div class="qc-ls-inner">
            <span class="qc-ls-globe" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.2"/><line x1="2.8" y1="12" x2="21.2" y2="12"/><path d="M12 2.8a14 14 0 0 1 3.7 9.2 14 14 0 0 1-3.7 9.2 14 14 0 0 1-3.7-9.2 14 14 0 0 1 3.7-9.2z"/></svg></span>
            <span class="qc-ls-text"></span>
            <button type="button" class="qc-ls-cta" data-lang-code="" data-lang-name=""></button>
            <button type="button" class="qc-ls-x" aria-label="{{ ___('Dismiss') }}">&times;</button>
        </div>
    </div>
    <style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
        #qc-lang-suggest{position:fixed;inset-inline:0;inset-block-end:0;z-index:1000;background:#111827;color:#fff;font-size:14px;box-shadow:0 -2px 12px rgba(0,0,0,.25)}
        #qc-lang-suggest[hidden]{display:none}
        #qc-lang-suggest .qc-ls-inner{display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;max-width:960px;margin-inline:auto}
        #qc-lang-suggest .qc-ls-globe{line-height:0;flex:none}
        #qc-lang-suggest .qc-ls-globe svg{width:18px;height:18px;display:block}
        #qc-lang-suggest .qc-ls-text{font-weight:600}
        #qc-lang-suggest .qc-ls-cta{background:#fff;color:#111827;border:0;border-radius:8px;padding:.3rem .7rem;font-weight:700;cursor:pointer}
        #qc-lang-suggest .qc-ls-x{margin-inline-start:auto;background:transparent;border:0;color:inherit;font-size:20px;cursor:pointer;line-height:1;padding:.1rem .3rem}
        /* Once mbAnchor drops it in-flow at the top of the theme's content, stop being
           a fixed bottom bar and become a first-section card themed off the Customizer
           v2 tokens (--tc-*), with the original dark bar as the literal fallback. */
        /* Colours cascade through each theme's own surface/text tokens (resolved once
           mbAnchor has moved it INTO the theme root, so they flip light/dark), then the
           --tc-* layer, then a dark literal fallback. */
        #qc-lang-suggest.mb-inflow{position:static;inset:auto;z-index:auto;box-shadow:none;
            background:var(--card,var(--paper,var(--panel,var(--au-bg-2,var(--vd-surface,var(--ff-bg,var(--v-bg-card,var(--cta-bg,var(--night-2,var(--tc-surface,#111827))))))))));
            color:var(--ink,var(--au-text,var(--vd-text,var(--ff-ink,var(--v-text-primary,var(--tc-text,#fff))))));
            border:1px solid var(--stroke,rgba(128,128,128,.22));border-radius:var(--tr-card,16px);
            margin:12px 0 2px;overflow:hidden}
        #qc-lang-suggest.mb-inflow .qc-ls-inner{max-width:none;margin:0;padding:12px 14px;gap:10px}
        #qc-lang-suggest.mb-inflow .qc-ls-globe svg{width:20px;height:20px}
        #qc-lang-suggest.mb-inflow .qc-ls-text{font-weight:700}
        #qc-lang-suggest.mb-inflow .qc-ls-cta{background:var(--ink,var(--au-text,var(--vd-text,var(--ff-ink,var(--v-text-primary,var(--tc-text,#fff))))));color:var(--card,var(--paper,var(--panel,var(--au-bg-2,var(--vd-surface,var(--ff-bg,var(--v-bg-card,var(--cta-bg,var(--night-2,var(--tc-surface,#111827))))))))));
            border-radius:var(--tr-pill,999px);padding:8px 16px;font-weight:800}
        #qc-lang-suggest.mb-inflow .qc-ls-x{color:var(--muted,var(--tc-text-muted,rgba(128,128,128,.72)))}
        /* Post-load entrance: the banner only appears once placed at the top,
           so give the reveal a short settle instead of a hard pop. */
        #qc-lang-suggest.mb-inflow:not([hidden]){animation:qc-ls-in .28s ease-out}
        @keyframes qc-ls-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
    </style>
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
    (function () {
        var OFFERED = @json($__lsOffered);
        // Autonym (target language's own name) is the primary, target-language copy;
        // falls back to the platform name for anything not mapped.
        var AUTONYM = {en:'English',ar:'العربية',fr:'Français',de:'Deutsch',es:'Español',pt:'Português',tr:'Türkçe',it:'Italiano',ru:'Русский',hi:'हिन्दी',ur:'اردو',zh:'中文',nl:'Nederlands',id:'Bahasa Indonesia',fa:'فارسی'};
        var PROMPT = @json(___('View this menu in :lang'));

        function cookie(n){ var m = document.cookie.split('; ').find(function(r){ return r.indexOf(n + '=') === 0; }); return m ? m.split('=')[1] : ''; }
        // AL-11: an earlier dismissal or an explicit language choice silences it for a year.
        if (cookie('qc_lang_dismissed') || cookie('qc_lang_choice')) return;

        var langs = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || ''])
            .map(function (l) { return (l || '').toLowerCase(); });

        var match = null;
        for (var i = 0; i < langs.length && !match; i++) {
            var base = langs[i].split('-')[0];
            for (var j = 0; j < OFFERED.length; j++) {
                if (OFFERED[j].code === langs[i] || OFFERED[j].code === base) { match = OFFERED[j]; break; }
            }
        }
        if (!match) return;

        var el = document.getElementById('qc-lang-suggest');
        if (!el) return;
        var autonym = AUTONYM[match.code] || match.name;
        el.querySelector('.qc-ls-text').textContent = PROMPT.replace(':lang', autonym);
        var cta = el.querySelector('.qc-ls-cta');
        cta.textContent = autonym;
        cta.setAttribute('data-lang-code', match.code);
        cta.setAttribute('data-lang-name', match.name);
        cta.setAttribute('dir', match.dir);
        /* Reveal ONLY once the page has fully loaded AND the banner has been
           relocated in-flow to the top of the theme (inside app-shell frames,
           not the fixed viewport bottom) — never the transient fixed bottom
           bar it used to flash before jumping top (2026-09-01 owner report,
           all 17 themes). If the anchor system never arrives or placeTop finds
           no host, stay hidden rather than show at the bottom. */
        var revealed = false, dismissed = false;
        function placeAndReveal(tries) {
            if (dismissed) return;
            if (!window.mbAnchor) {
                if (tries < 40) setTimeout(function () { placeAndReveal(tries + 1); }, 50);
                return;
            }
            mbAnchor.onSettled(function () {
                if (dismissed) return;
                /* placeTop is idempotent and onSettled re-fires (theme boot
                   reparenting, scheme flips) — keep re-placing like before,
                   but the [hidden] reveal happens only once, after placement. */
                if (mbAnchor.placeTop(el)) {
                    el.classList.add('mb-inflow');
                    if (!revealed) {
                        revealed = true;
                        el.hidden = false; // client-only reveal — no server HTML variance
                    }
                }
            }, el);
        }
        if (document.readyState === 'complete') {
            placeAndReveal(0);
        } else {
            window.addEventListener('load', function () { placeAndReveal(0); }, { once: true });
        }

        el.querySelector('.qc-ls-x').addEventListener('click', function () {
            dismissed = true;
            try { document.cookie = 'qc_lang_dismissed=1;path=/;max-age=31536000;samesite=lax'; } catch (e) {}
            el.hidden = true;
        });
    })();
    </script>
@endif
