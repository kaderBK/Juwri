{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- y2k_chrome thumbnail — "Chrome Scoop" liquid-metal Y2K: chrome-gradient wordmark,
     metallic pill links with inner bevel, sparkles, holographic drop border.
     Bespoke inline-styled mini-mockup. Mirrors .d15. --}}
<div class="bio-thumb bio-thumb--y2k_chrome" data-preset="y2k_chrome" data-tier="pro" role="img" aria-label="Y2K Chrome"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;position:relative;
     background:linear-gradient(165deg,#e9e9f2 0%,#cfd2e2 34%,#e7e2f0 62%,#c8cde0 100%);padding:16px 13px 0;text-align:center;font-family:'Manrope',system-ui,sans-serif;color:#2a2438">
    <span style="position:absolute;top:24px;left:14px;color:#fff;text-shadow:0 0 6px rgba(120,110,220,.8);font-size:9px">✦</span>
    <span style="position:absolute;top:54px;right:16px;color:#fff;text-shadow:0 0 6px rgba(120,110,220,.8);font-size:7px">✧</span>
    <div style="width:40px;height:40px;margin:0 auto 8px;border-radius:50%;padding:3px;background:conic-gradient(from 0deg,#b7bdd6,#ffffff 20%,#8f96b8 35%,#e8eaf5 55%,#9aa1c4 70%,#ffffff 85%,#b7bdd6);box-shadow:0 8px 18px rgba(80,80,130,.3)">
        <div style="display:grid;place-items:center;width:100%;height:100%;border-radius:50%;font-size:18px;background:radial-gradient(circle at 32% 28%,#fff,#d5d8e8 60%,#aab0cc)">🍦</div>
    </div>
    <div style="font-family:'Unbounded','Manrope',system-ui,sans-serif;font-weight:700;font-size:13px;background:linear-gradient(180deg,#fdfdff 8%,#8b93b8 38%,#e8ebf7 52%,#5d6488 78%,#c9cee6);-webkit-background-clip:text;background-clip:text;color:transparent;filter:drop-shadow(0 1px 0 rgba(40,40,70,.45)) drop-shadow(0 3px 8px rgba(90,90,150,.35));margin-bottom:8px">CHROME SCOOP</div>
    <div style="display:inline-block;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.24em;color:#8347d6;background:rgba(255,255,255,.6);border:1px solid #c5b3ec;border-radius:999px;padding:3px 9px;margin-bottom:12px">EST. 2024 ★ FUTURE FLAVORS</div>
    @foreach ([['🍨','Flavor Board','LIVE'],['🛵','Order Delivery',''],['🎂','Gelato Cakes','']] as [$ic,$t,$tag])
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:9px;padding:1.5px;border-radius:999px;background:linear-gradient(120deg,#ffffff,#9aa1c4 30%,#e8eaf5 50%,#8f96b8 72%,#ffffff);box-shadow:0 5px 12px rgba(80,80,130,.25)">
            <span style="display:flex;align-items:center;gap:8px;width:100%;border-radius:999px;padding:7px 11px;background:linear-gradient(180deg,#fbfbfe,#e9e9f4);text-align:left">
                <span style="font-size:11px">{{ $ic }}</span>
                <span style="font-size:8px;font-weight:800">{{ $t }}</span>
                @if ($tag)<span style="margin-left:auto;font-family:'IBM Plex Mono',ui-monospace,monospace;font-size:5px;letter-spacing:.12em;color:#ff3d9a;border:1px solid #ffb3d9;background:#fff;border-radius:999px;padding:2px 6px">{{ $tag }}</span>@endif
            </span>
        </div>
    @endforeach
</div>
