{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- cover_card thumbnail — "Verde" Linkr overlapping sheet: green cover band with a white
     sheet card sliding over it, ringed avatar breaking the edge, one green primary button.
     Bespoke inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d33 design. --}}
<div class="bio-thumb bio-thumb--cover_card" data-preset="cover_card" data-tier="pro" role="img" aria-label="Cover Card"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#eef0f4;padding:0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#22242c;text-align:center">
    <div style="height:66px;background:radial-gradient(70px 44px at 60% 60%,#7ec8a0,#2a7050 65%,#12301f)"></div>
    <div style="position:relative;margin:-16px 9px 0;background:#fff;border-radius:16px;padding:0 11px 11px;box-shadow:0 8px 20px rgba(30,35,50,.12)">
        <div style="width:40px;height:40px;border-radius:50%;margin:-20px auto 5px;background:#e8f3ec;border:3px solid #fff;box-shadow:0 4px 10px rgba(30,35,50,.15)"></div>
        <div style="font-size:11px;font-weight:700;margin-bottom:2px">Verde Salad Bar</div>
        <div style="font-size:7px;color:#7b8090;margin-bottom:9px">Build-your-own bowls</div>
        <div style="background:#2a7050;color:#fff;font-weight:700;border-radius:10px;padding:7px 6px;font-size:8px;margin-bottom:7px">Build Your Bowl</div>
        @foreach (["This Week's Featured Bowl", 'Macro Guide'] as $lbl)
            <div style="background:#f2f4f7;color:#22242c;border-radius:10px;padding:7px 6px;font-size:8px;margin-bottom:7px">{{ $lbl }}</div>
        @endforeach
    </div>
</div>
