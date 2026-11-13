{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- classic_flat thumbnail — the Linktree-default look: flat green ground, centered
     white avatar, handle-style name, white rounded pill buttons. The plainest of
     the set — kept clean. Bespoke inline-styled mini-mockup. Mirrors .d17. --}}
<div class="bio-thumb bio-thumb--classic_flat" data-preset="classic_flat" data-tier="basic" role="img" aria-label="Classic Flat"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#9cd6c8;padding:18px 14px 0;text-align:center;font-family:'Poppins','Manrope',system-ui,sans-serif;color:#123c32">
    <div style="width:34px;height:34px;margin:0 auto 8px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:17px;box-shadow:0 4px 12px rgba(20,60,50,.18)">☕</div>
    <div style="font-size:10px;font-weight:700;margin-bottom:3px">@@morningratio</div>
    <div style="font-size:6.5px;color:#2c5a4e;margin-bottom:13px;line-height:1.5">Specialty coffee roasted in Alkhobar</div>
    @foreach (['Our Menu','Order Delivery','Buy Our Beans','Visit the Roastery'] as $t)
        <div style="background:#fff;border-radius:999px;padding:9px 8px;margin-bottom:9px;font-size:8px;font-weight:600;box-shadow:0 2px 8px rgba(20,60,50,.12)">{{ $t }}</div>
    @endforeach
</div>
