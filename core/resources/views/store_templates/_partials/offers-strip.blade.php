{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared offers strip — the discounted items a store is selling RIGHT NOW.

  "There is no offer-strip section" was reported against several themes. The
  capability matrix showed the reference theme has none either, so this could
  not be a port: it is a new surface, and the owner confirmed an offer here
  means a DISCOUNTED ITEM (not a coupon — `.offer-strip` on crumb and `.promos`
  on orbit are coupon and banner slots, and both keep their own meaning).

  A discount is a RUNTIME fact, not a column. MenuScheduleService::decorate()
  rewrites `price` and sets `original_price` for whatever is inside a live
  PriceSchedule window, and StoreController@publicView already decorated every
  item in `$total_menus` before this renders — so the strip is a filter over
  data the page already has, costing no extra query.

  Renders NOTHING when the store has no live offers. An empty shell is exactly
  the trap that produced four of the original theme reports: a control that is
  present but blank reads as broken, and reviewers then file it as a bug in the
  code when the truth is the store has no data.
--}}
@php
    /*
     * Scoped to the 17 retained modern templates. The shared layout is also the
     * layout for ~40 legacy templates carrying the overwhelming majority of live
     * stores, and this injects markup at the top of <main> — the same structural
     * risk that keeps the shared stories rail opt-in. Those templates already
     * show a struck-through price on the item card itself via hh_price_cell(),
     * so nothing is hidden from them; they just do not grow a new section.
     */
    $mboTemplates = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN;

    $mboItems = [];
    $mboPost = $store ?? null;

    /* Money is formatted the way the THEMES do it, not through
       price_symbol_format(): publicView copies the store's own currency options
       into config('settings.*') before rendering, so a store that never set a
       sign clobbers the global one with null and the helper prints a bare
       number. Every theme guards that with `$postOptions->currency_sign ?? '$'`
       — matching it keeps the strip's price and the card behind it identical. */
    $mboSign = cur_isolate($postOptions->currency_sign ?? config('settings.currency_sign') ?? '$');
    // Locale-aware position (RTL/Arabic → sign-first, which renders visually after
    // the number). Use the effective helper, not raw currency_pos, so the strip
    // matches the rest of the storefront.
    $mboLeft = currency_pos_effective($postOptions->id ?? null) === 1;
    // UAE dirham (U+20C3) needs a space off the number; every other sign is gap-less.
    $mboGap = currency_symbol_gap($postOptions->currency_sign ?? config('settings.currency_sign') ?? '$');
    $mboMoney = fn ($v) => $mboLeft ? $mboSign.$mboGap.price_format($v) : price_format($v).$mboGap.$mboSign;

    if (is_object($mboPost) && ! empty($mboPost->id) && ! empty($total_menus)) {
        $mboTemplate = app(\App\Services\StorefrontThemeService::class)->resolveSaved($mboPost);

        if (in_array($mboTemplate, $mboTemplates, true)) {
            foreach ($total_menus as $mboMenu) {
                if (! is_object($mboMenu) || empty($mboMenu->original_price)) {
                    continue;
                }
                /* decorate() clamps a schedule so it can never RAISE a price, so
                   this is normally redundant — but a strip that could print a
                   "discount" to a higher number is not worth the saved line. */
                if ((float) $mboMenu->original_price <= (float) $mboMenu->price) {
                    continue;
                }
                // Sold out is still sold out; sending a diner to an item they
                // cannot order is a worse dead end than a shorter strip.
                if (! empty($mboMenu->is_sold_out)) {
                    continue;
                }
                $mboItems[] = $mboMenu;
                if (count($mboItems) >= 12) {
                    break;
                }
            }
        }
    }
@endphp

@if(! empty($mboItems))
{{-- data-mb-pending: server-rendered at the top of <main>, which flashes a row
     of product cards above the theme's own header on every reload until
     mbAnchor has moved it next to the menu. Hidden until placement succeeds. --}}
