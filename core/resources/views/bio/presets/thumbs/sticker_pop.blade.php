{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- sticker_pop thumbnail — "Sunday Juice" vivid & bouncy: saturated sunrise gradient,
     hard sticker offset-shadows, playful rotated badges. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d5 artifact design. --}}
<div class="bio-thumb bio-thumb--sticker_pop" data-preset="sticker_pop" data-tier="pro" role="img" aria-label="Sticker Pop"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     position:relative;background:linear-gradient(170deg,#ffc24b 0%,#ff8e53 42%,#ff5f8f 100%);
     font-family:'Baloo 2',system-ui,sans-serif;color:#3b1f0e;padding:14px 12px 0;text-align:center">
    <span style="position:absolute;width:90px;height:90px;border-radius:50%;background:#ffe08a;opacity:.5;filter:blur(2px);top:-28px;left:-28px"></span>
    <span style="position:absolute;width:80px;height:80px;border-radius:50%;background:#ff9ec2;opacity:.5;filter:blur(2px);bottom:26px;right:-34px"></span>
    <div style="position:relative">
        <div style="width:38px;height:38px;margin:0 auto 8px;border-radius:14px;background:#fff8ec;display:grid;place-items:center;border:3px solid #3b1f0e;box-shadow:0 5px 0 rgba(59,31,14,.25);transform:rotate(-4deg)">
            <span style="width:18px;height:18px;border-radius:50%;background:radial-gradient(circle at 32% 30%,#ffd15a,#ff8a3c 70%);box-shadow:inset 0 0 0 2px rgba(59,31,14,.15)"></span>
        </div>
        <div style="font-weight:800;font-size:18px;line-height:1;color:#fff8ec;text-shadow:0 2px 0 #3b1f0e;margin-bottom:9px">Sunday Juice</div>
        <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-bottom:12px">
            @foreach (['Vegan'=>'rotate(-2deg)','Cold-pressed'=>'rotate(2deg)'] as $t=>$r)
                <span style="background:#fff8ec;border:2px solid #3b1f0e;border-radius:999px;font-size:6.5px;font-weight:800;padding:2px 7px;transform:{{ $r }}">{{ $t }}</span>
            @endforeach
        </div>
        @foreach (['Juice Menu'=>'14 blends + special','Cleanse Packs'=>'1, 3 & 5-day'] as $b=>$s)
            <div style="display:flex;align-items:center;gap:8px;background:#fff8ec;border:2.5px solid #3b1f0e;border-radius:16px;padding:8px 10px;margin-bottom:12px;text-align:left;box-shadow:0 5px 0 rgba(59,31,14,.28)">
                <span style="width:18px;height:18px;flex:none;border-radius:8px;border:2px solid #3b1f0e;background:#ffc24b"></span>
                <span><b style="display:block;font-size:10px;font-weight:800;line-height:1.1">{{ $b }}</b><span style="font-size:6.5px;font-weight:600;color:#8a5a33">{{ $s }}</span></span>
            </div>
        @endforeach
        <div style="background:#3b1f0e;color:#ffe9c9;border-radius:16px;padding:9px 11px;text-align:left">
            <em style="font-style:normal;font-size:6px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#ffc24b">Season drop</em>
            <b style="display:block;font-size:11px;line-height:1.1;margin-top:2px">Alphonso Mango Crush</b>
        </div>
    </div>
</div>
