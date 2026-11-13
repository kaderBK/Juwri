{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- crumb — brand ramp + appearance bootstrap (shared by index / track / account)

     CRUMB-F6. The shared layout derives `--theme-color-*` from
     sscanf($store->color,"#%02x%02x%02x"), which does not understand the 3-digit hex
     form: '#111' produces [17,1,null] and the layout emits the INVALID declaration
     `--theme-color-1: rgba(17,1,,1)`. An invalid custom property is invalid at
     computed-value time, and `var(--x, fallback)` does NOT rescue it (the fallback
     only fires when the variable is *undefined*). That killed all 82 crumb rules
     built on --brown*, leaving the primary CTA transparent with white text on the
     cream app — an invisible Checkout button. So crumb computes and emits its own
     ramp as literal colours and never depends on the shared variables.

     CRUMB-F7. The appearance preference is applied before first paint so a dark-mode
     customer never sees a cream flash. --}}
@php
    $crumbBrand = (function () use ($store) {
        foreach ([theme_setting($store->id, 'colors.primary', ''), $store->color ?? ''] as $raw) {
            $hex = ltrim(trim((string) $raw), '#');
            if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
                return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            }
        }
        return [0x8B, 0x5A, 0x2B];
    })();
    $__brRgb = implode(',', $crumbBrand);
    $__shade = fn (float $f) => implode(',', array_map(fn ($c) => max(0, min(255, (int) round($c * $f))), $crumbBrand));

    /* A brand colour can be too dark to read against crumb's DARK surfaces — the lane
       store's own '#111' produced a near-black CTA on a near-black page. Lift it toward
       white until it clears the dark background, and darken a very light brand for the
       same reason on the light theme's beige chips. Relative luminance, sRGB. */
    $__lin = function ($c) { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
    $__lum = 0.2126 * $__lin($crumbBrand[0]) + 0.7152 * $__lin($crumbBrand[1]) + 0.0722 * $__lin($crumbBrand[2]);
    $__mix = fn (float $t) => implode(',', array_map(fn ($c) => (int) round($c + (255 - $c) * $t), $crumbBrand));
    $__liftT   = $__lum < 0.05 ? 0.42 : ($__lum < 0.16 ? 0.26 : 0);
    $__brLift  = $__liftT > 0 ? $__mix($__liftT) : $__brRgb;
    $__brLift2 = $__liftT > 0 ? $__mix(min(1, $__liftT + 0.14)) : $__shade(1.14);
@endphp
<style nonce="{{ csp_nonce() }}">
#app{
  --brown:rgb({{ $__brRgb }});
  --brown-deep:rgb({{ $__shade(0.78) }});
  --brown-soft:rgba({{ $__brRgb }},.1);
  --brown-grad:linear-gradient(135deg,rgb({{ $__shade(1.14) }}) 0%,rgb({{ $__brRgb }}) 55%,rgb({{ $__shade(0.78) }}) 100%);
}
/* dark surfaces get the lifted ramp so the primary CTA never sinks into the page */
:root[data-crumb-theme="dark"] #app{
  --brown:rgb({{ $__brLift }});
  --brown-deep:rgb({{ $__brRgb }});
  --brown-soft:rgba({{ $__brLift }},.16);
  --brown-grad:linear-gradient(135deg,rgb({{ $__brLift2 }}) 0%,rgb({{ $__brLift }}) 55%,rgb({{ $__brRgb }}) 100%);
}
</style>
<script nonce="{{ csp_nonce() }}">
(function(){
  try{
    var k='crumb_{{ $store->id }}_appearance', v=localStorage.getItem(k);
    v = v ? JSON.parse(v) : 'auto';
    var dark = v==='dark' || (v!=='light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-crumb-theme', dark ? 'dark' : 'light');
  }catch(e){ document.documentElement.setAttribute('data-crumb-theme','light'); }
})();
</script>
