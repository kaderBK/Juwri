{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Generic preset thumbnail for the dashboard picker. Fully inline-styled (STYLE_ATTR is
     policy-allowed on both storefront + dashboard) so it needs no external CSS and no style element.
     Pass ['slug' => '...'] or ['preset' => [...]]. --}}
@php
    $slug = (string) ($slug ?? ($preset['slug'] ?? ''));
    if (! isset($preset) || ! is_array($preset)) {
        $jf = resource_path('views/bio/presets/'.preg_replace('/[^a-z0-9_]/', '', $slug).'.json');
        $preset = is_file($jf) ? (json_decode((string) file_get_contents($jf), true) ?: []) : [];
    }
    $t = is_array($preset['tokens'] ?? null) ? $preset['tokens'] : [];
    $bg = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--bg'] ?? null, '#eeeeee');
    $card = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--btn-bg'] ?? $t['--card'] ?? null, '#ffffff');
    $ink = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--ink'] ?? null, '#111111');
    $muted = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--muted'] ?? null, '#888888');
    $accent = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--accent'] ?? null, '#0f7d5a');
    $rad = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--radius'] ?? null, '12px');
    $border = \Juwri\Core\Support\Views\PublicSiteViewHelpers::safeCssValue($t['--btn-border'] ?? null, 'transparent');
    $name = (string) ($preset['name'] ?? $slug);
    $tier = ($preset['tier'] ?? 'pro') === 'basic' ? 'basic' : 'pro';
@endphp
<div class="bio-thumb" data-preset="{{ $slug }}" data-tier="{{ $tier }}" role="img" aria-label="{{ $name }}"
     style="display:block;width:100%;max-width:150px;aspect-ratio:118/168;margin:0 auto;border-radius:16px;overflow:hidden;box-sizing:border-box;
     padding:16px 13px;position:relative;background:{{ $bg }};border:1px solid rgba(0,0,0,.14);
     font-family:system-ui,sans-serif">
    <span style="display:block;width:26%;aspect-ratio:1;border-radius:50%;margin:0 auto 9%;background:{{ $card }};
        border:1px solid {{ $border }}"></span>
    <span style="display:block;height:5px;width:56%;margin:0 auto 13%;border-radius:99px;background:{{ $muted }};opacity:.7"></span>
    <span style="display:block;height:15%;margin-bottom:8%;border-radius:{{ $rad }};background:{{ $card }};border:1px solid {{ $border }}"></span>
    <span style="display:block;height:15%;margin-bottom:8%;border-radius:{{ $rad }};background:{{ $card }};border:1px solid {{ $border }}"></span>
    <span style="display:block;height:15%;border-radius:{{ $rad }};background:{{ $accent }}"></span>
</div>
