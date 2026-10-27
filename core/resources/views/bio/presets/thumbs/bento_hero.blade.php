{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bento_hero thumbnail — "Terra Kitchen" photo-first bento: full-bleed hero tile fading
     into a grid of smaller tiles, Fraunces headline. Inline-styled mini-mockup
     (STYLE_ATTR policy-allowed). Mirrors the .d7 artifact design. --}}
<div class="bio-thumb bio-thumb--bento_hero" data-preset="bento_hero" data-tier="pro" role="img" aria-label="Bento Hero"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#191713;font-family:'Manrope',system-ui,sans-serif;color:#efe9df">
    <div style="position:relative;height:82px;background:radial-gradient(90px 60px at 66% 40%,#9db26a,#5c6e3a 55%,#232519 95%),linear-gradient(180deg,#2a2d20,#191713)">
        <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(25,23,19,.1) 30%,rgba(25,23,19,.96) 96%)"></div>
        <div style="position:absolute;left:11px;right:11px;bottom:8px">
            <div style="font-family:'Fraunces',Georgia,serif;font-weight:560;font-size:15px;line-height:1;letter-spacing:-.01em">Terra <span style="font-style:italic;color:#c8d69a">Kitchen</span></div>
            <div style="font-size:6.5px;color:#b5ac9c;margin-top:2px">Farm-to-table · wood-fired · AlUla</div>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:8px">
        <a style="grid-column:1/-1;border-radius:10px;padding:9px;background:linear-gradient(150deg,#2c3220,#221f1a 70%);border:1px solid #322e26;text-decoration:none;color:#efe9df;display:block">
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.2em;text-transform:uppercase;color:#9db26a;margin-bottom:3px">The Menu</div>
            <b style="font-size:9.5px;font-weight:800">Autumn harvest — 24 items</b>
        </a>
        <div style="border-radius:10px;padding:9px;background:#221f1a;border:1px solid #322e26">
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.2em;text-transform:uppercase;color:#9db26a;margin-bottom:3px">Today</div>
            <b style="font-size:13px;font-weight:800">08–23</b>
            <div style="display:inline-flex;align-items:center;gap:4px;font-size:6px;font-weight:800;color:#9fe3af;margin-top:3px"><span style="width:4px;height:4px;border-radius:50%;background:#9fe3af"></span>Open now</div>
        </div>
        <div style="border-radius:10px;padding:9px;background:#221f1a;border:1px solid #322e26">
            <div style="font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5.5px;letter-spacing:.2em;text-transform:uppercase;color:#9db26a;margin-bottom:3px">Guests say</div>
            <div style="color:#e3b341;font-size:9px;letter-spacing:1px">★★★★★</div>
            <b style="font-size:13px;font-weight:800">4.8</b>
        </div>
    </div>
    <div style="display:flex;gap:6px;padding:0 8px">
        <span style="flex:1;text-align:center;background:#c8d69a;color:#191713;font-weight:800;font-size:8px;border-radius:9px;padding:7px 4px">Book a Table</span>
        <span style="flex:1;text-align:center;color:#efe9df;border:1px solid #4a463c;font-weight:800;font-size:8px;border-radius:9px;padding:7px 4px">Pickup</span>
    </div>
</div>
