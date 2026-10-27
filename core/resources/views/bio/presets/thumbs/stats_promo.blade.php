{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- stats_promo thumbnail — "Crispy Council" Mezink-style social proof: warm gradient
     header, overlapping stat cards (followers/rating/branches), a dashed coupon banner
     with code chip, NEW-badged buttons. Inline-styled mini-mockup. Mirrors the .d27. --}}
<div class="bio-thumb bio-thumb--stats_promo" data-preset="stats_promo" data-tier="pro" role="img" aria-label="Stats Promo"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#fff;color:#1e2126;padding:0;font-family:'Manrope',system-ui,sans-serif">
    <div style="background:linear-gradient(150deg,#ff5f3c,#ff9a3c);padding:15px 12px 20px;color:#fff;text-align:center">
        <div style="width:30px;height:30px;border-radius:50%;background:#fff;margin:0 auto 5px;box-shadow:0 5px 12px rgba(160,50,10,.3)"></div>
        <div style="font-size:11px;font-weight:800">Crispy Council</div>
        <div style="font-size:6px;color:rgba(255,255,255,.88);margin-top:1px">Broasted &amp; sauced · Riyadh</div>
    </div>
    <div style="display:flex;gap:5px;margin:-13px 10px 0;position:relative">
        @foreach ([['128K', 'Followers'], ['4.9★', 'Rating'], ['3', 'Branches']] as [$n, $l])
            <div style="flex:1;background:#fff;border-radius:8px;padding:6px 2px;box-shadow:0 5px 12px rgba(30,33,38,.12);text-align:center">
                <b style="display:block;font-size:9px;font-weight:800">{{ $n }}</b>
                <span style="font-size:4.5px;color:#8a8f99;font-weight:600;text-transform:uppercase;letter-spacing:.06em">{{ $l }}</span>
            </div>
        @endforeach
    </div>
    <div style="padding:9px 10px 0">
        <div style="border-radius:10px;background:#fff3ec;border:1.2px dashed #ff5f3c;padding:7px 8px;display:flex;align-items:center;gap:6px;margin-bottom:8px">
            <span style="flex:1"><b style="display:block;font-size:7.5px;font-weight:800;color:#c2410c">20% off first order</b><span style="font-size:5px;color:#8a5a3c">App-free — just use the code</span></span>
            <span style="flex:none;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:6px;background:#ff5f3c;color:#fff;border-radius:5px;padding:3px 6px">CRISPY20</span>
        </div>
        <div style="background:#f4f5f7;border-radius:9px;padding:6px 8px;font-size:7.5px;font-weight:600;margin-bottom:6px">Menu &amp; Combos</div>
        <div style="background:#f4f5f7;border-radius:9px;padding:6px 8px;font-size:7.5px;font-weight:600;display:flex;align-items:center">Order Delivery<span style="margin-left:auto;font-size:4.5px;font-weight:800;background:#ff5f3c;color:#fff;border-radius:99px;padding:2px 5px;letter-spacing:.06em">NEW</span></div>
    </div>
</div>
