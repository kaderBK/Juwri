{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- pastel_zen thumbnail — "Konomi Matcha" soft matcha calm: sage + cream, floating
     white cards with feather shadows, rounded Japanese-friendly type. Bespoke
     inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors .d12. --}}
<div class="bio-thumb bio-thumb--pastel_zen" data-preset="pastel_zen" data-tier="pro" role="img" aria-label="Pastel Zen"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f1f4ec;padding:16px 13px 0;text-align:center;font-family:'Zen Maru Gothic','Manrope',system-ui,sans-serif;color:#3d443a">
    <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.3em;text-transform:uppercase;color:#7d9b6a;margin-bottom:9px">Matcha · Mochi · Calm</div>
    <div style="width:36px;height:36px;margin:0 auto 8px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:18px;box-shadow:0 6px 15px rgba(125,155,106,.22)">🍵</div>
    <div style="font-weight:700;font-size:15px;letter-spacing:.04em;margin-bottom:2px">Konomi <span style="color:#7d9b6a">Matcha</span></div>
    <div style="font-size:7px;color:#8b9284;letter-spacing:.42em;margin-bottom:12px">こ の み</div>
    @foreach ([['🍵','Drinks Menu','#eef3e7'],['🍡','Mochi & Wagashi','#fbeef0'],['🫖','Tea Ceremony','#e9f0f2']] as [$ic,$lbl,$bg])
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border-radius:11px;padding:8px 9px;margin-bottom:8px;text-align:left;box-shadow:0 3px 11px rgba(61,68,58,.07)">
            <span style="width:20px;height:20px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:10px;background:{{ $bg }}">{{ $ic }}</span>
            <span style="font-size:8px;font-weight:700">{{ $lbl }}</span>
            <span style="margin-left:auto;width:5px;height:5px;border-radius:50%;background:#7d9b6a;opacity:.5"></span>
        </div>
    @endforeach
</div>
