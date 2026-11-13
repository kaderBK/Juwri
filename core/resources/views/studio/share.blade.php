{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('Menu preview') }} — {{ config('settings.website_title', config('app.name')) }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { --bg:#0d0f14; --paper:#faf8f4; --ink:#1d1d22; --muted:#7a7a85; --accent:#7c5cff; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; }
        .sheet { max-width:640px; margin:24px auto; background:var(--paper); border-radius:14px; padding:32px 28px; position:relative; overflow:hidden; }
        .wm { position:absolute; inset:0; pointer-events:none; display:flex; align-items:center; justify-content:center;
            font-size:2rem; font-weight:800; color:rgba(124,92,255,.08); transform:rotate(-24deg); letter-spacing:.1em; }
        h1 { margin:0 0 4px; font-size:1.6rem; }
        .made { color:var(--muted); font-size:.85rem; margin:0 0 20px; }
        .cat { margin:0 0 22px; }
        .cat h2 { margin:0 0 8px; color:var(--accent); font-size:1.2rem; border-bottom:2px solid var(--accent); padding-bottom:6px; }
        .row { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px dashed #e2ddd2; }
        .row:last-child { border-bottom:0; }
        .price { color:var(--accent); font-weight:700; white-space:nowrap; }
        .desc { color:var(--muted); font-size:.9rem; }
        .cta { display:block; text-align:center; margin:8px auto 40px; }
        .cta a { display:inline-block; background:var(--accent); color:#fff; text-decoration:none; padding:12px 24px; border-radius:999px; font-weight:600; }
    </style>
</head>
<body>
    @if (!empty($templateHtml))
        {{-- studio-templates P2-5: watermarked designed preview (scripts stay blocked) --}}
        <iframe class="tplshare" sandbox="allow-same-origin" title="{{ ___('Menu preview') }}"
                srcdoc="{{ $templateHtml }}"
                style="display:block;width:min(720px,94vw);height:80vh;margin:24px auto 0;border:0;border-radius:14px;background:#fff"></iframe>
    @endif
    <div class="sheet">
        <div class="wm">{{ config('settings.website_title', config('app.name')) }}</div>
        <h1>{{ ___('Menu preview') }}</h1>
        <p class="made">{{ ___('Made with') }} {{ config('settings.website_title', config('app.name')) }} — {{ ___('a read-only preview.') }}</p>
        @forelse($draft['categories'] ?? [] as $cat)
            <div class="cat">
                <h2>{{ $cat['name'] ?? 'Menu' }}</h2>
                @foreach($cat['items'] ?? [] as $item)
                    <div class="row">
                        <div>
                            <div>{{ $item['name'] ?? '' }}</div>
                            @if(!empty($item['description']))<div class="desc">{{ $item['description'] }}</div>@endif
                        </div>
                        @if(isset($item['price']) && $item['price'] !== null)<div class="price">{{ $item['price'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @empty
            <p class="desc">{{ ___('This preview is empty.') }}</p>
        @endforelse
    </div>
    <div class="cta"><a href="{{ route('studio') }}">{{ ___('Make your own menu') }}</a></div>
</body>
</html>
