{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Bio sprite — icons + food illustrations. Ported verbatim from rev/biolink-pages/demos/sprite.html (production assets). The trailing style block was intentionally dropped; icon-system CSS lives in bio/css/base.css so the page carries only ONE nonced style element. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
<defs>
<linearGradient id="lg-bun" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f7c774"/><stop offset="1" stop-color="#d98f35"/></linearGradient>
<linearGradient id="lg-patty" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a4b23"/><stop offset="1" stop-color="#5d2f13"/></linearGradient>
<linearGradient id="lg-drink" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb35c"/><stop offset="1" stop-color="#ff5f3c"/></linearGradient>
<linearGradient id="lg-crust" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f2b25c"/><stop offset="1" stop-color="#d98a2b"/></linearGradient>
<linearGradient id="lg-cheese" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffd970"/><stop offset="1" stop-color="#f2a93b"/></linearGradient>
<linearGradient id="lg-cream" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff7e8"/><stop offset="1" stop-color="#f2ddb8"/></linearGradient>
<linearGradient id="lg-choc" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7a4a26"/><stop offset="1" stop-color="#4a2a12"/></linearGradient>
<linearGradient id="lg-terra" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#d98a5c"/><stop offset="1" stop-color="#a34f28"/></linearGradient>
<linearGradient id="lg-chrome" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fdfdff"/><stop offset=".4" stop-color="#aeb4d0"/><stop offset=".6" stop-color="#e8eaf5"/><stop offset="1" stop-color="#7d84a8"/></linearGradient>
<linearGradient id="lg-fish" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#8fb8c9"/><stop offset="1" stop-color="#5c8ba1"/></linearGradient>
<linearGradient id="lg-green" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#9dc36a"/><stop offset="1" stop-color="#5f8f3d"/></linearGradient>
<radialGradient id="rg-plate" cx=".5" cy=".45" r=".6"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#d8d4cc"/></radialGradient>
<radialGradient id="rg-coffee" cx=".5" cy=".5" r=".55"><stop offset="0" stop-color="#a4673a"/><stop offset="1" stop-color="#5e3317"/></radialGradient>

<!-- ============ STROKE ICON SET (24x24, currentColor) ============ -->
<symbol id="i-menu" viewBox="0 0 24 24"><path d="M2 4h7a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H2Z"/><path d="M22 4h-7a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H22Z"/></symbol>
<symbol id="i-utensils" viewBox="0 0 24 24"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/></symbol>
<symbol id="i-bag" viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></symbol>
<symbol id="i-scooter" viewBox="0 0 24 24"><circle cx="5.3" cy="17.7" r="2.4"/><circle cx="18.7" cy="17.7" r="2.4"/><path d="M7.7 17.7h6.1l2.8-8.2h-3.1"/><path d="M12.5 5.5h3.2l1 4"/><path d="M16.3 17.7l.9-3.5"/></symbol>
<symbol id="i-chat" viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.6 0-3.2-.4-4.5-1.2L3 20l1.2-4.9A8.5 8.5 0 1 1 21 11.5Z"/></symbol>
<symbol id="i-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></symbol>
<symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
<symbol id="i-star" viewBox="0 0 24 24"><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21 7 14.2 2 9.3l6.9-1Z"/></symbol>
<symbol id="i-gift" viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></symbol>
<symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
<symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3A19.5 19.5 0 0 1 5.1 13 19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/></symbol>
<symbol id="i-coffee" viewBox="0 0 24 24"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6.5 2c0 1-.5 1-.5 2M10.5 2c0 1-.5 1-.5 2M14.5 2c0 1-.5 1-.5 2"/></symbol>
<symbol id="i-flame" viewBox="0 0 24 24"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.1-2.1-.2-4 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.3 1-3a2.5 2.5 0 0 0 2.5 2.5Z"/></symbol>
<symbol id="i-truck" viewBox="0 0 24 24"><path d="M14 17H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2Z"/><path d="M14 8h4l3 3.5V15a2 2 0 0 1-2 2h-1"/><circle cx="7.5" cy="18.5" r="2"/><circle cx="16.5" cy="18.5" r="2"/></symbol>
<symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/></symbol>
<symbol id="i-mail" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
<symbol id="i-megaphone" viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></symbol>
<symbol id="i-play" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4Z"/></symbol>
<symbol id="i-sparkles" viewBox="0 0 24 24"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z"/><path d="M19 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8Z"/></symbol>
<symbol id="i-leaf" viewBox="0 0 24 24"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10Z"/><path d="M2 21c0-3 1.9-5.4 5.1-6C9.5 14.5 12 13 13 12"/></symbol>
<symbol id="i-chef" viewBox="0 0 24 24"><path d="M6 13.9A4 4 0 0 1 7.4 6a5.1 5.1 0 0 1 1-1.5 5 5 0 0 1 7.1 0 5.1 5.1 0 0 1 1 1.5 4 4 0 0 1 1.4 7.9V21H6Z"/><path d="M6 17h12"/></symbol>
<symbol id="i-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3ZM21 14v2M17 21h4M14 20v1"/></symbol>
<symbol id="i-ticket" viewBox="0 0 24 24"><path d="M21 9V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v3a2.5 2.5 0 0 1 0 6v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3a2.5 2.5 0 0 1 0-6Z"/><path d="M13 5v2M13 11v2M13 17v2"/></symbol>
<symbol id="i-camera" viewBox="0 0 24 24"><path d="M4 7h3l2-3h6l2 3h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><circle cx="12" cy="13" r="4"/></symbol>
<symbol id="i-heart" viewBox="0 0 24 24"><path d="M19.5 5a5.2 5.2 0 0 0-7.5.2A5.2 5.2 0 0 0 4.5 5c-2 2-2 5.3 0 7.3L12 20l7.5-7.7c2-2 2-5.3 0-7.3Z"/></symbol>
<symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8v8a2 2 0 0 1-1 1.7l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.7l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></symbol>
<symbol id="i-tag" viewBox="0 0 24 24"><path d="M2 12V4a2 2 0 0 1 2-2h8l10 10-10 10L2 12Z"/><circle cx="7.5" cy="7.5" r="1.5"/></symbol>
<symbol id="i-cup" viewBox="0 0 24 24"><path d="M6 8h12l-1.5 12.4a2 2 0 0 1-2 1.6h-5a2 2 0 0 1-2-1.6L6 8Z"/><path d="M5 8h14"/><path d="m13 8 3.5-6"/></symbol>
<symbol id="i-teapot" viewBox="0 0 24 24"><path d="M6 11h12v1.5a6 6 0 0 1-12 0Z"/><path d="M18 11.5c1.9 0 3.1-1.3 3.1-3.1"/><path d="M6 12.5c-2.1 0-3.4 1-3.4 2.7"/><path d="M9 11a3 3 0 0 1 6 0"/><path d="M12 8V6.7"/><circle cx="12" cy="5.8" r=".9"/></symbol>
<symbol id="i-drumstick" viewBox="0 0 24 24"><path d="M15.4 15.6a7 7 0 1 0-7-7c0 1.8.7 3.5 1.9 4.8l-1 1.1"/><path d="m8.4 13.5-3 3a2.2 2.2 0 1 0-2 3.6 2.2 2.2 0 1 0 3.6-2l3-3"/></symbol>
<symbol id="i-skewer" viewBox="0 0 24 24"><path d="M3 21 21 3"/><circle cx="9" cy="15" r="2.2"/><circle cx="13" cy="11" r="2.2"/><circle cx="17" cy="7" r="2.2"/></symbol>
<symbol id="i-glass" viewBox="0 0 24 24"><path d="M6 3h12l-1 6a5 5 0 0 1-10 0Z"/><path d="M12 13v6"/><path d="M8 21h8"/></symbol>
<symbol id="i-donut" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.7"/><path d="M8 7.5l.5.8M15.5 6.8l-.4.9M17.5 12.5l-.9.2M7 13.5l.9.3M11 17.5l.4-.9"/></symbol>
<symbol id="i-bowl" viewBox="0 0 24 24"><path d="M3 11h18c0 5-4 9.5-9 9.5S3 16 3 11Z"/><path d="M8 8c1-2 3-3 5-3M13.5 8c1-1.4 2.5-2 4-2"/></symbol>
<symbol id="i-teacup" viewBox="0 0 24 24"><path d="M4 10h16v1.5a8 8 0 0 1-16 0Z"/><path d="M7.5 6.5c0-1 .5-1 .5-2M11.5 6.5c0-1 .5-1 .5-2M15.5 6.5c0-1 .5-1 .5-2"/></symbol>
<symbol id="i-drop" viewBox="0 0 24 24"><path d="M12 3s6 6.4 6 10.8a6 6 0 0 1-12 0C6 9.4 12 3 12 3Z"/></symbol>
<symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></symbol>
<symbol id="i-anchor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2.5"/><path d="M12 7.5V21"/><path d="M5 12H2.5a9.5 9.5 0 0 0 19 0H19"/></symbol>
<symbol id="i-burger" viewBox="0 0 24 24"><path d="M4 10a8 5.8 0 0 1 16 0Z"/><path d="M3.5 13.5h17"/><path d="M4 17h16v.5a3.5 3.5 0 0 1-3.5 3.5h-9A3.5 3.5 0 0 1 4 17.5Z"/><path d="M8.5 6.8h0M12 6h0M15.5 6.8h0"/></symbol>
<symbol id="i-bread" viewBox="0 0 24 24"><path d="M5 11a3.5 3.5 0 0 1 1.5-6.7h11A3.5 3.5 0 0 1 19 11v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2Z"/><path d="M9 8.5v3M15 8.5v3M12 7.5v4"/></symbol>
<symbol id="i-cake" viewBox="0 0 24 24"><path d="M4 21h16"/><path d="M5 21v-6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6"/><path d="M5 16c1.2 1 2.3 1 3.5 0s2.3-1 3.5 0 2.3 1 3.5 0 2.3-1 3.5 0"/><path d="M12 13V9"/><path d="M12 6.5c.8-.8.8-1.7 0-2.5-.8.8-.8 1.7 0 2.5Z"/></symbol>
<symbol id="i-icecream" viewBox="0 0 24 24"><path d="M7.5 11h9L12 22Z"/><path d="M17 11a5 5 0 0 0-10 0"/><path d="M8.7 6.4A4.5 4.5 0 0 1 15 4.6"/></symbol>
<symbol id="i-fish" viewBox="0 0 24 24"><path d="M7 12c2.4-3 5.4-4.5 9-4.5 2.8 0 5 2 6 4.5-1 2.5-3.2 4.5-6 4.5-3.6 0-6.6-1.5-9-4.5Z"/><path d="M7 12 2.5 8.5v7Z"/><path d="M17.5 11h0"/></symbol>
<symbol id="i-pot" viewBox="0 0 24 24"><path d="M4 13h16v1a6 6 0 0 1-6 6h-4a6 6 0 0 1-6-6Z"/><path d="M2 13h20"/><path d="m6.5 13 5-8.5 6 8.5"/><circle cx="11.5" cy="3.5" r="1"/></symbol>
<symbol id="i-pizza" viewBox="0 0 24 24"><path d="M3.5 6C6 4.2 9 3.2 12.3 3.2S18.6 4.2 21 6L12.3 21.5Z"/><path d="M4.8 8.5c2.2-1.3 4.7-2 7.5-2s5.3.7 7.4 2"/><circle cx="12" cy="10.5" r="1.3"/><circle cx="9.7" cy="14.2" r="1.1"/><circle cx="13.6" cy="15.4" r="1"/></symbol>
<symbol id="i-snow" viewBox="0 0 24 24"><path d="M12 2v20M4 6l16 12M20 6 4 18"/><path d="m12 5-2-2m2 2 2-2m-2 14-2 2m2-2 2 2"/></symbol>
<symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.5-4.5"/></symbol>
<symbol id="i-fb" viewBox="0 0 24 24"><path d="M14 8.5h3V5h-3a4.5 4.5 0 0 0-4.5 4.5V12H7v3.5h2.5V22H13v-6.5h2.7l.5-3.5H13V9.7c0-.7.5-1.2 1-1.2Z"/></symbol>

<!-- ============ FILLED FOOD ILLUSTRATIONS (120x120) ============ -->
<symbol id="ill-burger" viewBox="0 0 120 120">
  <ellipse cx="60" cy="104" rx="42" ry="8" fill="rgba(0,0,0,.28)"/>
  <path d="M22 62c0-21 17-34 38-34s38 13 38 34H22Z" fill="url(#lg-bun)"/>
  <ellipse cx="44" cy="43" rx="3" ry="1.8" fill="#fff3d9"/><ellipse cx="60" cy="38" rx="3" ry="1.8" fill="#fff3d9"/><ellipse cx="76" cy="43" rx="3" ry="1.8" fill="#fff3d9"/><ellipse cx="52" cy="51" rx="2.6" ry="1.6" fill="#fff3d9"/><ellipse cx="68" cy="51" rx="2.6" ry="1.6" fill="#fff3d9"/>
  <path d="M20 64h80c0 4-3 6-8 6l-4-3-6 4-7-4-7 4-7-4-7 4-6-4-5 3h-15c-5 0-8-2-8-6Z" fill="#8fbf4d"/>
  <path d="M24 72h72l-6 8-8-8-8 8-8-8-8 8-8-8-8 8-6-8-6 6Z" fill="url(#lg-cheese)"/>
  <rect x="24" y="78" width="72" height="12" rx="6" fill="url(#lg-patty)"/>
  <path d="M24 92h72a10 10 0 0 1-10 10H34a10 10 0 0 1-10-10Z" fill="url(#lg-bun)"/>
  <path d="M30 46a30 30 0 0 1 20-12" stroke="#ffe2a8" stroke-width="4" stroke-linecap="round" fill="none" opacity=".7"/>
</symbol>
<symbol id="ill-pizza" viewBox="0 0 120 120">
  <ellipse cx="60" cy="104" rx="40" ry="7" fill="rgba(0,0,0,.25)"/>
  <path d="M20 30 60 106 100 30C88 21 74 16 60 16S32 21 20 30Z" fill="url(#lg-cheese)"/>
  <path d="M20 30c12-9 26-14 40-14s28 5 40 14l-4 8c-11-8-23-12-36-12S35 30 24 38Z" fill="url(#lg-crust)"/>
  <circle cx="52" cy="50" r="8" fill="#c2402e"/><circle cx="72" cy="58" r="7" fill="#c2402e"/><circle cx="58" cy="76" r="6.5" fill="#c2402e"/>
  <circle cx="52" cy="50" r="8" fill="none" stroke="#8f2418" stroke-width="1.5"/><circle cx="72" cy="58" r="7" fill="none" stroke="#8f2418" stroke-width="1.5"/><circle cx="58" cy="76" r="6.5" fill="none" stroke="#8f2418" stroke-width="1.5"/>
  <path d="M44 62c3-1 5 1 5 3s-3 4-5 2-2-4 0-5Z" fill="#5f8f3d"/><path d="M68 42c3-1 5 1 5 3s-3 4-5 2-2-4 0-5Z" fill="#5f8f3d"/>
  <path d="M60 88c1.5 4 1 8-1 10" stroke="#f2a93b" stroke-width="4" stroke-linecap="round" fill="none"/>
</symbol>
<symbol id="ill-bowl" viewBox="0 0 120 120">
  <ellipse cx="60" cy="102" rx="42" ry="8" fill="rgba(0,0,0,.25)"/>
  <circle cx="60" cy="58" r="42" fill="url(#rg-plate)"/>
  <circle cx="60" cy="58" r="34" fill="#f4efe2"/>
  <path d="M35 48c6-8 14-12 22-11 -4 6-4 12 0 17-8 3-17 1-22-6Z" fill="url(#lg-green)"/>
  <path d="M62 36c9-2 17 1 22 8-6 3-9 8-9 14-8-2-14-9-13-22Z" fill="#7fae53"/>
  <path d="M42 66c8-3 15-1 19 5-5 5-12 6-19 3-3-2-3-6 0-8Z" fill="#9dc36a"/>
  <circle cx="72" cy="68" r="7" fill="#e2603a"/><circle cx="72" cy="68" r="3.4" fill="#f2905c"/>
  <circle cx="52" cy="42" r="5.5" fill="#e2603a"/><circle cx="52" cy="42" r="2.6" fill="#f2905c"/>
  <path d="M78 48a10 10 0 0 1 6 9c-5 1-9-1-11-5 1-2 3-4 5-4Z" fill="#c9dc72"/>
  <ellipse cx="60" cy="58" rx="3.2" ry="3.2" fill="#fff" opacity=".5"/>
</symbol>
<symbol id="ill-drink" viewBox="0 0 120 120">
  <ellipse cx="60" cy="108" rx="30" ry="6" fill="rgba(0,0,0,.25)"/>
  <path d="M66 10 82 4l2 6-15 6Z" fill="#e2603a"/><rect x="63" y="12" width="6" height="26" rx="3" transform="rotate(14 66 25)" fill="#f2905c"/>
  <path d="M36 32h48l-5 66a8 8 0 0 1-8 7.4H49A8 8 0 0 1 41 98Z" fill="#fff" opacity=".35"/>
  <path d="M39 46h42l-4 52a8 8 0 0 1-8 7H51a8 8 0 0 1-8-7Z" fill="url(#lg-drink)"/>
  <circle cx="52" cy="62" r="4" fill="#ffd9a8" opacity=".85"/><circle cx="66" cy="74" r="5" fill="#ffd9a8" opacity=".85"/><circle cx="56" cy="88" r="3.5" fill="#ffd9a8" opacity=".85"/>
  <path d="M34 32h52v8H34Z" rx="4" fill="#fff" opacity=".55"/>
  <path d="M84 40a13 13 0 0 0 0-26v6a7 7 0 0 1 0 14Z" fill="#f2b25c"/>
  <path d="M44 50c0 14 2 30 4 44" stroke="#fff" stroke-width="4" stroke-linecap="round" opacity=".45" fill="none"/>
</symbol>
<symbol id="ill-cake" viewBox="0 0 120 120">
  <ellipse cx="60" cy="106" rx="40" ry="7" fill="rgba(0,0,0,.25)"/>
  <path d="M24 100h72v4H24Z" fill="#d8d4cc"/>
  <path d="M28 68h64v32H28Z" fill="url(#lg-cream)"/>
  <path d="M28 80h64v8H28Z" fill="#e8b04c"/>
  <path d="M28 68c8 0 8 6 16 6s8-6 16-6 8 6 16 6 8-6 16-6v-8H28Z" fill="url(#lg-choc)"/>
  <path d="M42 66c0 4 3 8 3 12M60 66c0 5 3 9 3 13M78 66c0 4 3 8 3 12" stroke="#4a2a12" stroke-width="4" stroke-linecap="round" fill="none"/>
  <circle cx="60" cy="46" r="9" fill="#c2402e"/><circle cx="57" cy="43" r="2.6" fill="#e8735c"/>
  <path d="M60 37c2-5 6-7 10-7-1 4-4 7-8 8Z" fill="#5f8f3d"/>
</symbol>
<symbol id="ill-pot" viewBox="0 0 120 120">
  <ellipse cx="60" cy="106" rx="42" ry="7" fill="rgba(0,0,0,.28)"/>
  <path d="M60 16 96 74H24Z" fill="url(#lg-terra)"/>
  <path d="M60 16 76 42c-5 3-10 4-16 4s-11-1-16-4Z" fill="#e8a273" opacity=".55"/>
  <circle cx="60" cy="14" r="6" fill="#8a3f1c"/>
  <path d="M18 74h84v6a10 10 0 0 1-10 10H28a10 10 0 0 1-10-10Z" fill="#8a3f1c"/>
  <path d="M18 74h84v5H18Z" fill="#a34f28"/>
  <path d="M44 62c0-6 3-6 3-12M60 60c0-6 3-6 3-12M76 62c0-6 3-6 3-12" stroke="#fff" stroke-width="3.5" stroke-linecap="round" opacity=".55" fill="none"/>
</symbol>
<symbol id="ill-icecream" viewBox="0 0 120 120">
  <ellipse cx="60" cy="108" rx="28" ry="6" fill="rgba(0,0,0,.25)"/>
  <path d="M38 52h44L60 110Z" fill="url(#lg-crust)"/>
  <path d="M42 60h36M46 70h28M50 80h20M54 90h12" stroke="#a3671f" stroke-width="2.4" stroke-linecap="round"/>
  <path d="M60 8c10 0 17 6 17 14 6 1 9 5 9 10 0 7-6 12-13 12H47c-7 0-13-5-13-12 0-5 3-9 9-10 0-8 7-14 17-14Z" fill="url(#lg-chrome)"/>
  <path d="M42 22a18 14 0 0 1 12-9" stroke="#fff" stroke-width="4" stroke-linecap="round" fill="none" opacity=".8"/>
  <circle cx="78" cy="20" r="3" fill="#ff8ac2"/><circle cx="40" cy="38" r="2.4" fill="#43c6e8"/><circle cx="84" cy="38" r="2.2" fill="#8347d6"/>
</symbol>
<symbol id="ill-coffee" viewBox="0 0 120 120">
  <ellipse cx="60" cy="104" rx="42" ry="8" fill="rgba(0,0,0,.22)"/>
  <circle cx="60" cy="58" r="43" fill="url(#rg-plate)"/>
  <circle cx="60" cy="58" r="34" fill="url(#rg-coffee)"/>
  <path d="M60 34c-9 7-12 15-9 24 2 7 8 12 9 22 1-10 7-15 9-22 3-9 0-17-9-24Z" fill="#f4e8d8"/>
  <path d="M60 42c-5 5-7 10-5 16M60 42c5 5 7 10 5 16" stroke="#c8a880" stroke-width="2" fill="none" stroke-linecap="round"/>
  <path d="M60 80v-8" stroke="#f4e8d8" stroke-width="3" stroke-linecap="round"/>
  <path d="M38 40a26 26 0 0 1 10-8" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".35" fill="none"/>
</symbol>
<symbol id="ill-bread" viewBox="0 0 120 120">
  <ellipse cx="60" cy="100" rx="42" ry="8" fill="rgba(0,0,0,.25)"/>
  <path d="M18 72c0-22 19-38 42-38s42 16 42 38c0 12-10 20-22 20H40c-12 0-22-8-22-20Z" fill="url(#lg-bun)"/>
  <path d="M36 54c8-4 12 2 8 8s-14 2-8-8Z" fill="#fff0d4" opacity=".0"/>
  <path d="M38 52c6-8 12-8 16 0M56 46c6-8 12-8 16 0M50 64c6-8 12-8 16 0" stroke="#8a5a1c" stroke-width="4" stroke-linecap="round" fill="none"/>
  <path d="M26 58a34 30 0 0 1 12-14" stroke="#ffe2a8" stroke-width="5" stroke-linecap="round" fill="none" opacity=".8"/>
  <ellipse cx="76" cy="40" rx="2.2" ry="1.6" fill="#fff3d9"/><ellipse cx="66" cy="36" rx="2" ry="1.5" fill="#fff3d9"/><ellipse cx="84" cy="48" rx="2" ry="1.5" fill="#fff3d9"/>
</symbol>
<symbol id="ill-skewer" viewBox="0 0 120 120">
  <ellipse cx="60" cy="106" rx="40" ry="7" fill="rgba(0,0,0,.28)"/>
  <path d="M16 104 104 16" stroke="#c9a06a" stroke-width="5" stroke-linecap="round"/>
  <path d="M100 12l8 0 0 8" stroke="#c9a06a" stroke-width="5" stroke-linecap="round" fill="none"/>
  <rect x="30" y="66" width="26" height="26" rx="8" transform="rotate(-45 43 79)" fill="url(#lg-patty)"/>
  <rect x="48" y="48" width="24" height="24" rx="7" transform="rotate(-45 60 60)" fill="#c2402e"/>
  <rect x="66" y="30" width="24" height="24" rx="7" transform="rotate(-45 78 42)" fill="url(#lg-patty)"/>
  <path d="M36 84c2-2 6-2 8 0M56 62c2-2 6-2 8 0M74 40c2-2 6-2 8 0" stroke="#3d1c0a" stroke-width="3" stroke-linecap="round" fill="none"/>
  <path d="M40 100c-2-5 2-7 1-11M52 96c-2-5 2-7 1-11" stroke="#f2905c" stroke-width="3" stroke-linecap="round" fill="none" opacity=".8"/>
</symbol>
<symbol id="ill-fish" viewBox="0 0 120 120">
  <ellipse cx="60" cy="98" rx="46" ry="9" fill="rgba(0,0,0,.25)"/>
  <ellipse cx="60" cy="72" rx="48" ry="22" fill="url(#rg-plate)"/>
  <path d="M22 66c10-14 24-21 42-21 14 0 26 8 32 20-6 12-18 20-32 20-18 0-32-7-42-19Z" fill="url(#lg-fish)"/>
  <path d="M22 66c-6-4-9-9-9-15 6 1 11 4 15 9-3 2-5 4-6 6Zm0 0c-6 4-9 9-9 15 6-1 11-4 15-9-3-2-5-4-6-6Z" fill="#5c8ba1"/>
  <path d="M42 58c6 4 6 12 0 16M56 54c6 5 6 15 0 20M70 54c5 5 5 14 0 19" stroke="#3d6478" stroke-width="3" stroke-linecap="round" fill="none"/>
  <circle cx="84" cy="62" r="2.6" fill="#16303d"/>
  <circle cx="24" cy="88" r="7" fill="#f2d33b"/><path d="M24 81v14M17 88h14M19 83l10 10M29 83l-10 10" stroke="#e8b81c" stroke-width="1.4"/>
  <path d="M96 84c5 0 8-2 10-5" stroke="#5f8f3d" stroke-width="4" stroke-linecap="round" fill="none"/>
</symbol>
<symbol id="ill-platter" viewBox="0 0 120 120">
  <ellipse cx="60" cy="96" rx="48" ry="10" fill="rgba(0,0,0,.28)"/>
  <ellipse cx="60" cy="76" rx="50" ry="24" fill="url(#rg-plate)"/>
  <ellipse cx="60" cy="72" rx="42" ry="18" fill="#f2d898"/>
  <ellipse cx="46" cy="66" rx="2" ry="1.3" fill="#e8b84c"/><ellipse cx="58" cy="62" rx="2" ry="1.3" fill="#e8b84c"/><ellipse cx="72" cy="66" rx="2" ry="1.3" fill="#e8b84c"/><ellipse cx="52" cy="74" rx="2" ry="1.3" fill="#e8b84c"/><ellipse cx="66" cy="76" rx="2" ry="1.3" fill="#e8b84c"/><ellipse cx="80" cy="72" rx="2" ry="1.3" fill="#e8b84c"/>
  <path d="M42 48c2-10 10-16 20-16 12 0 20 8 20 19 0 7-4 12-10 14-9 3-19 1-25-5-4-4-6-8-5-12Z" fill="url(#lg-patty)"/>
  <path d="M48 42c3-5 8-7 13-7" stroke="#b06a3c" stroke-width="4" stroke-linecap="round" fill="none"/>
  <path d="M30 84c4 2 9 3 14 3M76 86c5 0 10-1 14-3" stroke="#5f8f3d" stroke-width="4" stroke-linecap="round" fill="none"/>
  <circle cx="88" cy="58" r="5.5" fill="#f2d33b"/><path d="M88 53v11M83 58h10" stroke="#e8b81c" stroke-width="1.3"/>
</symbol>
<symbol id="ill-citrus" viewBox="0 0 120 120">
  <circle cx="60" cy="60" r="46" fill="#f2a93b"/>
  <circle cx="60" cy="60" r="38" fill="#ffd970"/>
  <g fill="#f2a93b"><path d="M60 26a34 34 0 0 1 24 10L60 60Z"/><path d="M94 60a34 34 0 0 1-10 24L60 60Z"/><path d="M60 94a34 34 0 0 1-24-10L60 60Z"/><path d="M26 60a34 34 0 0 1 10-24L60 60Z"/></g>
  <circle cx="60" cy="60" r="5" fill="#fff3d9"/>
</symbol>
<symbol id="ill-wrap" viewBox="0 0 120 120">
  <ellipse cx="60" cy="106" rx="30" ry="6" fill="rgba(0,0,0,.25)"/>
  <path d="M40 44h40L64 108a6 6 0 0 1-8 0Z" fill="#f4e8d0"/>
  <path d="M40 44h40l-6 24H46Z" fill="#e8d4b0"/>
  <path d="M42 44c4-10 10-16 18-16s14 6 18 16c-6 3-12 4-18 4s-12-1-18-4Z" fill="url(#lg-green)"/>
  <path d="M48 34c2 3 5 5 8 5M64 32c2 3 5 5 8 5" stroke="#c2402e" stroke-width="5" stroke-linecap="round" fill="none"/>
  <path d="M50 40c3 2 7 3 10 3" stroke="#f2d33b" stroke-width="4" stroke-linecap="round" fill="none"/>
  <path d="M46 68h28" stroke="#c9a06a" stroke-width="2.5" stroke-dasharray="4 4"/>
</symbol>
</defs>
</svg>
