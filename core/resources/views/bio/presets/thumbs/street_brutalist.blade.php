{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- street_brutalist thumbnail — "Asphalt" raw concrete & acid-green: mono ticker bar,
     stencil headline, slab links with hard offset shadows. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d9 artifact design. --}}
<div class="bio-thumb bio-thumb--street_brutalist" data-preset="street_brutalist" data-tier="pro" role="img" aria-label="Street Brutalist"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#d9d9d2;color:#141412;font-family:'Archivo',system-ui,sans-serif">
    <div style="background:#141412;color:#d8ff00;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.14em;padding:5px 8px;white-space:nowrap;overflow:hidden">NO APP · NO LOGIN · JUST SHAWARMA ··· OPEN TIL 3AM ···</div>
    <div style="padding:11px 12px 0">
        <div style="font-weight:900;font-size:26px;line-height:.86;letter-spacing:-.03em;text-transform:uppercase">ASPH<br><span style="display:inline-block;background:#d8ff00;padding:0 4px;box-shadow:3px 3px 0 #141412">ALT</span></div>
        <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.08em;margin:10px 0 12px;color:#3c3c36">SHAWARMA TRUCK · CHANGES CORNER WEEKLY</div>
        @foreach ([['Today\'s Corner ➜','EXIT 9 · UNTIL 3AM','01',true],['The Menu','6 WRAPS. NOTHING ELSE.','02',false],['Order & Skip Line','READY IN 8 MIN','03',false]] as $s)
            <div style="position:relative;background:{{ $s[3] ? '#d8ff00' : '#e8e8e2' }};border:2.5px solid #141412;box-shadow:4px 4px 0 #141412;padding:9px 10px;margin-bottom:11px">
                <span style="position:absolute;top:7px;right:9px;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:7px;color:#3c3c36">{{ $s[2] }}</span>
                <b style="display:block;font-weight:900;font-size:11px;text-transform:uppercase;letter-spacing:-.01em">{{ $s[0] }}</b>
                <span style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.1em;color:#3c3c36">{{ $s[1] }}</span>
            </div>
        @endforeach
        <div style="position:relative;background:#141412;color:#e8e8e2;border:2.5px solid #141412;padding:9px 10px">
            <span style="position:absolute;right:-6px;top:-9px;background:#d8ff00;color:#141412;font-weight:900;font-size:8px;padding:3px 7px;border:2.5px solid #141412;transform:rotate(4deg)">17 SAR</span>
            <em style="font-style:normal;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.24em;color:#d8ff00">THIS WEEK</em>
            <b style="display:block;font-weight:900;font-size:13px;text-transform:uppercase;margin-top:2px;line-height:1">Double Toum Crunch</b>
        </div>
    </div>
</div>
