{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- classic_dark thumbnail — outline buttons on near-black: the standard dark-mode
     template, ghost buttons with a verified tick and muted bio. Bespoke
     inline-styled mini-mockup. Mirrors .d18. --}}
<div class="bio-thumb bio-thumb--classic_dark" data-preset="classic_dark" data-tier="basic" role="img" aria-label="Classic Dark"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#161618;padding:18px 14px 0;text-align:center;font-family:'Poppins','Manrope',system-ui,sans-serif;color:#fff">
    <div style="width:34px;height:34px;margin:0 auto 8px;border-radius:50%;background:#242428;border:1px solid #333338;display:grid;place-items:center;font-size:16px">🔥</div>
    <div style="font-size:9px;font-weight:700;margin-bottom:3px">CINDER STEAKHOUSE <span style="display:inline-grid;place-items:center;width:11px;height:11px;border-radius:50%;background:#3aa3ff;color:#fff;font-size:6px;vertical-align:1px">✓</span></div>
    <div style="font-size:6.5px;color:#9c9ca4;margin-bottom:13px;line-height:1.5">Dry-aged, charcoal-fired · Riyadh</div>
    @foreach (['Dinner Menu','Book a Table','Private Cellar Room','Gift Cards'] as $t)
        <div style="border:1.5px solid #48484f;border-radius:9px;padding:9px 8px;margin-bottom:9px;font-size:8px;font-weight:600">{{ $t }}</div>
    @endforeach
</div>
