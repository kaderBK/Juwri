{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- zellige_arch thumbnail — "Dar Zaytoun" Moroccan courtyard: zellige tile band,
     keyhole-arch avatar, gradient tile-border links in terracotta/teal/brass.
     Bespoke inline-styled mini-mockup. Mirrors .d14. --}}
<div class="bio-thumb bio-thumb--zellige_arch" data-preset="zellige_arch" data-tier="pro" role="img" aria-label="Zellige Arch"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#f0e6d2;padding:0 0 0;font-family:'Manrope',system-ui,sans-serif;color:#3a2a20">
    <div style="height:26px;background:radial-gradient(circle at 0% 50%,#1f6f6b 4px,transparent 4.3px),radial-gradient(circle at 100% 50%,#1f6f6b 4px,transparent 4.3px),radial-gradient(circle at 50% 0%,#b45b3e 4px,transparent 4.3px),radial-gradient(circle at 50% 100%,#b45b3e 4px,transparent 4.3px),#e7b95c;background-size:14px 14px"></div>
    <div style="padding:0 12px;text-align:center">
        <div style="width:42px;height:50px;margin:-16px auto 8px;border-radius:21px 21px 5px 5px;background:#fdf9f0;border:1.5px solid #b45b3e;box-shadow:0 0 0 3px #f0e6d2;display:grid;place-items:center;font-size:20px">🫖</div>
        <div style="font-family:'Fraunces',Georgia,serif;font-weight:640;font-size:15px;color:#7c3b24;margin-bottom:1px">Dar Zaytoun</div>
        <div style="font-size:6.5px;color:#8a715c;margin-bottom:11px">Moroccan house kitchen · Doha</div>
        @foreach ([['🍲','The Menu'],['✨','Book Courtyard'],['🛵','Order Delivery']] as [$ic,$t])
            <div style="margin-bottom:8px;padding:1.5px;border-radius:11px;background:linear-gradient(#f0e6d2,#f0e6d2) padding-box,linear-gradient(120deg,#b45b3e,#e7b95c,#1f6f6b) border-box;border:1.5px solid transparent">
                <div style="display:flex;align-items:center;gap:7px;background:#fdf9f0;border-radius:9px;padding:7px 9px;text-align:left">
                    <span style="width:18px;height:18px;flex:none;border-radius:9px 9px 3px 3px;background:#f4e7cd;border:1px solid #e0c9a2;display:grid;place-items:center;font-size:9px">{{ $ic }}</span>
                    <span style="font-size:8px;font-weight:800">{{ $t }}</span>
                    <span style="margin-left:auto;color:#1f6f6b;font-size:9px">❖</span>
                </div>
            </div>
        @endforeach
        <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:6px;font-size:7.5px;color:#1f6f6b;font-weight:700"><span style="color:#e7b95c">✦</span>Mint tea on the house<span style="color:#e7b95c">✦</span></div>
    </div>
</div>
