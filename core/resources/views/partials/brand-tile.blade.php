{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Animated gradient logo tile — shared by every shell (superadmin, owner,
     reseller, public site, auth). The artwork is picked in
     Settings → Logo & Favicon → Logo background effect; see brand_tile_url().
     Include this inside <head>. --}}
@php $__brandTile = brand_tile_url(); @endphp
@php $__brandTileCss = public_path('assets/brand/brand-tile.css'); @endphp
<link rel="stylesheet" href="{{ asset('assets/brand/brand-tile.css') }}?v={{ is_file($__brandTileCss) ? filemtime($__brandTileCss) : '1' }}">
@if ($__brandTile)
    @php $__brandTileSpeed = brand_tile_speed(); @endphp
    <style nonce="{{ csp_nonce() }}">:root{--brand-logo-bg:url('{{ $__brandTile }}');--brand-logo-speed:{{ $__brandTileSpeed }}s;}@if ($__brandTileSpeed === 0).brand-tile{animation:none}@endif</style>
@else
    {{-- Effect off: collapse the frame to a plain wrapper. --}}
    <style nonce="{{ csp_nonce() }}">.brand-tile{padding:0;border-radius:0;background-image:none;box-shadow:none;animation:none}.brand-tile>img{padding:0;border-radius:0;background:none}</style>
@endif
