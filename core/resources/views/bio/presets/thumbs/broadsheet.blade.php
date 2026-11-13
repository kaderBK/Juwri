{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- broadsheet thumbnail — "The Daily Crumb" newspaper: masthead, dateline, double-rule,
     headline links with section labels, serif on newsprint. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d11 artifact design. --}}
<div class="bio-thumb bio-thumb--broadsheet" data-preset="broadsheet" data-tier="pro" role="img" aria-label="Broadsheet"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f4f1e7;color:#191713;font-family:'Playfair Display',Georgia,serif;padding:12px 12px 0">
    <div style="display:flex;justify-content:space-between;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.12em;text-transform:uppercase;color:#6b675c;border-bottom:1px solid #191713;padding-bottom:4px">
        <span>Vol. IV — No. 212</span><span>Manama, Bahrain</span>
    </div>
    <div style="font-weight:900;font-size:22px;text-align:center;letter-spacing:.01em;line-height:1;margin:8px 0 4px">The Daily Crumb</div>
    <div style="font-style:italic;text-align:center;font-size:6.5px;color:#6b675c;margin-bottom:7px">“All the bread that's fit to bake”</div>
    <div style="border-top:3px double #191713;border-bottom:1px solid #191713;padding:3px 0;text-align:center;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.26em;text-transform:uppercase">Open today 07:00 — 16:00</div>
    @foreach (['Section A'=>'The Brunch Menu, "Worth the Queue"','Section B'=>'Order Collection: Skip the Queue','Classifieds'=>'Table Wanted: Party of Four'] as $sec=>$hd)
        <div style="padding:8px 1px;border-bottom:1px solid rgba(25,23,19,.25)">
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.22em;color:#8a3324;text-transform:uppercase">{{ $sec }}</div>
            <b style="display:block;font-weight:700;font-size:10px;line-height:1.15;margin-top:2px">{{ $hd }}</b>
        </div>
    @endforeach
    <div style="margin-top:10px;border:1px solid #191713;padding:9px 10px;background:#faf8f0">
        <em style="font-style:normal;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.24em;color:#8a3324;text-transform:uppercase">Today's dispatch</em>
        <b style="display:block;font-weight:900;font-size:12px;line-height:1.1;margin:3px 0 4px">Cardamom Knots Return</b>
        <p style="font-family:'Manrope',sans-serif;font-size:6px;line-height:1.5;color:#403d35;margin:0;column-count:2;column-gap:9px;column-rule:1px solid rgba(25,23,19,.2)">Removed in June "for one week", their absence stretched to eleven. Management, pressed by a petition of regulars, confirms the pastry resumes daily at seven sharp.</p>
    </div>
</div>
