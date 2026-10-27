{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- event block: event + RSVP (bio-t15 A10, SPEC §2 — extends the countdown
     concept). config: {title, date(ISO), url?, capture?:bool, location?}. Title +
     date + an optional server-computed countdown pill (SSR, no client clock —
     same days/hrs/min calc as bio/layouts/countdown.blade.php, reimplemented here
     because that CSS/markup is scoped to the countdown FAMILY layout, not
     reusable from an ordinary block) + an RSVP CTA, or the existing `capture`
     block inline when `capture:true`. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $title = trim((string) ($b['label'] ?? $cfg['title'] ?? ''));
    $location = trim((string) ($cfg['location'] ?? ''));
    $url = trim((string) ($cfg['url'] ?? '')) ?: trim((string) ($b['url'] ?? '')); // gap-fix: shared editor field submits to the top-level url column
    $capture = (bool) ($cfg['capture'] ?? false);
    $id = $b['id'] ?? '';

    $dateStr = trim((string) ($cfg['date'] ?? ''));
    $when = '';
    $digits = null;
    if ($dateStr !== '') {
        try {
            $t = \Illuminate\Support\Carbon::parse($dateStr);
            $when = $t->translatedFormat('D, j M Y · H:i');
            if ($t->isFuture()) {
                $mins = (int) round(abs(now()->diffInMinutes($t)));
                $digits = ['days' => intdiv($mins, 1440), 'hrs' => intdiv($mins % 1440, 60), 'min' => $mins % 60];
            }
        } catch (\Throwable $e) {
            $when = '';
        }
    }
@endphp
@if ($title !== '' || $when !== '')
    <div class="bio-card bio-event" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($title !== '')<p class="bio-card__title">{{ $title }}</p>@endif
        @if ($when !== '')<p class="bio-card__text bio-event__when">{{ $when }}</p>@endif
        @if ($location !== '')<p class="bio-card__text">{{ $location }}</p>@endif
        @if ($digits !== null)
            <div class="bio-event__digits">
                <div><b>{{ str_pad((string) $digits['days'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('days') }}</span></div>
                <div><b>{{ str_pad((string) $digits['hrs'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('hrs') }}</span></div>
                <div><b>{{ str_pad((string) $digits['min'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('min') }}</span></div>
            </div>
        @endif
        @if ($capture)
            @include('bio._blocks.capture', ['block' => [
                'id' => $id,
                'label' => ___('RSVP'),
                'config' => ['title' => ___('RSVP'), 'text' => $cfg['rsvp_text'] ?? ''],
            ]])
        @elseif ($url !== '')
            <a class="bio-cta bio-event__cta" href="{{ $url }}" rel="noopener">{{ ___('RSVP') }}</a>
        @endif
    </div>
@endif
