{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- retro_diner thumbnail — "Buzz Diner" loud & tactile retro: checkerboard bands,
     bold red/teal, chunky offset-shadow cards. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d3 artifact design. --}}
<div class="bio-thumb bio-thumb--retro_diner" data-preset="retro_diner" data-tier="pro" role="img" aria-label="Retro Diner"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f5e9d0;font-family:'Archivo',system-ui,sans-serif;color:#1e1a16">
    <div style="height:16px;background:conic-gradient(#d92b2b 90deg,#f5e9d0 90deg 180deg,#d92b2b 180deg 270deg,#f5e9d0 270deg);background-size:16px 16px"></div>
    <div style="padding:11px 12px 0;text-align:center">
        <div style="font-family:'Bungee',system-ui,sans-serif;font-size:20px;line-height:.95;color:#d92b2b;text-shadow:1.5px 1.5px 0 #1e1a16">BUZZ</div>
        <div style="font-family:'Bungee',system-ui,sans-serif;font-size:11px;color:#0f8a8a;text-shadow:1px 1px 0 #1e1a16;margin-bottom:6px">DINER</div>
        <span style="display:inline-block;background:#0f8a8a;color:#f5e9d0;font-family:'Bungee',system-ui,sans-serif;font-size:6.5px;padding:3px 9px;border:2px solid #1e1a16;border-radius:999px;box-shadow:2px 2px 0 #1e1a16;margin-bottom:12px">OPEN TIL MIDNIGHT</span>
        @foreach (['See the Menu'=>'#ffd23e','Order Pickup'=>'#ff8fab','Book the Booth'=>'#8fd6ff'] as $lbl=>$c)
            <div style="display:flex;align-items:center;gap:8px;background:#fffdf6;border:2px solid #1e1a16;border-radius:11px;box-shadow:3px 3px 0 #1e1a16;padding:8px 9px;margin-bottom:11px;text-align:left">
                <span style="width:18px;height:18px;flex:none;border:2px solid #1e1a16;border-radius:6px;background:{{ $c }}"></span>
                <b style="font-family:'Archivo',sans-serif;font-weight:900;font-size:9px;letter-spacing:.01em;text-transform:uppercase">{{ $lbl }}</b>
            </div>
        @endforeach
    </div>
    <div style="height:16px;background:conic-gradient(#d92b2b 90deg,#f5e9d0 90deg 180deg,#d92b2b 180deg 270deg,#f5e9d0 270deg);background-size:16px 16px"></div>
</div>
