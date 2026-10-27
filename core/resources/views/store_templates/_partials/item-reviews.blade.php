{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared item-reviews upgrade: the rating histogram and the write-a-review flow.

  crave, matcha and glow ship the full widget — average, a 5→1 bar chart, filters
  and a Write button. orbit, velora and crumb render only a list (or, on crumb, a
  count and an average), which is what the "no Reviews section like Crave" reports
  were really about once the sections themselves were browser-verified: reviews
  were there, the widget around them was not.

  This ADDS the two missing pieces to whatever the theme already renders instead
  of replacing it, so each theme keeps its own list, markup and styling. Themes
  that already have a histogram are left alone.

  What this file used to be: a full replacement renderer (`mbItemReviews.render`)
  that no theme ever called, reading `r.rating` / `r.comment` from a payload whose
  fields are `stars` / `body` — so had anything called it, every review would have
  rendered as zero stars with no text. Replaced rather than fixed.

  The data is the same public endpoint crave uses, which already returns the
  distribution: GET /r/{store}/item/{item}/reviews → {data, average, count,
  distribution:{5..1}}. Writing posts back to the same URL.
--}}
@php
    /* Same 17-template scope as the other shared sections. Nothing here fires
       without a matching reviews section, so a legacy template would only ever
       carry dead script — and there are ~40 of them holding nearly every live
       store. */
    $mbrTemplates = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN;

    /* Owner switch (per-store option `item_reviews_enabled`, DEFAULT ON): lets a
       store hide the "Rate this dish" / Reviews widget entirely. Nulling $mbrBase
       drops both the display list AND the write-a-review flow (the JS never boots
       without BASE). Only an explicit '0' disables it, so every existing store
       keeps reviews unchanged. The post-order "How was your order?" prompt
       (mk-rating-prompt) is a separate feature and is not gated here. */
    $mbrEnabled = ! (isset($store) && ! empty($store->id)
        && (string) store_options($store->id, 'item_reviews_enabled', '1') === '0');
    $mbrBase = null;
    if (isset($store) && ! empty($store->id)
        && $mbrEnabled
        && in_array(app(\App\Services\StorefrontThemeService::class)->resolveSaved($store), $mbrTemplates, true)) {
        $mbrBase = url('r/'.$store->id.'/item');
    }
@endphp

