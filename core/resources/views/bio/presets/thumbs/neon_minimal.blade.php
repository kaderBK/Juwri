{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- neon_minimal thumbnail — "Stacked" X-style dark minimal profile: near-black canvas,
     avatar + @handle + verified tick, mono stat line, a PINNED row and slug-hint links.
     Bespoke inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d30 design. --}}
<div class="bio-thumb bio-thumb--neon_minimal" data-preset="neon_minimal" data-tier="pro" role="img" aria-label="Neon Minimal"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#0a0c10;padding:16px 12px 0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#e6edf3;text-align:left">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:30px;height:30px;border-radius:50%;background:#12161d;border:1.5px solid #2a3038;flex:none"></div>
        <div>
            <div style="display:flex;align-items:center;gap:4px;font-size:10px;font-weight:700;line-height:1">STACKED
                <span style="display:inline-grid;place-items:center;width:9px;height:9px;border-radius:50%;background:#3aa3ff;color:#fff;font-size:6px">✓</span>
            </div>
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:7px;color:#7d8590;margin-top:2px">@@stacked.ksa</div>
        </div>
    </div>
    <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6.5px;color:#7d8590;display:flex;gap:8px;margin-bottom:11px">
        <span><b style="color:#3aa3ff;font-weight:500">4.9</b> rating</span><span><b style="color:#3aa3ff;font-weight:500">92k</b> orders</span>
    </div>
    <div style="display:flex;align-items:center;gap:6px;padding:7px 8px;border-radius:8px;background:#0d1520;border:1px solid #3aa3ff33;font-size:7.5px;margin-bottom:8px">
        Ramadan hours
        <span style="margin-left:auto;font-size:6px;font-weight:800;letter-spacing:.08em;color:#3aa3ff;border:1px solid #3aa3ff55;border-radius:4px;padding:1px 4px">PINNED</span>
    </div>
    @foreach (['Menu' => '/menu', 'Order' => '/order', 'Locations' => '/find'] as $lbl => $slug)
        <div style="display:flex;align-items:center;gap:6px;padding:7px 8px;border-radius:8px;background:#0f1319;border:1px solid #2a3038;color:#e6edf3;font-size:8px;margin-bottom:7px">
            {{ $lbl }}<span style="margin-left:auto;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6.5px;color:#7d8590">{{ $slug }}</span>
        </div>
    @endforeach
</div>
