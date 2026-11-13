{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- creator_store thumbnail — "Chef Nora" Stan-style storefront: avatar header then
     product ROWS (gradient thumb + promise title + price chip), one FREE lead magnet.
     Inline-styled mini-mockup. Mirrors the .d22 artifact design. --}}
<div class="bio-thumb bio-thumb--creator_store" data-preset="creator_store" data-tier="pro" role="img" aria-label="Creator Store"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#faf8f5;color:#221d16;text-align:left;padding:15px 12px;font-family:'Manrope',system-ui,sans-serif">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px">
        <div style="width:30px;height:30px;border-radius:50%;background:#e9e2d6;flex:none"></div>
        <div><div style="font-size:10px;font-weight:700;line-height:1.1">Nora Al-S.</div><div style="font-size:6px;color:#8a8378;margin-top:1px">Home-based creator · 212K</div></div>
    </div>
    @foreach ([
        ['Private Dinner', '5-course tasting', 'linear-gradient(140deg,#f6d8b8,#e8a76c)', '1,800', false],
        ['Weekly Prep Box', '10 boxes, macros', 'linear-gradient(140deg,#d8e8c8,#a4c68a)', '349', false],
        ['Fusion Masterclass', '2h live + book', 'linear-gradient(140deg,#f8d0c8,#e89a8c)', '120', false],
        ['15 Signature Recipes', 'Free PDF', 'linear-gradient(140deg,#d0dce8,#8aaac6)', 'FREE', true],
    ] as [$title, $sub, $grad, $price, $free])
        <div style="display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #eae4d9;border-radius:10px;padding:5px 6px;margin-bottom:6px;box-shadow:0 2px 5px rgba(80,70,50,.06)">
            <span style="width:22px;height:22px;border-radius:6px;flex:none;background:{{ $grad }}"></span>
            <span style="min-width:0;flex:1"><b style="display:block;font-size:7px;font-weight:700;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $title }}</b><span style="font-size:5.5px;color:#8a8378">{{ $sub }}</span></span>
            <span style="flex:none;font-weight:700;font-size:6px;background:{{ $free ? '#3f8f5f' : '#221d16' }};color:#faf8f5;border-radius:99px;padding:3px 6px">{{ $price }}</span>
        </div>
    @endforeach
</div>
