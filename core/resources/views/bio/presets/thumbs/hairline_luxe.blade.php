{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- hairline_luxe thumbnail — "Silo" fine-dining restraint: thin gold hairlines, cream serif
     on near-black, double-ruled tasting panel, lots of space. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d8 artifact design. --}}
<div class="bio-thumb bio-thumb--hairline_luxe" data-preset="hairline_luxe" data-tier="pro" role="img" aria-label="Hairline Luxe"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     position:relative;background:#161311;color:#e9e2d6;font-family:'Cormorant Garamond',Georgia,serif;
     background-image:radial-gradient(150px 110px at 50% 12%,rgba(196,164,105,.1),transparent 70%);
     padding:22px 20px 0;text-align:center">
    <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.5em;color:#c4a469;text-transform:uppercase;margin-bottom:12px">Est. MMXXII</div>
    <div style="font-weight:300;font-size:30px;letter-spacing:.14em;line-height:1;margin-bottom:5px">SIL<span style="color:#c4a469">O</span></div>
    <div style="font-style:italic;font-size:9px;color:#a89c8a;margin-bottom:14px">Contemporary Najdi tasting counter</div>
    <div style="width:34px;height:1px;background:#c4a469;opacity:.7;margin:0 auto 14px"></div>
    @foreach (['The Tasting Menu'=>'Nine courses · seasonal','À la Carte'=>'Bar seating only','Private Events'=>'The cellar room'] as $b=>$s)
        <div style="padding:9px 0;border-bottom:1px solid rgba(196,164,105,.22){{ $loop->first ? ';border-top:1px solid rgba(196,164,105,.22)' : '' }}">
            <div style="font-size:12px;letter-spacing:.05em">{{ $b }}</div>
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.3em;text-transform:uppercase;color:#a89c8a;margin-top:3px">{{ $s }}</div>
        </div>
    @endforeach
    <div style="position:relative;margin-top:16px;padding:12px 12px;border:1px solid rgba(196,164,105,.35)">
        <div style="position:absolute;inset:4px;border:1px solid rgba(196,164,105,.16);pointer-events:none"></div>
        <div style="position:relative">
            <em style="font-style:normal;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.34em;text-transform:uppercase;color:#c4a469">This season</em>
            <b style="display:block;font-weight:400;font-size:14px;letter-spacing:.03em;margin:4px 0 3px">Smoke &amp; Date Palm</b>
            <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;color:#c4a469;letter-spacing:.14em">420 SAR · TUE—SAT</span>
        </div>
    </div>
</div>
