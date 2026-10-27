{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- swiss_grid thumbnail — "Ratio Coffee" typographic Swiss minimal: one giant display
     size, hairline rules, tabular stat strip, black on white. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d4 artifact design. --}}
<div class="bio-thumb bio-thumb--swiss_grid" data-preset="swiss_grid" data-tier="pro" role="img" aria-label="Swiss Grid"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#fbfbf9;font-family:'Archivo',system-ui,sans-serif;color:#111;padding:14px 0 0">
    <div style="padding:0 13px">
        <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.24em;text-transform:uppercase;color:#8a8a84;margin-bottom:8px">Specialty coffee · Alkhobar</div>
        <div style="font-weight:900;font-size:40px;line-height:.86;letter-spacing:-.045em">RA&mdash;<br>TIO<span style="color:#d94f00">.</span></div>
        <div style="display:flex;border-top:1px solid #111;border-bottom:1px solid #111;margin-top:13px">
            @foreach (['07:00'=>'Doors','4.9'=>'Rating','12'=>'Origins'] as $n=>$l)
                <div style="flex:1;padding:6px 3px 7px;border-right:1px solid #e2e2dc{{ $loop->last ? ';border-right:0' : '' }}">
                    <b style="display:block;font-size:11px;font-weight:800;letter-spacing:-.02em">{{ $n }}</b>
                    <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.14em;text-transform:uppercase;color:#8a8a84">{{ $l }}</span>
                </div>
            @endforeach
        </div>
    </div>
    <div style="margin-top:14px">
        @foreach (['Brew Menu'=>'PDF·2p','Order Ahead'=>'Pickup','Beans & Gear'=>'Ship KSA'] as $b=>$m)
            <div style="display:flex;align-items:center;gap:10px;padding:9px 13px;border-top:1px solid #e2e2dc{{ $loop->last ? ';border-bottom:1px solid #e2e2dc' : '' }}">
                <b style="font-weight:800;font-size:10px;letter-spacing:-.015em">{{ $b }}</b>
                <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;color:#8a8a84;margin-left:auto">{{ $m }}</span>
                <span style="font-size:11px;color:#d94f00">&rarr;</span>
            </div>
        @endforeach
    </div>
    <div style="margin:13px 13px 0;border:1px solid #111;padding:8px 10px;display:flex;align-items:center;gap:8px">
        <span style="width:6px;height:6px;border-radius:50%;background:#d94f00;flex:none"></span>
        <b style="font-size:7px;font-weight:800">Now pouring — Yirgacheffe</b>
        <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;color:#8a8a84;margin-left:auto">22:00</span>
    </div>
</div>
