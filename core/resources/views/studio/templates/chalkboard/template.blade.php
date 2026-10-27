{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- Chalkboard. Standalone page: inline CSS only, no external requests.
     All menu/slot text is user data — {{ }} escaping is mandatory. --}}
<html dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style nonce="{{ csp_nonce() }}">
:root {
    --accent: {{ $colors['accent'] }};
    --bg: {{ $colors['bg'] }};
    --text: {{ $colors['text'] }};
}
{!! $css !!}
</style>
@includeWhen(!empty($background), 'studio.templates._bg')
</head>
<body>
<div class="sheet aspect-{{ $aspect }}">
<div class="board">
    <header class="menu-header">
        <h1 class="restaurant-name">{{ $slots['store_name'] !== '' ? $slots['store_name'] : ___('our_menu') }}</h1>
        @if ($slots['tagline'] !== '')
            <div class="rule"></div>
            <p class="tagline">{{ $slots['tagline'] }}</p>
        @endif
    </header>

    <main class="menu-body">
        @foreach (($draft['categories'] ?? []) as $category)
            <section class="category">
                <h2 class="category-name">{{ $category['name'] ?? '' }}</h2>
                @foreach (($category['items'] ?? []) as $item)
                    <article class="item">
                        <div class="item-line">
                            <span class="item-name" data-path="{{ $loop->parent->index }}.{{ $loop->index }}">{{ $item['name'] ?? '' }}</span>
                            <span class="item-dots"></span>
                            @if (isset($item['price']) && $item['price'] !== null && $item['price'] !== '')
                                <span class="item-price">{{ rtrim(rtrim(number_format((float) $item['price'], 2, '.', ''), '0'), '.') }} {{ $currency }}</span>
                            @endif
                        </div>
                        @php($desc = trim((string) ($item['display_description'] ?? $item['description'] ?? '')))
                        @if ($desc !== '')
                            <p class="item-desc">{{ $desc }}</p>
                        @endif
                        @if (!empty($item['tags']))
                            <div class="item-tags">
                                @foreach ($item['tags'] as $tag)
                                    <span class="tag">{{ ___($tag) }}</span>
                                @endforeach
                            </div>
                        @endif
                        @foreach (($item['variants'] ?? []) as $variant)
                            @if (!empty($variant['options']))
                                <p class="item-variants">
                                    {{ $variant['title'] ?? '' }}:
                                    @foreach ($variant['options'] as $vi => $option)
                                        {{ $option }}@if(isset($variant['prices'][$vi]) && $variant['prices'][$vi] !== null) {{ rtrim(rtrim(number_format((float) $variant['prices'][$vi], 2, '.', ''), '0'), '.') }}@endif @if(!$loop->last) · @endif
                                    @endforeach
                                </p>
                            @endif
                        @endforeach
                        @if (!empty($item['extras']))
                            <p class="item-extras">
                                + @foreach ($item['extras'] as $extra){{ $extra['title'] ?? '' }}@if(isset($extra['price']) && $extra['price'] !== null) {{ rtrim(rtrim(number_format((float) $extra['price'], 2, '.', ''), '0'), '.') }}@endif @if(!$loop->last) · @endif
                                @endforeach
                            </p>
                        @endif
                    </article>
                @endforeach
            </section>
        @endforeach
    </main>

    @if ($slots['footer_note'] !== '')
        <footer class="menu-footer">{{ $slots['footer_note'] }}</footer>
    @endif
</div>
</div>
</body>
</html>
