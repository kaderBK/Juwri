{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- soft_gradient thumbnail — pastel lavender-to-peach wash with two-line buttons
     (label + hint). The Lnk.bio / Linkfly staple. Bespoke inline-styled
     mini-mockup. Mirrors .d19. --}}
<div class="bio-thumb bio-thumb--soft_gradient" data-preset="soft_gradient" data-tier="pro" role="img" aria-label="Soft Gradient"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:linear-gradient(175deg,#c7b9ff 0%,#f6b8d0 60%,#ffd9c0 100%);padding:18px 14px 0;text-align:center;font-family:'Poppins','Manrope',system-ui,sans-serif;color:#3c2a55">
    <div style="width:34px;height:34px;margin:0 auto 8px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:17px;box-shadow:0 6px 16px rgba(120,90,180,.25)">🎂</div>
    <div style="font-size:11px;font-weight:700;margin-bottom:3px">Petal &amp; Crumb</div>
    <div style="font-size:6.5px;color:#5f4a7d;margin-bottom:13px;line-height:1.5">Custom cakes &amp; dessert tables ✨ Jeddah</div>
    @foreach ([['Cake Catalogue','Browse flavors & sizes'],['Check Availability','We book 2–3 weeks out'],['Wedding & Events','Tables from 900 SAR']] as [$t,$s])
        <div style="background:rgba(255,255,255,.92);border-radius:12px;padding:8px 10px;margin-bottom:9px;text-align:left;box-shadow:0 4px 12px rgba(120,90,180,.18)">
            <div style="font-size:8px;font-weight:600">{{ $t }}</div>
            <div style="font-size:6px;font-weight:500;color:#8a74a8;margin-top:1px">{{ $s }}</div>
        </div>
    @endforeach
</div>
