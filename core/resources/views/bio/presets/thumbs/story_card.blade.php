{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- story_card thumbnail — "Loulou's Kitchen" Milkshake-style: coral→pink gradient,
     a big conversational "talking" headline, one white obsession card with pink CTA,
     pagination dots + tiny outline links. Inline-styled mini-mockup. Mirrors .d23. --}}
<div class="bio-thumb bio-thumb--story_card" data-preset="story_card" data-tier="pro" role="img" aria-label="Story Card"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:linear-gradient(160deg,#ff7a59,#ff4f7e);color:#fff;text-align:left;padding:15px 13px;font-family:'Manrope',system-ui,sans-serif">
    <div style="font-size:5.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.85;margin-bottom:7px">Loulou's Kitchen · Bahrain</div>
    <div style="font-weight:800;font-size:16px;line-height:1.03;letter-spacing:-.01em;margin-bottom:10px">Okay but have you tried our <em style="font-style:italic;font-weight:600">saffron milk cake?</em></div>
    <div style="background:#fff;color:#33202a;border-radius:14px;padding:11px;box-shadow:0 10px 24px rgba(120,20,50,.3)">
        <div style="width:30px;height:22px;border-radius:6px;background:linear-gradient(140deg,#ffd7a8,#ff9ec2);margin-bottom:7px"></div>
        <div style="font-size:9px;font-weight:800;margin-bottom:3px">This week's obsession</div>
        <div style="font-size:6px;color:#7d5f6b;line-height:1.4;margin-bottom:9px">Saffron milk cake, torched cream. Gone by 6pm.</div>
        <span style="display:inline-block;background:#ff4f7e;color:#fff;font-weight:700;font-size:6.5px;border-radius:99px;padding:6px 12px">Reserve a slice →</span>
    </div>
    <div style="display:flex;gap:4px;justify-content:center;margin:11px 0 10px">
        <i style="width:13px;height:4px;border-radius:99px;background:#fff"></i>
        <i style="width:4px;height:4px;border-radius:99px;background:rgba(255,255,255,.45)"></i>
        <i style="width:4px;height:4px;border-radius:99px;background:rgba(255,255,255,.45)"></i>
    </div>
    <div style="display:flex;gap:6px">
        @foreach (['Menu', 'Order', 'Visit'] as $lbl)
            <span style="flex:1;text-align:center;border:1px solid rgba(255,255,255,.55);border-radius:9px;font-size:6.5px;font-weight:700;padding:6px 0">{{ $lbl }}</span>
        @endforeach
    </div>
</div>
