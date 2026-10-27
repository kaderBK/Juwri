{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- type_lite thumbnail — "nadia bakes." lowercase minimal: no avatar, no buttons — hairline
     text rows with right-aligned hints and an underlined footer. Bespoke inline-styled
     mini-mockup (STYLE_ATTR policy-allowed). Mirrors the .d34 artifact design. --}}
<div class="bio-thumb bio-thumb--type_lite" data-preset="type_lite" data-tier="pro" role="img" aria-label="Type Lite"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#fcfcfc;padding:22px 16px 0;font-family:'Manrope',-apple-system,'Segoe UI',sans-serif;color:#191919;text-align:left">
    <div style="font-size:14px;font-weight:600;letter-spacing:-.02em">nadia bakes.</div>
    <div style="font-size:8px;color:#8c8c8c;line-height:1.5;margin:4px 0 11px">small-batch sourdough. fridays only.</div>
    @foreach (["this friday's list" => 'wed', 'pre-order' => 'thu 8pm', 'pickup point' => 'al zahra', 'wholesale' => 'email'] as $lbl => $hint)
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;padding:8px 1px;border-top:1px solid #ebebeb;{{ $loop->last ? 'border-bottom:1px solid #ebebeb;' : '' }}font-size:9.5px;font-weight:500">
            {{ $lbl }}<span style="font-size:7px;color:#b3b3b3">{{ $hint }}</span>
        </div>
    @endforeach
    <div style="font-size:8px;color:#8c8c8c;margin-top:12px;line-height:1.7">questions → <span style="color:#191919;border-bottom:1px solid #d9d9d9">whatsapp</span></div>
</div>