@if(isset($store) && ! empty($store->id))
{{-- Shared flag every kept theme reads before rendering its OWN native reviews
     section / "Rate this dish" row (those render from baked item data, not from
     $mbrBase, so they need this switch too). Emitted always; default true. --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>window.__mbItemReviewsOn = {{ $mbrEnabled ? 'true' : 'false' }};</script>
@endif

@if($mbrBase)
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var BASE = @json($mbrBase);
    var I18N = {
        write: @json(___('Write a review')),
        reviews: @json(___('reviews')),
        yourReview: @json(___('Your review')),
        yourName: @json(___('Your name')),
        optional: @json(___('Optional')),
        rating: @json(___('Your rating')),
        submit: @json(___('Submit review')),
        cancel: @json(___('Cancel')),
        chooseRating: @json(___('Choose a rating first')),
        thanks: @json(___('Thank you! Your review will appear once approved.')),
        failed: @json(___('Could not send your review. Please try again.')),
    };

    /* The theme's own ITEM-review section. Deliberately not `.review-card` /
       `#reviewCard`: that is the post-order "How was your order?" prompt on
       picnic, soiree and orbit, and counting it is why an earlier audit
       reported item reviews on themes that have none. */
    var SECTION = '.product-reviews, .p-reviews, .p-reviews-row, .ff-reviews, .v-reviews, [data-item-reviews]';
    /* Themes whose widget is already complete carry their own distribution. */
    var HAS_HISTOGRAM = '.rv-dist, .rv-bar, [data-rv-dist]';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function visible(el) {
        var r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        var c = window.getComputedStyle(el);

        return c.display !== 'none' && c.visibility !== 'hidden' && parseFloat(c.opacity) > 0.01;
    }

    /* Which item is open. The themes rebuild their product body from JS and do
       not publish the id, so it is taken from the click that opened it — the
       same attribute union the other shared sections resolve against. A bare
       [data-id] is only trusted on a node that looks like an item card (it is
       also what category chips use). */
    var itemId = null;
    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        var hook = e.target.closest('[data-open-item], [data-open], [data-item-id], [data-menu-id]');
        var id = hook && (hook.getAttribute('data-open-item') || hook.getAttribute('data-open')
            || hook.getAttribute('data-item-id') || hook.getAttribute('data-menu-id'));
        if (!id) {
            var card = e.target.closest('[data-id]');
            if (card && card.querySelector('img')) id = card.getAttribute('data-id');
        }
        if (id && /^\d+$/.test(String(id))) { itemId = String(id); schedule(); }
    }, true);

    function schedule() {
        // Themes rebuild the product body asynchronously; catch it whenever it lands.
        [250, 800, 1600].forEach(function (ms) { setTimeout(enhance, ms); });
    }

    function styles() {
        if (document.getElementById('mbrStyles')) return;
        var css = document.createElement('style');
        css.id = 'mbrStyles';
        @if(function_exists('csp_nonce')) css.setAttribute('nonce', @json(csp_nonce())); @endif
        css.textContent = [
            /* Inherits the theme's colours; no surface of its own, for the same
               reason the other injected sections have none — it cannot know what
               it is sitting on. */
            '.mbr-x{display:grid;gap:10px;margin:10px 0 2px}',
            '.mbr-x-top{display:flex;align-items:center;justify-content:space-between;gap:12px}',
            '.mbr-x-avg{display:flex;align-items:baseline;gap:6px}',
            '.mbr-x-avg b{font-size:1.35rem;line-height:1}',
            '.mbr-x-avg small{opacity:.7;font-size:.8rem}',
            '.mbr-x-write{appearance:none;border:1px solid currentColor;background:none;color:inherit;',
            'padding:6px 13px;border-radius:999px;font:inherit;font-size:.8rem;font-weight:600;cursor:pointer;opacity:.9}',
            '.mbr-x-dist{display:grid;gap:4px}',
            '.mbr-x-bar{display:grid;grid-template-columns:1.2em 1fr 2em;align-items:center;gap:8px;font-size:.75rem;opacity:.85}',
            '.mbr-x-bar i{display:block;height:6px;border-radius:999px;background:rgba(127,127,127,.28);overflow:hidden}',
            '.mbr-x-bar i s{display:block;height:100%;background:var(--tc-primary,var(--theme-color-1,currentColor));opacity:.85}',
            '.mbr-x-bar b{text-align:end;font-weight:600;opacity:.75}',
            /* Sheet */
            '.mbr-sheet{position:fixed;inset:0;z-index:2147483001;display:flex;align-items:flex-end;justify-content:center;background:rgba(0,0,0,.5)}',
            '.mbr-sheet[hidden]{display:none!important}',
            '.mbr-card{width:100%;max-width:520px;background:var(--tc-surface,#fff);color:var(--tc-text,#14181d);',
            'border-radius:18px 18px 0 0;padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));display:grid;gap:12px}',
            '.mbr-card h2{margin:0;font-size:1.05rem}',
            '.mbr-stars-in{display:flex;gap:4px}',
            '.mbr-stars-in button{appearance:none;border:0;background:none;font-size:1.7rem;line-height:1;cursor:pointer;color:inherit;opacity:.3;padding:0}',
            '.mbr-stars-in button.is-on{opacity:1;color:#f5a524}',
            '.mbr-card label{display:grid;gap:5px;font-size:.8rem;opacity:.8}',
            '.mbr-card input,.mbr-card textarea{font:inherit;padding:9px 11px;border-radius:10px;border:1px solid rgba(127,127,127,.4);',
            'background:transparent;color:inherit;width:100%}',
            '.mbr-card textarea{min-height:84px;resize:vertical}',
            '.mbr-act{display:flex;gap:10px;justify-content:flex-end}',
            '.mbr-act button{appearance:none;border:0;border-radius:999px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}',
            '.mbr-cancel{background:rgba(127,127,127,.18);color:inherit}',
            '.mbr-send{background:var(--tc-primary,var(--theme-color-1,#e0480f));color:var(--tc-on-primary,#fff)}',
            '.mbr-msg{margin:0;font-size:.85rem;min-height:1.2em}',
            '.mbr-msg.is-bad{color:#e5484d}',
        ].join('');
        document.head.appendChild(css);
    }

    /**
     * Does the theme's own section already print a rating figure?
     *
     * orbit heads its section "Reviews 84" and velora "Reviews 118" — the item's
     * aggregate rating count — while this endpoint counts APPROVED item reviews
     * (1 on the demo data). Two different numbers side by side read as a bug, so
     * where the theme already shows a figure, only the bars and the write button
     * are added. Measured on the section with its review list removed, because
     * the reviews themselves are full of digits.
     */
    function showsFigures(section) {
        var probe = section.cloneNode(true);
        [].slice.call(probe.querySelectorAll('article, li, p, .mbr-x, .rv-item')).forEach(function (n) { n.remove(); });

        return /\d/.test(probe.textContent || '');
    }

    function histogram(payload, withFigures) {
        var count = Number(payload.count || 0);
        var dist = payload.distribution || {};
        var avg = Number(payload.average || 0);

        return '<div class="mbr-x-top">'
            + (withFigures
                ? '<span class="mbr-x-avg"><b>' + avg.toFixed(1) + '</b>'
                    + '<small>' + esc(count + ' ' + I18N.reviews) + '</small></span>'
                : '<span></span>')
            + '<button class="mbr-x-write" type="button" data-mbr-write>' + esc(I18N.write) + '</button>'
            + '</div>'
            + '<div class="mbr-x-dist">' + [5, 4, 3, 2, 1].map(function (s) {
                var c = Number(dist[s] || 0);
                var pct = count ? Math.round(c / count * 100) : 0;

                return '<span class="mbr-x-bar"><em>' + s + '</em>'
                    + '<i><s style="width:' + pct + '%"></s></i><b>' + c + '</b></span>';
            }).join('') + '</div>';
    }

    function enhance() {
        if (!itemId) return;
        var sections = [].slice.call(document.querySelectorAll(SECTION)).filter(visible);
        if (!sections.length) return;

        sections.forEach(function (section) {
            // Leave a theme that already ships the full widget alone.
            if (section.querySelector(HAS_HISTOGRAM)) return;

            /* crumb's "reviews section" is a compact FLEX ROW — a count and an
               average on one line — so putting a block of bars inside it made
               them a squashed third column. A row gets the block as a sibling
               underneath; a real container gets it inside, under its heading. */
            var isRow = /flex/.test(window.getComputedStyle(section).display)
                && section.getBoundingClientRect().height < 110;

            var sibling = section.nextElementSibling;
            var block = (sibling && sibling.classList && sibling.classList.contains('mbr-x'))
                ? sibling
                : section.querySelector(':scope > .mbr-x');
            if (block && block.getAttribute('data-mbr-for') === itemId) return; // already done

            styles();
            if (!block) {
                block = document.createElement('div');
                block.className = 'mbr-x';
                var head = section.querySelector('.section-head, .p-sec-head, h2, h3');
                if (isRow) section.insertAdjacentElement('afterend', block);
                else if (head && head.parentElement === section) head.insertAdjacentElement('afterend', block);
                else section.insertBefore(block, section.firstChild);
            }
            block.setAttribute('data-mbr-for', itemId);
            block.hidden = true;

            var wanted = itemId;
            var withFigures = !showsFigures(section);
            fetch(BASE + '/' + wanted + '/reviews?filter=latest', { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (payload) {
                    // The guest may have moved on to another item mid-flight.
                    if (!payload || block.getAttribute('data-mbr-for') !== wanted) return;
                    block.innerHTML = histogram(payload, withFigures);
                    block.hidden = false;
                })
                .catch(function () { /* leave it hidden — never an empty shell */ });
        });
    }

    /* ------------------------------------------------------------- writing */

    var sheet = null;
    var chosen = 0;

    function buildSheet() {
        if (sheet) return sheet;
        styles();
        /* Dialog semantics live in the markup, not in JS attribute calls.
           SheetA11yTest detects the shared sheet-a11y helper by searching the
           rendered page for the line of source that sets aria-modal, so any
           second script writing that attribute the same way reports the helper
           as shipped while its kill switch is off. (Including in a COMMENT: the
           first fix here failed because the explanation quoted the very line it
           was avoiding, and JS comments ship to the page.) */
        var host = document.createElement('div');
        host.innerHTML = '<div class="mbr-sheet" role="dialog" aria-modal="true" hidden></div>';
        sheet = host.firstElementChild;
        sheet.innerHTML = '<form class="mbr-card">'
            + '<h2>' + esc(I18N.yourReview) + '</h2>'
            + '<div><div class="mbr-stars-in" role="group" aria-label="' + esc(I18N.rating) + '">'
            + [1, 2, 3, 4, 5].map(function (n) {
                return '<button type="button" data-mbr-star="' + n + '" aria-label="' + n + '">★</button>';
            }).join('') + '</div></div>'
            + '<label>' + esc(I18N.yourName) + ' <span>(' + esc(I18N.optional) + ')</span>'
            + '<input type="text" maxlength="80" data-mbr-name autocomplete="name"></label>'
            + '<label>' + esc(I18N.yourReview) + ' <span>(' + esc(I18N.optional) + ')</span>'
            + '<textarea maxlength="2000" data-mbr-text></textarea></label>'
            + '<p class="mbr-msg" data-mbr-msg></p>'
            + '<div class="mbr-act">'
            + '<button type="button" class="mbr-cancel" data-mbr-cancel>' + esc(I18N.cancel) + '</button>'
            + '<button type="submit" class="mbr-send" data-mbr-send>' + esc(I18N.submit) + '</button>'
            + '</div></form>';
        document.body.appendChild(sheet);

        sheet.addEventListener('click', function (e) {
            if (e.target === sheet || e.target.closest('[data-mbr-cancel]')) { close(); return; }
            var star = e.target.closest('[data-mbr-star]');
            if (star) {
                chosen = Number(star.getAttribute('data-mbr-star'));
                paintChosen();
            }
        });
        sheet.querySelector('form').addEventListener('submit', function (e) {
            e.preventDefault();
            send();
        });
        document.addEventListener('keydown', function (e) {
            if (!sheet.hidden && e.key === 'Escape') close();
        });

        return sheet;
    }

    function msg(text, bad) {
        var el = sheet.querySelector('[data-mbr-msg]');
        el.textContent = text || '';
        el.classList.toggle('is-bad', !!bad);
    }

    var returnFocusTo = null;
    var onSuccess = null;

    function paintChosen() {
        [].slice.call(sheet.querySelectorAll('[data-mbr-star]')).forEach(function (b, i) {
            b.classList.toggle('is-on', i < chosen);
        });
    }

    /**
     * @param  {number}   [preStars]   rating to preselect (a theme's inline stars)
     * @param  {object}   [opts]       { onSuccess: fn(stars) } — kept for velora's
     *                                 high-rating Google funnel, which used to live
     *                                 on its now-removed inline post.
     */
    function open(preStars, opts) {
        buildSheet();
        returnFocusTo = document.activeElement;
        onSuccess = (opts && typeof opts.onSuccess === 'function') ? opts.onSuccess : null;
        chosen = (preStars >= 1 && preStars <= 5) ? Math.round(preStars) : 0;
        paintChosen();
        sheet.querySelector('[data-mbr-name]').value = '';
        sheet.querySelector('[data-mbr-text]').value = '';
        msg('');
        sheet.querySelector('[data-mbr-send]').disabled = false;
        sheet.hidden = false;
        // Land on the rating (the one required field), or the text box when a
        // theme's inline stars already chose one.
        var focusOn = chosen
            ? sheet.querySelector('[data-mbr-text]')
            : sheet.querySelector('[data-mbr-star="1"]');
        if (focusOn) focusOn.focus();
    }

    function close() {
        if (!sheet) return;
        sheet.hidden = true;
        // Back to the control that opened it, not to the top of the document.
        if (returnFocusTo && document.contains(returnFocusTo)) returnFocusTo.focus();
        returnFocusTo = null;
    }

    function send() {
        if (!chosen) { msg(I18N.chooseRating, true); return; }
        var token = document.querySelector('meta[name="csrf-token"]');
        var btn = sheet.querySelector('[data-mbr-send]');
        btn.disabled = true;
        msg('');

        fetch(BASE + '/' + itemId + '/reviews', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
            },
            body: JSON.stringify({
                rating: chosen,
                text: sheet.querySelector('[data-mbr-text]').value.trim() || null,
                name: sheet.querySelector('[data-mbr-name]').value.trim() || null,
            }),
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.ok) { msg(I18N.failed, true); btn.disabled = false; return; }
                // Held for owner approval, so the list does not change yet — say so
                // rather than showing a review that is not public.
                msg(I18N.thanks);
                if (onSuccess) { try { onSuccess(chosen); } catch (e) {} }
                setTimeout(close, 1800);
            })
            .catch(function () { msg(I18N.failed, true); btn.disabled = false; });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-mbr-write]')) open();
    });

    /* One write flow, reachable two ways: the histogram's own button (above) and
       a theme's inline "rate this dish" stars, which used to POST immediately to
       a stars-only endpoint — a second, text-less path that produced a separate
       pending review. orbit and velora now call open(stars) instead, so every
       rating goes through this sheet and this endpoint. */
    window.mbItemReviews = { open: open };
})();
</script>
@endif
