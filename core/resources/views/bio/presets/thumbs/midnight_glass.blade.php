{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- midnight_glass thumbnail — "NOIR Burger" late-night dark: frosted-glass link cards
     over neon fume gradients, live "open until" badge. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d1 artifact design. --}}
<div class="bio-thumb bio-thumb--midnight_glass" data-preset="midnight_glass" data-tier="basic" role="img" aria-label="Midnight Glass"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     position:relative;background:#0b0710;
     background-image:radial-gradient(110px 100px at 85% 2%,rgba(255,122,41,.34),transparent 65%),radial-gradient(120px 110px at 0% 28%,rgba(147,66,245,.3),transparent 65%),radial-gradient(100px 95px at 70% 80%,rgba(255,64,129,.16),transparent 60%),linear-gradient(180deg,#120a1c,#0b0710 55%);
     padding:15px 12px 0;font-family:'Manrope',system-ui,sans-serif;color:#f4effa;text-align:center">
    <div style="width:34px;height:34px;margin:0 auto 7px;border-radius:50%;padding:2px;box-sizing:border-box;background:conic-gradient(from 210deg,#ff7a29,#9342f5,#ff4081,#ff7a29)">
        <div style="width:100%;height:100%;border-radius:50%;background:#160d20;display:grid;place-items:center;font-family:'Unbounded',system-ui,sans-serif;font-weight:700;font-size:13px;color:#ffb26b">N</div>
    </div>
    <div style="font-family:'Unbounded',system-ui,sans-serif;font-weight:700;font-size:12px;letter-spacing:.02em;text-shadow:0 0 10px rgba(255,178,107,.45);margin-bottom:7px">NOIR BURGER</div>
    <span style="display:inline-flex;align-items:center;gap:4px;font-size:6px;font-weight:700;letter-spacing:.06em;color:#7dffab;background:rgba(125,255,171,.09);border:1px solid rgba(125,255,171,.28);border-radius:999px;padding:3px 8px;margin-bottom:11px">
        <span style="width:4px;height:4px;border-radius:50%;background:#7dffab;box-shadow:0 0 6px #7dffab"></span>OPEN · 2:00 AM</span>
    @foreach (['Full Menu','Order Delivery'] as $lbl)
        <div style="display:flex;align-items:center;gap:7px;text-align:left;margin-bottom:8px;padding:8px 9px;border-radius:11px;background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.13)">
            <span style="width:18px;height:18px;border-radius:6px;background:linear-gradient(135deg,rgba(255,122,41,.4),rgba(147,66,245,.4));border:1px solid rgba(255,255,255,.14)"></span>
            <span style="font-size:8px;font-weight:800">{{ $lbl }}</span>
            <span style="margin-left:auto;color:#ffb26b;font-size:11px">&rarr;</span>
        </div>
    @endforeach
    <div style="border:1px solid rgba(255,255,255,.14);border-radius:12px;overflow:hidden;text-align:left">
        <div style="height:42px;position:relative;background:radial-gradient(60px 40px at 30% 42%,#ff9d4d,#c2410c 60%,#4a1508 100%)">
            <span style="position:absolute;top:6px;right:6px;font-size:7px;font-weight:800;background:#0b0710;border:1px solid rgba(255,178,107,.5);color:#ffb26b;padding:2px 6px;border-radius:999px">38 SAR</span>
        </div>
        <div style="padding:6px 9px;background:rgba(20,10,30,.72)">
            <em style="font-style:normal;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.2em;color:#ffb26b;text-transform:uppercase">This week</em>
            <b style="display:block;font-size:8.5px;margin-top:2px">Truffle Noir Double</b>
        </div>
    </div>
</div>
