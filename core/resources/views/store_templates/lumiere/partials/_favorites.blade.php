{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Favorites (wishlist) screen. The heart buttons across the storefront save
     product ids into localStorage (WISH_KEY, see _script.blade.php); this screen
     renders those saved items so a shopper can actually find them again. Built
     client-side from the same window ITEMS map the rest of the theme uses, so it
     needs no extra endpoint and stays in sync with the hearts. --}}
<section class="lm-screen has-tabbar" data-screen="favorites" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-nav="profile" aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <span class="lm-title">{{ ___('Favorites') }}</span>
        <span class="lm-iconbtn" aria-hidden="true" style="visibility:hidden"></span>
    </div>

    <div class="lm-grid" id="lmFavGrid"></div>

    <div class="lm-empty" id="lmFavEmpty" hidden>
        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="color:var(--lm-ink-3)"><path d="M12 20s-7-4.5-9.5-9A5 5 0 0112 5a5 5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
        <p style="margin-top:10px;font-weight:600">{{ ___('No favorites yet') }}</p>
        <p style="font-size:13px">{{ ___('Tap the heart on any product to save it here.') }}</p>
    </div>
</section>
