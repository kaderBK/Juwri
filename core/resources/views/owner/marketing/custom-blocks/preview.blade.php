{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Theme Studio phase A: owner DRAFT preview of one custom block. The draft goes
     through the same render pipeline as the storefront (BuilderRender::page:
     cleanPage at echo → nonce'd <style>, escaped merge tags, curated forms). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ ($rtl ?? false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ___('Preview') }} — {{ $block->name }}</title>
    <style nonce="{{ csp_nonce() }}">
        html, body { margin: 0; padding: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #fafafa; color: #18181b; }
        .cb-preview-flag { position: sticky; top: 0; z-index: 10; background: #fff3cd; color: #7a5b00; text-align: center; font: 500 13px/1.4 system-ui, sans-serif; padding: 6px 10px; }
        .cb-preview-frame { max-width: 960px; margin: 24px auto; background: #fff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow: hidden; }
    </style>
    @include('builder._runtime-assets')
</head>
<body>
    <div class="cb-preview-flag">{{ ___('Draft preview — publish the block to show it on your storefront.') }}</div>
    <div class="cb-preview-frame">
        {!! \Juwri\Modules\Content\Services\Builder\BuilderRender::page((string) ($block->html ?? ''), \App\Models\StoreCustomBlock::tokens($store)) !!}
    </div>
</body>
</html>
