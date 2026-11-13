{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- glass_gradient thumbnail — "Cloud Nine" Linkfly glass stack: indigo→purple→pink gradient,
     frosted-glass pills over it and one solid white CTA. Bespoke inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d31 artifact design. --}}
<div class="bio-thumb bio-thumb--glass_gradient" data-preset="glass_gradient" data-tier="basic" role="img" aria-label="Glass Gradient"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:linear-gradient(165deg,#4338ca 0%,#7c3aed 45%,#db2777 100%);padding:18px 14px 0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;text-align:center">
    <div style="width:40px;height:40px;border-radius:50%;margin:0 auto 8px;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.55)"></div>
    <div style="color:#fff;font-size:12px;font-weight:700;margin-bottom:3px">Cloud Nine Tea</div>
    <div style="color:rgba(255,255,255,.85);font-size:7.5px;line-height:1.5;margin-bottom:12px">Bubble tea &amp; cloud jellies ☁️</div>
    <div style="background:#fff;color:#5b21b6;font-weight:700;border-radius:12px;padding:8px 6px;font-size:8px;margin-bottom:8px">Order the Friday Flavor</div>
    @foreach (['Full Menu', 'Loyalty Card', 'Find Our Kiosks'] as $lbl)
        <div style="background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:12px;padding:8px 6px;font-size:8px;margin-bottom:8px">{{ $lbl }}</div>
    @endforeach
</div>
