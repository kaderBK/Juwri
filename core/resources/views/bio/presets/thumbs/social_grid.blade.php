{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- social_grid thumbnail — "Shawarma Lab" LinkMe-style: big avatar, then a 4×2 WALL
     of branded social icon tiles (IG gradient, TikTok black, WhatsApp green, Snap yellow…)
     and just two buttons. Inline-styled mini-mockup. Mirrors the .d24 artifact design. --}}
<div class="bio-thumb bio-thumb--social_grid" data-preset="social_grid" data-tier="pro" role="img" aria-label="Social Grid"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#0f1117;color:#fff;text-align:center;padding:14px 12px;font-family:'Manrope',system-ui,sans-serif">
    <div style="width:40px;height:40px;border-radius:50%;margin:0 auto 7px;background:linear-gradient(140deg,#2a2f3d,#171a23);border:2px solid #2e3342"></div>
    <div style="font-size:11px;font-weight:800;letter-spacing:.02em">SHAWARMA LAB</div>
    <div style="font-size:6px;color:#8f95a8;margin:2px 0 10px">One shawarma. Perfected.</div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px">
        @foreach ([
            'linear-gradient(140deg,#f58529,#dd2a7b 60%,#8134af)', '#111', '#25d366', '#fffc00',
            '#ff0050', '#1877f2', '#ff5c39', '#5865f2',
        ] as $bg)
            <div style="aspect-ratio:1;border-radius:8px;background:{{ $bg }};display:flex;align-items:center;justify-content:center">
                <span style="width:9px;height:9px;border-radius:2px;background:rgba(255,255,255,.92)"></span>
            </div>
        @endforeach
    </div>
    @foreach (['The Menu (it\'s one item)', 'Order It'] as $lbl)
        <div style="background:#1b1f2a;border:1px solid #2e3342;color:#fff;font-size:7px;border-radius:9px;padding:6px 0;margin-bottom:6px">{{ $lbl }}</div>
    @endforeach
</div>