<section class="mbo-strip" id="mbOffersStrip" data-offer-strip data-mb-pending aria-labelledby="mbOffersTitle" data-mbo-base-cur="{{ config('settings.currency_code', 'USD') }}">
    <div class="mbo-head">
        {{-- "On sale", not "offers": crave already runs a daily-specials row
             titled عروض اليوم directly above this, and two adjacent rows both
             called offers read as one duplicated section. A price cut is what
             this strip actually shows, so it says so. --}}
        <h2 class="mbo-title" id="mbOffersTitle">{{ ___('On sale now') }}</h2>
    </div>
    <div class="mbo-rail">
        @foreach($mboItems as $mboItem)
            @php
                $mboOff = (int) round(100 - (((float) $mboItem->price / (float) $mboItem->original_price) * 100));
            @endphp
            <button class="mbo-card" type="button" data-mbo-item="{{ $mboItem->id }}">
                @if(! empty($mboItem->image))
                    <span class="mbo-media">
                        {{-- A 404 photo used to leave a bare grey block at the head of the
                             card (live media has real gaps) — drop the media box and let
                             the card read as name + price instead. --}}
                        @if((string) (media_url('storage/menu/', $mboItem->image, $mboPost->id)) !== '')<img src="{{ media_url('storage/menu/', $mboItem->image, $mboPost->id) }}" alt="" loading="lazy" data-csp-error="closest-hide" data-csp-closest=".mbo-media">@endif
                        @if($mboOff > 0)
                            {{-- dir=ltr: a bare "−20%" reorders to "20%−" in an
                                 RTL storefront, which reads as a typo. --}}
                            <span class="mbo-off" dir="ltr">&minus;{{ $mboOff }}%</span>
                        @endif
                    </span>
                @endif
                <span class="mbo-body">
                    <span class="mbo-name">{{ $mboItem->name }}</span>
                    <span class="mbo-prices">
                        {{-- data-mbo-base carries the raw base-currency amount so the
                             strip can re-render as a "≈ CODE value" estimate when the
                             diner switches display currency (MC-34), matching every
                             theme's own converted-price format. --}}
                        <span class="mbo-now" data-mbo-base="{{ (float) $mboItem->price }}">{{ $mboMoney($mboItem->price) }}</span>
                        <del class="mbo-was" data-mbo-base="{{ (float) $mboItem->original_price }}">{{ $mboMoney($mboItem->original_price) }}</del>
                    </span>
                    @if(! empty($mboItem->schedule_label))
                        <span class="mbo-label">{{ $mboItem->schedule_label }}</span>
                    @endif
                </span>
            </button>
        @endforeach
    </div>
</section>

{{-- Scoped styles (`mbo-` prefix). Colour comes from the customizer's theme
     tokens where a store set them, and falls back to the shared legacy
     `--theme-color-1` and then to neutrals — the same fallback chain every
     v2-aware theme uses, so an untouched store keeps its own brand. --}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbo-strip{padding:14px 16px 6px;font-family:var(--tf-body,inherit)}
.mbo-strip[data-mb-pending]{display:none}
.mbo-strip[hidden]{display:none!important}
.mbo-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:0 0 10px}
.mbo-title{margin:0;font-family:var(--tf-heading,inherit);font-size:16px;font-weight:700;line-height:1.2;color:var(--mb-ink,var(--tc-text,inherit))}
.mbo-rail{display:flex;gap:12px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;padding-bottom:8px}
.mbo-rail::-webkit-scrollbar{display:none}
/* NO card surface of its own. Two earlier attempts both produced unreadable
   text on some themes — #fff put the dark themes' light text on white, and a
   translucent grey tint went pale-yellow on scoop and swallowed it again. The
   strip cannot know what it is sitting on, so it paints nothing: the labels
   render on the theme's own background in the theme's own text colour, which
   is a pairing the theme already guarantees. Only the photo gets a shape. */
.mbo-card{flex:0 0 auto;width:148px;padding:0;border:0;background:none;text-align:start;font:inherit;color:inherit;cursor:pointer}
.mbo-card[data-mbo-inert]{cursor:default}
.mbo-media{position:relative;display:block;aspect-ratio:4/3;background:rgba(127,127,127,.14);border-radius:var(--tr-card,14px);overflow:hidden}
.mbo-media img{width:100%;height:100%;object-fit:cover;display:block}
.mbo-off{position:absolute;top:8px;inset-inline-start:8px;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.4;
    background:var(--tc-primary,var(--theme-color-1,#e0480f));color:var(--tc-on-primary,#fff)}
.mbo-body{display:block;padding:9px 2px 2px}
.mbo-name{display:block;font-size:13px;font-weight:600;line-height:1.3;color:var(--mb-ink,var(--tc-text,inherit));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mbo-prices{display:flex;align-items:baseline;gap:6px;margin-top:5px;flex-wrap:wrap}
/* Ink, not the brand colour: scoop's pink on scoop's yellow is the theme's
   own pairing for a BUTTON, and unreadable as 14px text. The badge below
   carries the brand — it brings its own solid background with it. */
.mbo-now{font-size:14px;font-weight:700;color:var(--mb-ink,inherit);direction:ltr;unicode-bidi:isolate}
.mbo-was{font-size:12px;opacity:.8;color:var(--mb-ink,var(--tc-text-muted,inherit));direction:ltr;unicode-bidi:isolate}
.mbo-label{display:block;margin-top:5px;font-size:11px;opacity:.7;color:var(--mb-ink,var(--tc-text-muted,inherit));overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>

<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var strip = document.getElementById('mbOffersStrip');
    // See the note in _partials/home-order-type: mbAnchor is a sibling partial.
    if (!strip || !window.mbAnchor) return;

    /* Stand down for a theme that grows its own discounted-items strip later.
       Deliberately NOT matched on `.offers` / `.promo`: those already exist on
       six of the seventeen and mean coupons or banner carousels, so treating
       them as "this theme has offers covered" would suppress this strip on
       themes that show no discounted item anywhere. */
    var native = document.querySelector('[data-offer-strip]:not(#mbOffersStrip)');
    if (native) { strip.remove(); return; }

    /* Every theme opens its own item sheet, so the card cannot know the call to
       make. Find the theme's own card for that item and click it — one code path
       for seventeen bundles. If a theme renders no matching node the button
       would be a dead control, so it stops looking like one (story-cta lesson:
       a control that goes nowhere is worse than no control).

       Resolved in TWO passes rather than one selector list, because a theme's
       OPEN HOOK must win over a node that merely carries the id: aurora and
       vivid render both — the id on the <article>, the hook on a child — and
       their delegated listener only answers to [data-open-item], so clicking
       the article did nothing while still looking like a resolved target. */
    var HOOKS = ['[data-open-item="', '[data-open="'];
    var IDENTS = ['[data-item-id="', '[data-menu-id="', '[data-id="'];

    function firstOutside(sel) {
        var nodes = document.querySelectorAll(sel);
        for (var i = 0; i < nodes.length; i++) {
            if (!strip.contains(nodes[i])) return nodes[i];
        }
        return null;
    }

    function themeCard(id) {
        var lists = [HOOKS, IDENTS];
        for (var l = 0; l < lists.length; l++) {
            var sel = lists[l].map(function (p) { return p + id + '"]'; }).join(', ');
            var hit = firstOutside(sel);
            if (hit) return hit;
        }
        return null;
    }

    function wire() {
        var cards = strip.querySelectorAll('.mbo-card');
        for (var i = 0; i < cards.length; i++) {
            var target = themeCard(cards[i].getAttribute('data-mbo-item'));
            if (target) cards[i].removeAttribute('data-mbo-inert');
            else cards[i].setAttribute('data-mbo-inert', '');
        }
    }

    /* MC-34 — follow the diner's display-currency choice. The prices above are
       server-rendered in the store's BASE currency; the rest of the storefront
       re-renders to a "≈ CODE value" estimate when the diner picks another
       currency, and a strip left in base $ was the one panel still quoting the
       wrong money. Every theme persists the choice through window.mbCurrency and
       carries the estimate in the same `≈ CODE value` form (2 dp for a converted
       currency — the shared fallback), so mirroring it keeps the strip in step. */
    var BASE_CUR = String(strip.getAttribute('data-mbo-base-cur') || '');
    function applyCurrency(code, rate) {
        if (code == null && window.mbCurrency && mbCurrency.load) {
            var s = mbCurrency.load();
            if (s) { code = s.code; rate = s.rate; }
        }
        rate = parseFloat(rate);
        if (isNaN(rate)) rate = 1;
        var estimate = code && code !== BASE_CUR && rate > 0;
        var els = strip.querySelectorAll('[data-mbo-base]');
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            // Cache the server's base-currency text so switching back is exact.
            if (el.getAttribute('data-mbo-orig') == null) el.setAttribute('data-mbo-orig', el.textContent);
            if (estimate) {
                el.textContent = '≈ ' + code + ' ' + (parseFloat(el.getAttribute('data-mbo-base')) * rate).toFixed(2);
            } else {
                el.textContent = el.getAttribute('data-mbo-orig');
            }
        }
    }
    /* The switcher lives in the theme's own chrome, not here, so watch document
       clicks. Defer past the theme's handler (which sets .is-on and persists via
       mbCurrency.save) and read the button's own code/rate so it works even on a
       theme that has not adopted the shared persistence helper. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cur]');
        if (!btn) return;
        var code = btn.getAttribute('data-cur');
        var rate = btn.getAttribute('data-rate');
        window.setTimeout(function () { applyCurrency(code, rate); }, 0);
    });
    // Placement, ink and the settle cadence are shared with the other
    // injected sections — see _partials/theme-anchor.blade.php.
    function sync() {
        wire();
        // Position only — the reveal is gated separately (see bootReveal).
        mbAnchor.place(strip);
        mbAnchor.ink(strip);
        // Match the persisted display-currency choice on (re)settle.
        applyCurrency();
    }
    mbAnchor.onSettled(sync, strip);

    /* Reveal only after the page's `load` event + a short beat, by which time
       the theme has populated its REAL menu grid. Before that, place() can
       latch onto an intermediate container — a "Today's specials" rail (6
       cards) wins over the still-empty main grid (0 cards during the skeleton
       phase) — and the strip gets placed there, revealed, and ~1.5s later
       jumps down beside the finished grid. A diner reads the jump as the
       first offer card's photo flashing and then disappearing. Gating on
       load+beat keeps the strip hidden until cvGrid actually exists, so the
       FIRST visible placement is the final one. Worst case is roughly the
       time it takes the catalogue to render + a 600ms reflow window —
       typically 2.5–3.5s on a heavy store, well after the splash settles.
       onSettled above keeps the strip glued to its anchor on later theme
       flips; this block only runs once. */
    var revealed = false;
    function bootReveal() {
        if (revealed || !strip.hasAttribute('data-mb-pending')) return;
        if (!mbAnchor.place(strip)) return; // grid not built yet — retry on next tick
        revealed = true;
        strip.removeAttribute('data-mb-pending');
    }
    function armBootReveal() {
        // If the grid still isn't there, retry every 500ms (light — place()
        // bails cheaply when menuGrid() finds nothing) up to a hard deadline
        // so a theme that never quite settles is no worse off than before.
        var tries = 0, maxTries = 20;
        (function tick() {
            if (revealed || tries++ >= maxTries) return;
            if (bootReveal()) return;
            window.setTimeout(tick, 500);
        })();
    }
    if (document.readyState === 'complete') {
        window.setTimeout(armBootReveal, 600);
    } else {
        window.addEventListener('load', function () { window.setTimeout(armBootReveal, 600); }, { once: true });
    }
    /* Safety net: if `load` never fires (a broken resource keeps it pending), do
       not the wait forever — run the same reveal loop after 8s unconditionally. */
    window.setTimeout(armBootReveal, 8000);

    strip.addEventListener('click', function (e) {
        var card = e.target.closest('.mbo-card');
        if (!card || card.hasAttribute('data-mbo-inert')) return;
        var target = themeCard(card.getAttribute('data-mbo-item'));
        if (!target) return;
        /* Some themes only bind the sheet to a child (the image or title), so a
           click on the wrapper does nothing. Click the innermost node that
           carries the theme's own open hook. */
        (target.querySelector('[data-open-item], [data-open]') || target).click();
    });
})();
</script>
@endif
