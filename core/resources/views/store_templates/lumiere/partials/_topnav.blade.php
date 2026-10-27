{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Desktop-only sticky top navigation (≥1024px). Ships in the markup at
     every width but is `display:none` until the desktop breakpoint (see
     _styles.blade.php) — the mobile bottom tabbar (_tabbar.blade.php) is
     completely untouched below 1024 and keeps working exactly as before.

     Reuses the SAME `data-nav` contract + `.lm-tab` class the mobile tabbar
     already uses, so lm-boot.js's existing delegated click handler
     (`app.addEventListener('click', ...)`) and `paintScreen()`'s
     aria-current bookkeeping (`app.querySelectorAll('.lm-tab')`) drive these
     buttons too with zero changes to that logic — this is purely an added,
     alternate set of triggers for the same navigation hooks. --}}
<header class="lm-topnav" id="lmTopnav">
    <div class="lm-topnav__inner">
        <div class="lm-topnav__brand">
            <span class="lm-topnav__logo" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#fff" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/></svg>
            </span>
            <span class="lm-topnav__name">{{ $store->title }}</span>
        </div>

        <nav class="lm-topnav__links" aria-label="{{ ___('Main') }}">
            <button type="button" class="lm-tab" data-nav="home" aria-current="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 001 1h10a1 1 0 001-1v-9"/></svg>
                <span>{{ ___('Home') }}</span>
            </button>
            <button type="button" class="lm-tab" data-nav="scan" aria-current="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M20 8V6a2 2 0 00-2-2h-2M4 16v2a2 2 0 002 2h2M20 16v2a2 2 0 01-2 2h-2"/><circle cx="12" cy="12" r="3"/></svg>
                <span>{{ ___('Scan') }}</span>
            </button>
            <button type="button" class="lm-tab" data-nav="calendar" aria-current="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                <span>{{ ___('Calendar') }}</span>
            </button>
            <button type="button" class="lm-tab" data-nav="profile" aria-current="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <span>{{ ___('Profile') }}</span>
            </button>
        </nav>

        <div class="lm-topnav__actions">
            @if(($lmLanguages ?? collect())->count() > 1)
                <label class="lm-langsel" aria-label="{{ ___('Language') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                    <select id="lmLangSel">
                        @foreach($lmLanguages as $lang)
                            <option value="{{ $lang->code }}" data-url="{{ \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" @if(get_lang() === $lang->code) selected @endif>{{ strtoupper($lang->code) }}</option>
                        @endforeach
                    </select>
                </label>
                <script nonce="{{ csp_nonce() }}">
                    (function(){var s=document.getElementById('lmLangSel');if(!s)return;s.addEventListener('change',function(e){var o=e.target.selectedOptions[0];if(!o)return;var code=o.value;try{document.cookie='Quick_user_lang_code='+code+';path=/;max-age=31536000;samesite=lax';document.cookie='qc_lang_choice='+code+';path=/;max-age=31536000;samesite=lax';}catch(err){}window.location.assign(o.getAttribute('data-url')||window.location.href);});})();
                </script>
            @endif
            <button type="button" class="lm-iconbtn" data-nav="basket" aria-label="{{ $vocab['orders'] ?? ___('Basket') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                <i class="lm-badge" id="lmCartBadgeTop" hidden>0</i>
            </button>
            <button type="button" class="lm-iconbtn lm-bellbtn" id="lmBellBtnTop" aria-label="{{ ___('Notifications') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0112 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 004 0"/></svg>
                <i class="lm-bell-dot" hidden aria-hidden="true"></i>
            </button>
        </div>
    </div>
</header>
