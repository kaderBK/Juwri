{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Standalone document for a locked-template page. The template brings its own
    header/footer, so this is NOT wrapped in a surface layout. $lt comes from
    Juwri\Modules\Content\Services\LockedTemplates\Renderer::render(); every value in it is already
    escaped/validated there. All JS is external (/assets) — no inline script — so the
    page is CSP-friendly and safe to full-page cache.

    Expects: $lt, $title, and optionally $metaDescription, $favicon, $headExtra (HTML).
--}}
<!DOCTYPE html>
<html {!! $lt['html_attrs'] !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if(!empty($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    @if(!empty($favicon))
        <link rel="icon" href="{{ $favicon }}">
    @else
        <link rel="icon" href="data:,">
    @endif
    {!! $headExtra ?? '' !!}
    @if($lt['dir'] === 'rtl')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    @endif
    @foreach($lt['head_scripts'] ?? [] as $src)
        <script src="{{ $src }}"></script>
    @endforeach
    @foreach($lt['styles'] as $href)
        <link rel="stylesheet" href="{{ $href }}">
    @endforeach
</head>
<body class="{{ $lt['body_class'] }}">
{!! $lt['body'] !!}
@foreach($lt['scripts'] as $src)
    <script src="{{ $src }}" defer></script>
@endforeach
</body>
</html>
