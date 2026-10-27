{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  AL-10/11 (auto-locale v1) — first-visit language SUGGEST banner for the
  marketing site. Same client-only contract as the storefront partial:
  identical markup for every visitor and crawler (SEO-safe, no Vary); a script
  reveals it only when navigator.languages matches an active site language that
  is not the current one. The CTA is a real lang_url() anchor (the site switches by
  URL, not the storefront cookie handler), so it works with or without JS once
  shown. An explicit pick writes qc_lang_choice (set below on click) so the
  banner never returns.

  Fixed to the block-end edge and revealed via [hidden] → overlay, no CLS.
--}}
@php
    $__lsCurrent = \Juwri\Core\Support\Views\PublicSiteViewHelpers::currentLanguageRaw();
    $__lsLangs = (isset($languages) ? $languages : collect())->unique('code')->values();
    $__lsOffered = \Juwri\Core\Support\Views\PublicSiteViewHelpers::localeSuggestOffered($__lsLangs, $__lsCurrent);

    $__lsShow = ! request()->boolean('preview_theme')
        && ! empty($settings->userlangsel ?? null)
        && $__lsLangs->count() > 1
        && $__lsOffered->isNotEmpty();
@endphp

@if ($__lsShow)
    <div id="qc-lang-suggest" role="status" aria-live="polite" hidden>
        <div class="qc-ls-inner">
            <span class="qc-ls-globe" aria-hidden="true">🌐</span>
            <span class="qc-ls-text"></span>
            <a class="qc-ls-cta" href="#" data-turbo="false"></a>
            <button type="button" class="qc-ls-x" aria-label="{{ ___('Dismiss') }}">&times;</button>
        </div>
    </div>
    <style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
        #qc-lang-suggest{position:fixed;inset-inline:0;inset-block-end:0;z-index:1000;background:#111827;color:#fff;font-size:14px;box-shadow:0 -2px 12px rgba(0,0,0,.25)}
        #qc-lang-suggest[hidden]{display:none}
        #qc-lang-suggest .qc-ls-inner{display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;max-width:960px;margin-inline:auto}
        #qc-lang-suggest .qc-ls-globe{font-size:18px;line-height:1}
        #qc-lang-suggest .qc-ls-text{font-weight:600}
        #qc-lang-suggest .qc-ls-cta{background:#fff;color:#111827;border:0;border-radius:8px;padding:.3rem .7rem;font-weight:700;cursor:pointer;text-decoration:none}
        #qc-lang-suggest .qc-ls-x{margin-inline-start:auto;background:transparent;border:0;color:inherit;font-size:20px;cursor:pointer;line-height:1;padding:.1rem .3rem}
    </style>
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
    (function () {
        var OFFERED = @json($__lsOffered);
        var AUTONYM = {en:'English',ar:'العربية',fr:'Français',de:'Deutsch',es:'Español',pt:'Português',tr:'Türkçe',it:'Italiano',ru:'Русский',hi:'हिन्दी',ur:'اردو',zh:'中文',nl:'Nederlands',id:'Bahasa Indonesia',fa:'فارسی'};
        var PROMPT = @json(___('View this site in :lang'));

        function cookie(n){ var m = document.cookie.split('; ').find(function(r){ return r.indexOf(n + '=') === 0; }); return m ? m.split('=')[1] : ''; }
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
        cta.setAttribute('href', match.url);
        cta.setAttribute('dir', match.dir);
        // AL-11: remember the choice so the banner never returns after switching.
        cta.addEventListener('click', function () {
            try { document.cookie = 'qc_lang_choice=' + match.code + ';path=/;max-age=31536000;samesite=lax'; } catch (e) {}
        });
        el.hidden = false;

        el.querySelector('.qc-ls-x').addEventListener('click', function () {
            try { document.cookie = 'qc_lang_dismissed=1;path=/;max-age=31536000;samesite=lax'; } catch (e) {}
            el.hidden = true;
        });
    })();
    </script>
@endif
