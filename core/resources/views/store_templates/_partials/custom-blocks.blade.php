{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Theme Studio phase A — the store's published custom blocks (owner-authored
  Builder.js HTML) on the Blade themes.

  Server-rendered once, then moved to the block's chosen anchor by
  window.mbAnchor (the same placement engine as the offers strip):
    top          top of the theme's scrollable content (placeTop)
    after-hero   right after the first thing in that content (the hero)
    before-menu  next to the menu grid (place)
    after-menu   right after the menu grid
    footer       bottom of the content (placeBottom)
  Hidden (data-mb-pending) until placed, so nothing flashes at the top of the
  page; a block that cannot be placed (a theme with no recognisable menu grid)
  is revealed where it was rendered after a short grace period.

  Gated by FeatureAccess::storeAllows(post, 'custom_sections') — never feature
  middleware on the storefront. Scoped to THIS store's rows; a block with a
  language shows only in that language. Output goes through the one builder
  render pipeline (StoreCustomBlock::renderFor → BuilderRender::page: cleanPage
  at echo stamps the CSP nonce on kept style blocks, merge tags are escaped, curated
  forms post to builder.form.submit). Renders nothing when there is no block.

  Layouts without the shared anchor helper (the vertical themes) pass
  `mbcbAnchor => true` so it is included here.
--}}
@php
    $mbcbPost = $store ?? null;
    $mbcbBlocks = collect();
    if ($mbcbPost instanceof \Juwri\Core\Models\Store && ! empty($mbcbPost->id) && isset($total_menus)) {
        try {
            if (\App\Services\FeatureAccess::storeAllows((int) $mbcbPost->id, \App\Models\StoreCustomBlock::FEATURE)) {
                $mbcbBlocks = collect(\Juwri\Core\Support\Views\StoreViewHelpers::customBlocksByPlacement(
                    \App\Models\StoreCustomBlock::visibleFor((int) $mbcbPost->id, app()->getLocale())->all(),
                    \App\Models\StoreCustomBlock::PLACEMENTS
                ));
            }
        } catch (\Throwable $e) {
            report($e); // an add-on section never takes the storefront down
            $mbcbBlocks = collect();
        }
    }
@endphp
@if ($mbcbBlocks->isNotEmpty())
@if (! empty($mbcbAnchor))
@include('store_templates._partials.theme-anchor')
@endif
@include('builder._runtime-assets')
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbcb{display:block;inline-size:100%;box-sizing:border-box;color:var(--mb-ink,inherit)}
.mbcb[data-mb-pending]{display:none}
</style>
@foreach ($mbcbBlocks as $mbcbBlock)
<section class="mbcb" id="mbcb-{{ $mbcbBlock->id }}" data-mbcb-placement="{{ $mbcbBlock->placement }}" data-mb-pending aria-label="{{ $mbcbBlock->name }}">{!! $mbcbBlock->renderFor($mbcbPost) !!}</section>
@endforeach
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var A = window.mbAnchor;
    var blocks = Array.prototype.slice.call(document.querySelectorAll('section.mbcb[data-mbcb-placement]'));
    if (!blocks.length) return;

    function reveal(el) { el.removeAttribute('data-mb-pending'); if (A) A.ink(el); }

    /* The block that sits after `ref` among blocks of the same placement keeps
       the owner's order (creation order = server order). */
    function after(el, ref) {
        if (!ref || !ref.parentElement || ref === el || el.contains(ref)) return false;
        if (ref.nextElementSibling === el) return true;
        ref.parentElement.insertBefore(el, ref.nextElementSibling);
        return true;
    }

    function gridBlock(el) {
        var grid = A.menuGrid(el);
        if (!grid) return null;
        /* Same climb as place(): the grid's own wrapper while it is still ABOUT the grid. */
        var h = grid.getBoundingClientRect().height || 1, block = grid;
        for (var i = 0; i < 2 && block.parentElement && block.parentElement.getBoundingClientRect().height <= h * 1.6; i++) {
            if (/^(MAIN|BODY)$/.test(block.parentElement.tagName)) break;
            block = block.parentElement;
        }
        return block;
    }

    var last = {};
    function placeOne(el) {
        var where = el.getAttribute('data-mbcb-placement');
        var prev = last[where];
        if (prev && prev !== el && document.contains(prev) && !prev.hasAttribute('data-mb-pending')) {
            return after(el, prev); // stack behind the previous block of this placement
        }
        switch (where) {
            case 'top': return A.placeTop(el);
            case 'after-hero':
                if (!A.placeTop(el)) return false;
                var hero = el.nextElementSibling;
                while (hero && hero.classList && hero.classList.contains('mbcb')) hero = hero.nextElementSibling;
                return hero ? after(el, hero) : true;
            case 'before-menu': return A.place(el);
            case 'after-menu':
                var b = gridBlock(el);
                return b ? after(el, b) : false;
            case 'footer': return A.placeBottom(el);
        }
        return false;
    }

    function sync() {
        if (!A) return;
        blocks.forEach(function (el) {
            if (!el.hasAttribute('data-mb-pending')) return;
            if (placeOne(el)) { reveal(el); last[el.getAttribute('data-mbcb-placement')] = el; }
        });
    }

    if (A) { blocks.forEach(function (el) { A.onSettled(sync, el); }); }
    /* Grace period: a theme with no menu grid keeps the block where it was
       rendered rather than hiding the owner's content. */
    window.setTimeout(function () {
        blocks.forEach(function (el) { if (el.hasAttribute('data-mb-pending')) reveal(el); });
    }, A ? 3500 : 0);
})();
</script>
@endif
