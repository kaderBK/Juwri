{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bento_site thumbnail — "Golden Hour Café" Universe-style link-in-bio-as-mini-website:
     mixed-size white tiles, one gradient feature tile, one photo tile, corner arrows.
     Inline-styled mini-mockup. Mirrors the .d25 artifact design. --}}
<div class="bio-thumb bio-thumb--bento_site" data-preset="bento_site" data-tier="pro" role="img" aria-label="Bento Site"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f1f2f4;color:#1c1e24;text-align:left;padding:12px 10px;font-family:'Manrope',system-ui,sans-serif">
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:9px">
        <div style="width:24px;height:24px;border-radius:8px;background:#fff;flex:none;box-shadow:0 2px 6px rgba(20,25,40,.08)"></div>
        <div><div style="font-size:9px;font-weight:700">Golden Hour Café</div><div style="font-size:5.5px;color:#7c8090;margin-top:1px">Brunch · bakery</div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        <div style="grid-column:1/-1;background:linear-gradient(140deg,#3d6df2,#7a4df2);color:#fff;border-radius:11px;padding:9px 10px">
            <div style="font-size:9px;font-weight:700">Browse the full menu</div>
            <div style="font-size:5.5px;color:rgba(255,255,255,.8);margin-top:2px">Brunch till 4pm daily</div>
        </div>
        @foreach ([['Order delivery', '30–40 min'], ['Book a table', 'Free · 2 taps']] as [$t, $s])
            <div style="background:#fff;border-radius:11px;padding:8px 9px;box-shadow:0 2px 6px rgba(20,25,40,.07)">
                <div style="font-size:7.5px;font-weight:700">{{ $t }}</div>
                <div style="font-size:5.5px;color:#7c8090;margin-top:2px">{{ $s }}</div>
            </div>
        @endforeach
        <div style="grid-column:1/-1;border-radius:11px;min-height:34px;position:relative;overflow:hidden;background:radial-gradient(110px 66px at 50% 55%,#f0b25c,#a05a1c 75%,#3d2008)">
            <span style="position:absolute;left:7px;bottom:6px;background:rgba(255,255,255,.92);border-radius:99px;font-size:6px;font-weight:700;padding:3px 7px;color:#1c1e24">This week's bake</span>
        </div>
        @foreach ([['Open now', 'Closes 23:00'], ['4.8 rating', '2,190 reviews']] as [$t, $s])
            <div style="background:#fff;border-radius:11px;padding:8px 9px;box-shadow:0 2px 6px rgba(20,25,40,.07)">
                <div style="font-size:7.5px;font-weight:700">{{ $t }}</div>
                <div style="font-size:5.5px;color:#7c8090;margin-top:2px">{{ $s }}</div>
            </div>
        @endforeach
    </div>
</div>
