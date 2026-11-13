{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- reviews block: rating badge + leave-a-review CTA (bio-t15 A4, SPEC §2).
     config: {platform?:'google'|'yelp'|'tripadvisor'|'other', url?, rating?(0-5),
     count?, show_stars?}. `source:'live'|'manual'` (T2-6, pre-existing) resolves
     rating/count server-side in BioLinkService::resolveReviewBlocks() BEFORE this
     partial ever runs — untouched here. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $rating = trim((string) ($cfg['rating'] ?? ''));
    $count = trim((string) ($cfg['count'] ?? ''));
    $url = trim((string) ($b['url'] ?? $cfg['url'] ?? ''));
    $label = trim((string) ($b['label'] ?? '')) ?: ___('Guests say');
    $id = $b['id'] ?? '';
    $showStars = array_key_exists('show_stars', $cfg) ? (bool) $cfg['show_stars'] : true;
    $platforms = [
        'google' => ___('Google'), 'yelp' => ___('Yelp'),
        'tripadvisor' => ___('Tripadvisor'), 'other' => '',
    ];
    $platform = (string) ($cfg['platform'] ?? '');
    $platformLabel = $platforms[$platform] ?? '';
    $stars = '';
    $rv = (float) $rating;
    if ($rv > 0) { $full = (int) round($rv); $stars = str_repeat('★', max(0, min(5, $full))).str_repeat('☆', max(0, 5 - min(5, $full))); }
@endphp
@if ($rating !== '' || $count !== '')
    <div class="bio-card bio-reviews-card" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <p class="bio-kicker">{{ $label }}{{ $platformLabel !== '' ? ' · '.$platformLabel : '' }}</p>
        <div class="bio-reviews">
            @if ($showStars && $stars !== '')<span class="stars" aria-hidden="true">{{ $stars }}</span>@endif
            @if ($rating !== '')<b>{{ $rating }}</b>@endif
            @if ($count !== '')<span>{{ $count }} {{ ___('reviews') }}</span>@endif
        </div>
        @if ($url !== '')
            <a class="bio-reviews__cta" href="{{ external_url($url) }}" target="_blank" rel="noopener">{{ ___('Leave a review') }} <span class="bio-link__arrow" aria-hidden="true">→</span></a>
        @endif
    </div>
@endif
