{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- kraft_paper thumbnail — "Dough & Co." artisan kraft: paper grain, rubber-stamp
     logo mark, handwritten margin note, hard-shadow tickets and a bake schedule
     with a "sold out!" scrawl. Bespoke inline-styled mini-mockup. Mirrors .d16. --}}
<div class="bio-thumb bio-thumb--kraft_paper" data-preset="kraft_paper" data-tier="pro" role="img" aria-label="Kraft Paper"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#e3cda9;background-image:radial-gradient(rgba(74,54,32,.08) 1px,transparent 1.4px),radial-gradient(rgba(255,255,255,.16) 1px,transparent 1.4px);background-size:11px 11px,17px 17px;background-position:0 0,6px 8px;
     padding:15px 13px 0;text-align:center;font-family:'Manrope',system-ui,sans-serif;color:#4a3620">
    <div style="position:relative;width:40px;height:40px;margin:0 auto 7px;border-radius:50%;border:2px solid #4a3620;display:grid;place-items:center;transform:rotate(-5deg);background:rgba(255,251,240,.5);font-size:18px">🍞
        <span style="position:absolute;inset:4px;border-radius:50%;border:1px dashed #4a3620"></span>
    </div>
    <div style="font-family:'Fraunces',Georgia,serif;font-weight:640;font-size:15px">Dough <span style="font-style:italic;color:#a4632a">&amp;</span> Co.</div>
    <div style="font-family:'Caveat',cursive;font-size:10px;color:#a4632a;margin:1px 0 11px;transform:rotate(-1.5deg)">wood-fired sourdough since 2021 ~</div>
    @foreach ([['🍞','Fresh Bakes','start here!'],['☕','The Café Menu','']] as [$ic,$t,$note])
        <div style="position:relative;display:flex;align-items:center;gap:8px;margin-bottom:11px;padding:8px 10px;background:#fbf6ea;border-radius:5px;border:1.5px solid #4a3620;box-shadow:2.5px 2.5px 0 rgba(74,54,32,.35);text-align:left">
            @if ($note)<span style="position:absolute;right:8px;top:-9px;font-family:'Caveat',cursive;font-size:10px;color:#a4632a;transform:rotate(3deg)">{{ $note }}</span>@endif
            <span style="width:18px;height:18px;flex:none;border-radius:5px;background:#efe3c8;border:1.5px dashed #a4632a;display:grid;place-items:center;font-size:9px">{{ $ic }}</span>
            <span style="font-size:8px;font-weight:800">{{ $t }}</span>
        </div>
    @endforeach
    <div style="background:#fbf6ea;border:1.5px solid #4a3620;border-radius:5px;padding:9px 10px;text-align:left;box-shadow:2.5px 2.5px 0 rgba(74,54,32,.35)">
        <div style="font-family:'Fraunces',Georgia,serif;font-size:9px;font-weight:640;margin-bottom:5px">Out of the oven</div>
        <div style="display:flex;align-items:baseline;gap:5px;font-size:7px;padding:3px 0;border-bottom:1px dashed rgba(74,54,32,.3)"><b style="font-weight:800">07:00</b><i style="flex:1;border-bottom:1.5px dotted rgba(74,54,32,.35);transform:translateY(-2px)"></i><em style="font-style:normal;color:#8a6c47">Sourdough</em></div>
        <div style="display:flex;align-items:baseline;gap:5px;font-size:7px;padding:3px 0"><b style="font-weight:800">10:00</b><i style="flex:1;border-bottom:1.5px dotted rgba(74,54,32,.35);transform:translateY(-2px)"></i><em style="font-style:normal;text-decoration:line-through;opacity:.55">Cinnamon knots</em><span style="font-family:'Caveat',cursive;font-size:9px;color:#b0432c;transform:rotate(-2deg)">sold out!</span></div>
    </div>
</div>
