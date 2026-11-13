{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- photo_hero thumbnail — "Forno 450°" Beacons-style: full-bleed food-photo header
     (gradient stands in for the photo) fading into dark, name overlapping the fade,
     frosted link cards + one solid CTA. Inline-styled mini-mockup. Mirrors .d21. --}}
<div class="bio-thumb bio-thumb--photo_hero" data-preset="photo_hero" data-tier="pro" role="img" aria-label="Photo Hero"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#101014;color:#fff;font-family:'Manrope',system-ui,sans-serif">
    <div style="height:45%;background:linear-gradient(180deg,rgba(16,16,20,0) 28%,#101014 96%),radial-gradient(120px 88px at 55% 42%,#e2603a,#7c2a12 60%,#1d0a05 100%)"></div>
    <div style="text-align:center;margin-top:-20px;padding:0 13px">
        <div style="font-weight:800;font-size:15px;line-height:1;letter-spacing:-.01em">Forno 450°</div>
        <div style="color:#a8a8b4;font-size:7px;line-height:1.35;margin:4px 6px 11px">Neapolitan pizza, 90-second bake · Khobar</div>
        <div style="background:#e2603a;color:#fff;font-weight:700;font-size:7.5px;border-radius:9px;padding:7px 0;margin-bottom:7px">Order Now — 25 min</div>
        @foreach (['Menu & Allergens', 'Book a Table'] as $lbl)
            <div style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);color:#fff;font-size:7px;border-radius:9px;padding:6px 0;margin-bottom:6px">{{ $lbl }}</div>
        @endforeach
    </div>
</div>
