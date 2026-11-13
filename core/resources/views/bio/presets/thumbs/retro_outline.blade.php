{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- retro_outline thumbnail — the "Poppy"-style outline template: cream ground,
     black borders, offset hard-shadow buttons that pop, uppercase labels, one
     bright yellow avatar. Bespoke inline-styled mini-mockup. Mirrors .d20. --}}
<div class="bio-thumb bio-thumb--retro_outline" data-preset="retro_outline" data-tier="pro" role="img" aria-label="Retro Outline"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#fdf3e3;padding:18px 14px 0;text-align:center;font-family:'Poppins','Manrope',system-ui,sans-serif;color:#191713">
    <div style="width:36px;height:36px;margin:0 auto 8px;border-radius:50%;background:#ffd23e;border:2.5px solid #191713;display:grid;place-items:center;font-family:'Archivo',system-ui,sans-serif;font-weight:900;font-size:15px;letter-spacing:-.05em">TT</div>
    <div style="font-size:9px;font-weight:800;margin-bottom:3px">@@tacotuesdayksa</div>
    <div style="font-size:6.5px;color:#5c554c;margin-bottom:13px;line-height:1.5">Baja-style tacos, KSA-made 🌶️</div>
    @foreach (['TRUCK LOCATION','MENU','ORDER (NEW SHOP)'] as $t)
        <div style="background:#fdf3e3;border:2.5px solid #191713;border-radius:9px;padding:9px 8px;margin-bottom:11px;font-size:8px;font-weight:700;box-shadow:4px 4px 0 #191713">{{ $t }}</div>
    @endforeach
</div>
