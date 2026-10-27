{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- wave_pastel thumbnail — "Poke Poke" Linkjar sectioned waves: pastel ocean palette split by
     wavy dividers into labeled sections ("Eat" / dark tip-jar card). Inline SVG waves (allowed).
     Bespoke inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d38 design. --}}
<div class="bio-thumb bio-thumb--wave_pastel" data-preset="wave_pastel" data-tier="pro" role="img" aria-label="Wave Pastel"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#dff0f7;padding:0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#1d3a47;text-align:center">
    <div style="padding:12px 13px 3px">
        <div style="width:32px;height:32px;border-radius:50%;margin:0 auto 5px;background:#fff;box-shadow:0 6px 14px rgba(29,58,71,.12)"></div>
        <div style="font-size:11px;font-weight:700;margin-bottom:2px">Poke Poke</div>
        <div style="font-size:7px;color:#5b7c8a">Hawaiian bowls, Gulf fish 🌊</div>
    </div>
    <svg viewBox="0 0 340 30" preserveAspectRatio="none" style="display:block;width:100%;height:14px"><path d="M0 18 Q28 6 56 16 T112 16 T168 16 T224 16 T280 16 T340 14 L340 30 L0 30 Z" fill="#fff"/></svg>
    <div style="background:#fff;padding:2px 13px 6px;text-align:left">
        <div style="font-size:6.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#3ba7cf;margin:4px 0 6px">Eat</div>
        @foreach (['Build a Bowl', 'Signature Bowls'] as $lbl)
            <div style="background:#eaf6fb;border-radius:11px;padding:7px 8px;font-size:8px;margin-bottom:6px">{{ $lbl }}</div>
        @endforeach
    </div>
    <svg viewBox="0 0 340 30" preserveAspectRatio="none" style="display:block;width:100%;height:14px;transform:scaleY(-1)"><path d="M0 18 Q28 6 56 16 T112 16 T168 16 T224 16 T280 16 T340 14 L340 30 L0 30 Z" fill="#fff"/></svg>
    <div style="padding:4px 13px 0;text-align:left">
        <div style="display:flex;align-items:center;gap:8px;background:#1d3a47;color:#dff0f7;border-radius:12px;padding:8px 10px">
            <span style="font-size:14px;color:#5ab6d6;line-height:1">&hearts;</span>
            <span><b style="font-size:8.5px;display:block">The Tip Jar</b><span style="font-size:6.5px;color:#8fc3d6">Team's Friday surf fund 🏄</span></span>
        </div>
    </div>
</div>
