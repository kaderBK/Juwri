{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- honey_hive thumbnail — "The Hive" Linkbee warm & sweet: honeycomb band, butter-yellow
     accents, buttons with soft drop ledges + helper lines, one dark harvest card. Bespoke
     inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d32 artifact design. --}}
<div class="bio-thumb bio-thumb--honey_hive" data-preset="honey_hive" data-tier="pro" role="img" aria-label="Honey Hive"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#fff8e8;padding:0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#3d3116;text-align:center">
    <div style="height:22px;background:radial-gradient(circle at 50% 50%,transparent 4px,#f5c93c 4.5px,#f5c93c 5.5px,transparent 6px);background-size:17px 15px;opacity:.5"></div>
    <div style="padding:6px 13px 0">
        <div style="width:34px;height:34px;border-radius:50%;margin:0 auto 7px;background:#f5c93c;box-shadow:0 4px 0 rgba(61,49,22,.15)"></div>
        <div style="font-size:11px;font-weight:700;margin-bottom:2px">The Hive Breakfast</div>
        <div style="font-size:7px;color:#8a7440;margin-bottom:10px">Honey-everything breakfast 🐝</div>
        @foreach (['Breakfast Menu' => 'Served 6am – 1pm', 'Buy Our Honey' => 'Sidr · wildflower'] as $lbl => $sub)
            <div style="background:#fff;border:1.5px solid #ecd9a0;border-radius:11px;box-shadow:0 3px 0 #ecd9a0;text-align:left;padding:6px 9px;margin-bottom:9px;font-size:8.5px;font-weight:600">
                {{ $lbl }}<small style="display:block;font-size:6.5px;font-weight:500;color:#8a7440">{{ $sub }}</small>
            </div>
        @endforeach
        <div style="background:#3d3116;color:#ffe9a8;border-radius:12px;padding:8px 10px;text-align:left">
            <b style="font-size:8.5px;display:block">🌼 Spring harvest is in</b>
            <span style="font-size:6.5px;color:#c9b06a">First sidr jars of the season</span>
        </div>
    </div>
</div>
