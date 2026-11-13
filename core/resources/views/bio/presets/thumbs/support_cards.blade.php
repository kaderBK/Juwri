{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- support_cards thumbnail — "Midnight Noodle Club" Throne-style gifting grid: dark
     violet page, 2×2 cards each with a colored image band, gift title + price pill,
     and a live gifted-count note. Inline-styled mini-mockup. Mirrors the .d26 design. --}}
<div class="bio-thumb bio-thumb--support_cards" data-preset="support_cards" data-tier="pro" role="img" aria-label="Support Cards"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#171320;color:#f2eefa;text-align:center;padding:14px 12px;font-family:'Manrope',system-ui,sans-serif">
    <div style="width:34px;height:34px;border-radius:50%;margin:0 auto 6px;background:#241d33;border:1px solid #383050"></div>
    <div style="font-size:9.5px;font-weight:700">Midnight Noodle Club</div>
    <div style="font-size:6px;color:#a89cc4;margin:2px 0 10px">Love us? Feed us back 💜</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;text-align:left">
        @foreach ([
            ['linear-gradient(140deg,#3d2a58,#6a3d8f)', 'Gift a bowl to a stranger', '28 SAR', false],
            ['linear-gradient(140deg,#58452a,#8f6a3d)', 'Coffee the crew', '15 SAR', false],
            ['linear-gradient(140deg,#2a4458,#3d6a8f)', 'Gift card (any amount)', 'Choose', true],
            ['linear-gradient(140deg,#582a3a,#8f3d5c)', 'Sponsor lantern night', 'From 90', true],
        ] as [$band, $title, $price, $alt])
            <div style="background:#221b31;border:1px solid #383050;border-radius:11px;overflow:hidden">
                <div style="height:26px;background:{{ $band }}"></div>
                <div style="padding:6px 7px">
                    <div style="font-size:6.5px;font-weight:700;line-height:1.25">{{ $title }}</div>
                    <span style="display:inline-block;margin-top:5px;font-size:5.5px;font-weight:700;border-radius:99px;padding:2px 7px;{{ $alt ? 'background:transparent;border:1px solid #8d6ae8;color:#c4b2f4' : 'background:#8d6ae8;color:#fff' }}">{{ $price }}</span>
                </div>
            </div>
        @endforeach
    </div>
    <div style="font-size:5.5px;color:#a89cc4;margin-top:9px">142 bowls gifted this month 🙏</div>
</div>
