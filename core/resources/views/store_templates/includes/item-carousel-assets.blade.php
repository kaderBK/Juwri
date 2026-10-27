{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared item photo carousel for storefront themes. Framework-free (vanilla JS, no Alpine).
     Usage inside a theme's modal builder:  mbCarousel.html([item.img].concat(item.gallery||[]))
     Returns a self-wiring carousel HTML string (arrows + dots + swipe). One image => plain image. --}}
<style nonce="{{ csp_nonce() }}">
    .mic { position: relative; inline-size: 100%; overflow: hidden; border-radius: inherit; }
    /* direction:ltr keeps the flex slide order and the translateX(-i*100%) paging
       math valid inside RTL (Arabic) themes — without it the track reverses and a
       multi-image item pages to a blank/wrong slide. Arrows/dots stay logical (on .mic). */
    .mic__track { display: flex; direction: ltr; transition: transform .4s cubic-bezier(.4,0,.2,1); will-change: transform; }
    .mic__slide { flex: 0 0 100%; inline-size: 100%; }
    .mic__slide img { inline-size: 100%; display: block; object-fit: cover; }
    .mic__nav { position: absolute; inset-block-start: 50%; transform: translateY(-50%); z-index: 3;
        inline-size: 34px; block-size: 34px; border: 0; border-radius: 50%; cursor: pointer;
        background: rgba(0,0,0,.45); color: #fff; font-size: 20px; line-height: 1; display: grid; place-items: center; }
    .mic__nav:hover { background: rgba(0,0,0,.7); }
    .mic__nav--prev { inset-inline-start: 8px; } .mic__nav--next { inset-inline-end: 8px; }
    .mic__dots { position: absolute; inset-block-end: 8px; inset-inline: 0; display: flex; gap: 5px; justify-content: center; z-index: 3; }
    .mic__dot { inline-size: 7px; block-size: 7px; padding: 0; border: 0; border-radius: 50%; cursor: pointer; background: rgba(255,255,255,.55); transition: inline-size .2s; }
    .mic__dot.is-active { background: #fff; inline-size: 18px; border-radius: 4px; }
</style>
<script nonce="{{ csp_nonce() }}">
(function () {
    function esc(u) { return String(u == null ? '' : u).replace(/"/g, '&quot;'); }
    window.mbCarousel = {
        // Build carousel markup from an array of image URLs (falsy/empty entries dropped).
        html: function (images) {
            var imgs = (images || []).filter(function (u) { return u; });
            if (imgs.length === 0) return '';
            if (imgs.length === 1) {
                return '<div class="mic"><div class="mic__track"><div class="mic__slide">' +
                    '<img src="' + esc(imgs[0]) + '" alt="" data-csp-error="carousel-fail"></div></div></div>';
            }
            var slides = imgs.map(function (u) {
                return '<div class="mic__slide"><img src="' + esc(u) + '" alt="" data-csp-error="carousel-fail"></div>';
            }).join('');
            var dots = imgs.map(function (u, i) {
                return '<button type="button" class="mic__dot' + (i === 0 ? ' is-active' : '') +
                    '" data-csp-carousel-go="' + i + '" aria-label="' + (i + 1) + '"></button>';
            }).join('');
            return '<div class="mic" data-i="0" data-n="' + imgs.length + '">' +
                '<div class="mic__track">' + slides + '</div>' +
                '<button type="button" class="mic__nav mic__nav--prev" data-csp-carousel-step="-1" aria-label="prev">&#8249;</button>' +
                '<button type="button" class="mic__nav mic__nav--next" data-csp-carousel-step="1" aria-label="next">&#8250;</button>' +
                '<div class="mic__dots">' + dots + '</div></div>';
        },
        _root: function (el) { return el.closest ? el.closest('.mic') : null; },
        // A 404'd image must not keep holding its box: drop the slide, and drop the
        // whole .mic when nothing survives so host themes' :has()/:empty guards can
        // collapse the reserved media slot. Themes that also watch for `error`
        // still get their event (dispatch completes on the detached target).
        _fail: function (img) {
            var r = this._root(img);
            var slide = img.closest ? img.closest('.mic__slide') : null;
            if (slide) { slide.remove(); } else { img.remove(); }
            if (!r) return;
            var slides = r.querySelectorAll('.mic__slide');
            if (slides.length === 0) { r.remove(); return; }
            // Re-index: the dot/arrow controls are only meaningful past one slide.
            var dots = r.querySelectorAll('.mic__dot');
            for (var k = dots.length - 1; k >= slides.length; k--) dots[k].remove();
            r.dataset.n = slides.length;
            if (slides.length === 1) {
                var chrome = r.querySelectorAll('.mic__nav, .mic__dots');
                for (var j = 0; j < chrome.length; j++) chrome[j].remove();
            }
            this._set(r, 0);
        },
        go: function (el, i) { var r = this._root(el); if (r) this._set(r, i); },
        step: function (el, d) { var r = this._root(el); if (r) this._set(r, parseInt(r.dataset.i || 0, 10) + d); },
        _set: function (r, i) {
            var n = parseInt(r.dataset.n || 1, 10);
            i = ((i % n) + n) % n;
            r.dataset.i = i;
            var track = r.querySelector('.mic__track');
            if (track) track.style.transform = 'translateX(-' + (i * 100) + '%)';
            var dots = r.querySelectorAll('.mic__dot');
            for (var k = 0; k < dots.length; k++) dots[k].classList.toggle('is-active', k === i);
        }
    };
    // Swipe via event delegation (works for carousels inserted after load).
    var tsx = null, tcar = null;
    document.addEventListener('touchstart', function (e) {
        var c = e.target.closest ? e.target.closest('.mic') : null;
        if (c) { tcar = c; tsx = e.changedTouches[0].clientX; }
    }, { passive: true });
    document.addEventListener('touchend', function (e) {
        if (!tcar || tsx == null) return;
        var dx = e.changedTouches[0].clientX - tsx;
        if (Math.abs(dx) > 40) { mbCarousel._set(tcar, parseInt(tcar.dataset.i || 0, 10) + (dx < 0 ? 1 : -1)); }
        tcar = null; tsx = null;
    }, { passive: true });
})();
</script>
