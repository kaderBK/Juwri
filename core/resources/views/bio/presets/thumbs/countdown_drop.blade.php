{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- countdown_drop thumbnail — "Qamar Rooftop" event drop: dark purple canvas, a live-timer
     card with countdown digit tiles (days/hrs/min) and one purple waitlist CTA. Bespoke
     inline-styled mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d36 artifact design. --}}
<div class="bio-thumb bio-thumb--countdown_drop" data-preset="countdown_drop" data-tier="pro" role="img" aria-label="Countdown Drop"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#12101c;padding:16px 13px 0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#f0edfa;text-align:center">
    <div style="width:30px;height:30px;border-radius:50%;margin:0 auto 7px;background:#1e1a30;border:1px solid #35304e"></div>
    <div style="font-size:11px;font-weight:700;margin-bottom:2px">Qamar Rooftop</div>
    <div style="font-size:7px;color:#a49cc0;margin-bottom:9px">Seasonal supper club</div>
    <div style="background:#1a1628;border:1px solid #35304e;border-radius:14px;padding:10px 8px;margin-bottom:9px">
        <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;letter-spacing:.18em;color:#9f7aff;text-transform:uppercase;margin-bottom:8px">Next seating drops in</div>
        <div style="display:flex;justify-content:center;gap:5px;margin-bottom:10px">
            @foreach (['04' => 'days', '11' => 'hrs', '26' => 'min'] as $n => $u)
                <div style="background:#12101c;border:1px solid #35304e;border-radius:8px;padding:6px 0;width:30px">
                    <div style="font-size:14px;font-weight:800;color:#cbb8ff;line-height:1">{{ $n }}</div>
                    <div style="font-size:5.5px;letter-spacing:.1em;color:#a49cc0;text-transform:uppercase;font-weight:600;margin-top:2px">{{ $u }}</div>
                </div>
            @endforeach
        </div>
        <div style="display:inline-block;background:#9f7aff;color:#12101c;font-weight:800;font-size:8px;border-radius:999px;padding:6px 14px">Join the Waitlist</div>
    </div>
    <div style="background:#1a1628;border:1px solid #35304e;color:#f0edfa;border-radius:11px;padding:7px 6px;font-size:8px">Last Dinner's Menu</div>
</div>
