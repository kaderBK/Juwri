{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- menu_card thumbnail — "Maison Olive" printed-menu editorial: cream + Fraunces,
     double-ruled frame, dotted menu leaders. Bespoke inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d2 artifact design. --}}
<div class="bio-thumb bio-thumb--menu_card" data-preset="menu_card" data-tier="basic" role="img" aria-label="Menu Card"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f6f2e6;background-image:radial-gradient(rgba(43,55,31,.06) 0.5px,transparent 1px);background-size:7px 7px;
     padding:14px 12px;font-family:'Manrope',system-ui,sans-serif;color:#2b371f">
    <div style="border:1px solid #2b371f;box-shadow:0 0 0 3px #f6f2e6,0 0 0 4px #2b371f;background:#faf7ee;padding:14px 11px;height:100%;box-sizing:border-box">
        <div style="font-family:'Fraunces',Georgia,serif;font-size:16px;line-height:1;text-align:center;margin-bottom:3px">✦</div>
        <div style="font-family:'Fraunces',Georgia,serif;font-weight:600;font-size:15px;text-align:center;letter-spacing:.01em;margin-bottom:8px">Maison</div>
        <div style="display:flex;align-items:center;gap:5px;margin-bottom:10px">
            <span style="flex:1;height:1px;background:rgba(43,55,31,.35)"></span>
            <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.28em;text-transform:uppercase">Menu</span>
            <span style="flex:1;height:1px;background:rgba(43,55,31,.35)"></span>
        </div>
        @foreach (['Olive Plate' => '32', 'Sea Bream' => '58', 'Fig Tart' => '24'] as $dish => $price)
            <div style="display:flex;align-items:baseline;gap:5px;padding:5px 0">
                <b style="font-family:'Fraunces',Georgia,serif;font-weight:560;font-size:9px;white-space:nowrap">{{ $dish }}</b>
                <i style="flex:1;border-bottom:1.5px dotted rgba(43,55,31,.45);transform:translateY(-2px)"></i>
                <em style="font-style:normal;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:7px;letter-spacing:.08em">{{ $price }}</em>
            </div>
        @endforeach
    </div>
</div>
