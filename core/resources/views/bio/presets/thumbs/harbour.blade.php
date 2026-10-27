{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- harbour thumbnail — "Marsa & Sons" dockside nautical: deep navy + brass, drawn
     wave divider, coordinates under the name, ticket-stub catch-of-the-day with
     punched notches. Bespoke inline-styled mini-mockup. Mirrors .d13. --}}
<div class="bio-thumb bio-thumb--harbour" data-preset="harbour" data-tier="pro" role="img" aria-label="Harbour"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:linear-gradient(180deg,#0a1c2c,#0d2438);padding:15px 12px 0;text-align:center;font-family:'Manrope',system-ui,sans-serif;color:#eef4f6">
    <div style="font-size:15px;margin-bottom:4px">⚓</div>
    <div style="font-family:'Fraunces',Georgia,serif;font-weight:640;font-size:15px;letter-spacing:.02em">MARSA <span style="color:#c98f43">&amp;</span> SONS</div>
    <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.18em;color:#5f7a8c;margin-top:5px">21°32′N 39°10′E · JEDDAH</div>
    <svg viewBox="0 0 120 10" preserveAspectRatio="none" style="display:block;width:100%;height:8px;margin:8px 0 10px" aria-hidden="true">
        <path d="M0 6 Q10 1 20 6 T40 6 T60 6 T80 6 T100 6 T120 6 L120 10 L0 10 Z" fill="#12314a"/>
        <path d="M0 6 Q10 1 20 6 T40 6 T60 6 T80 6 T100 6 T120 6" fill="none" stroke="#c98f43" stroke-width="1" opacity=".6"/>
    </svg>
    @foreach ([['🐟','The Board','MENU'],['🔥','Grill Seats','BOOK']] as [$ic,$t,$k])
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:8px 9px;border-radius:8px;background:#12314a;border:1px solid #1e425e;text-align:left">
            <span style="width:18px;height:18px;flex:none;border-radius:50%;border:1px dashed #3c5c74;display:grid;place-items:center;font-size:9px">{{ $ic }}</span>
            <span style="font-size:8px;font-weight:800">{{ $t }}</span>
            <span style="margin-left:auto;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;color:#c98f43;letter-spacing:.06em">{{ $k }} →</span>
        </div>
    @endforeach
    <div style="position:relative;margin-top:6px;border-radius:8px;background:#eef4f6;color:#0d2438;padding:9px 11px;text-align:left">
        <span style="position:absolute;left:-5px;top:50%;transform:translateY(-50%);width:10px;height:10px;border-radius:50%;background:#0d2438"></span>
        <span style="position:absolute;right:-5px;top:50%;transform:translateY(-50%);width:10px;height:10px;border-radius:50%;background:#0d2438"></span>
        <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.22em;text-transform:uppercase;color:#b3691e">Off the boat</div>
        <div style="font-family:'Fraunces',Georgia,serif;font-size:11px;font-weight:640;margin-top:2px">Red Sea Najil</div>
    </div>
</div>
