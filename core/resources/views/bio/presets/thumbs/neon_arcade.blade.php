{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- neon_arcade thumbnail — "Volt" electric night brand: black + cyan/magenta glow.
     Bespoke inline-styled mini-mockup (STYLE_ATTR policy-allowed; no external CSS,
     works in every picker surface). Mirrors the .d10 artifact design. --}}
<div class="bio-thumb bio-thumb--neon_arcade" data-preset="neon_arcade" data-tier="pro" role="img" aria-label="Neon Arcade"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     position:relative;background:#07070d;
     background-image:radial-gradient(90px 70px at 22% 4%,rgba(33,230,240,.22),transparent 65%),radial-gradient(90px 80px at 90% 26%,rgba(255,45,149,.20),transparent 65%);
     padding:16px 12px 0;font-family:'Orbitron','Manrope',system-ui,sans-serif">
    <span style="display:inline-block;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.28em;color:#21e6f0;
        border:1px solid rgba(33,230,240,.5);padding:2px 6px;border-radius:3px;margin-bottom:10px;box-shadow:0 0 8px rgba(33,230,240,.3)">OPEN</span>
    <div style="font-weight:800;font-size:24px;line-height:1;letter-spacing:.05em;color:#fff;text-shadow:0 0 10px rgba(33,230,240,.85),0 0 22px rgba(33,230,240,.4);margin-bottom:14px">
        VO<span style="color:#ff2d95;text-shadow:0 0 10px rgba(255,45,149,.85),0 0 22px rgba(255,45,149,.4)">LT</span>
    </div>
    @foreach (['ORDER NOW','FIND US'] as $lbl)
        <div style="display:flex;align-items:center;gap:7px;text-align:left;margin-bottom:9px;padding:8px 9px;border-radius:7px;
            background:rgba(15,22,30,.7);border:1px solid rgba(33,230,240,.22);color:#cfeef2">
            <span style="width:2px;align-self:stretch;background:#21e6f0;box-shadow:0 0 6px #21e6f0;border-radius:2px"></span>
            <span style="font-size:7px;letter-spacing:.08em;text-transform:uppercase">{{ $lbl }}</span>
        </div>
    @endforeach
</div>
