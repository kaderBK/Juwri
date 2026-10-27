{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- chat_landing thumbnail — "Teta's Kitchen" Messgme-style: the whole page is a chat —
     green header with online dot, incoming white bubbles, quick-reply chips, one outgoing
     "me" bubble, and a WhatsApp CTA. Inline-styled mini-mockup. Mirrors the .d28 design. --}}
<div class="bio-thumb bio-thumb--chat_landing" data-preset="chat_landing" data-tier="pro" role="img" aria-label="Chat Landing"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     background:#e9e2d8;color:#1e2126;text-align:left;padding:0;font-family:'Manrope',system-ui,sans-serif">
    <div style="background:#106e5a;color:#fff;padding:13px 10px 9px;display:flex;align-items:center;gap:7px">
        <div style="width:22px;height:22px;border-radius:50%;background:#fff;flex:none"></div>
        <div><div style="font-size:8.5px;font-weight:700">Teta's Kitchen</div><div style="font-size:5.5px;color:#a8dcc9;display:flex;align-items:center;gap:3px"><span style="width:4px;height:4px;border-radius:50%;background:#5ee39a"></span>online — replies in ~2 min</div></div>
    </div>
    <div style="padding:9px 9px 0">
        <div style="max-width:84%;background:#fff;border-radius:10px 10px 10px 3px;padding:6px 8px;font-size:6.5px;line-height:1.4;margin-bottom:6px;box-shadow:0 1px 2px rgba(30,33,38,.08)">Ahlan! 👋 Home-style Levantine food in Sharjah.</div>
        <div style="max-width:70%;background:#fff;border-radius:10px 10px 10px 3px;padding:6px 8px;font-size:6.5px;line-height:1.4;margin-bottom:7px;box-shadow:0 1px 2px rgba(30,33,38,.08)">What are you in the mood for?</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:7px">
            @foreach (['See the menu', 'Order', 'Hours'] as $c)
                <span style="color:#106e5a;background:#fff;border:1px solid #106e5a;border-radius:99px;font-size:6px;font-weight:700;padding:4px 8px">{{ $c }}</span>
            @endforeach
        </div>
        <div style="max-width:82%;margin-left:auto;background:#d5f3e3;border-radius:10px 10px 3px 10px;padding:6px 8px;font-size:6.5px;line-height:1.4;margin-bottom:8px">Are they like my grandmother's? 🥺</div>
        <div style="background:#106e5a;color:#fff;border-radius:99px;padding:8px;text-align:center;font-weight:700;font-size:7px">Continue on WhatsApp</div>
    </div>
</div>
