{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- vip_gold thumbnail — "Maison Royale" Links-VIP style: gilded gold-gradient primary
     pill with satin highlight on near-black, gold-outline ghost pills, Fraunces serif name,
     mono members badge. Inline-styled mini-mockup. Mirrors the .d29 artifact design. --}}
<div class="bio-thumb bio-thumb--vip_gold" data-preset="vip_gold" data-tier="pro" role="img" aria-label="VIP Gold"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:linear-gradient(180deg,#0c0c0e,#1a1610);color:#f4ecd8;text-align:center;padding:16px 13px;font-family:'Manrope',system-ui,sans-serif">
    <div style="width:34px;height:34px;border-radius:50%;margin:0 auto 8px;background:radial-gradient(circle at 35% 30%,#3a342a,#191611);border:1.5px solid #caa353;box-shadow:0 0 0 3px rgba(202,163,83,.12),0 6px 18px rgba(0,0,0,.5)"></div>
    <div style="font-family:'Fraunces',Georgia,serif;font-weight:560;font-size:13px;letter-spacing:.04em">MAISON ROYALE</div>
    <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.26em;color:#caa353;border:1px solid rgba(202,163,83,.5);border-radius:99px;padding:3px 8px;display:inline-block;margin:7px 0 8px">MEMBERS &amp; RESERVATIONS</div>
    <div style="font-size:6px;color:#a89c7e;line-height:1.4;margin-bottom:11px">Rooftop lounge · Doha West Bay</div>
    <div style="color:#14110b;font-weight:700;font-size:7.5px;border-radius:99px;padding:7px 0;margin-bottom:7px;background:linear-gradient(120deg,#a8813c,#e3c477 45%,#caa353 70%,#8f6b2c);box-shadow:0 3px 12px rgba(202,163,83,.25),inset 0 1px 0 rgba(255,240,200,.5)">Reserve a Table</div>
    @foreach (['The Menu', 'Shisha Terrace', 'Concierge WhatsApp'] as $lbl)
        <div style="background:transparent;color:#e3c477;border:1px solid rgba(202,163,83,.55);font-weight:600;font-size:7px;border-radius:99px;padding:6px 0;margin-bottom:6px">{{ $lbl }}</div>
    @endforeach
</div>
