{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: one service in a sheet — what it is, how long, what it costs, what the cancellation rule is. The server renders it (a
     deep link, and the fragment theme.js opens). "Add to booking" ticks the service on the page; without script it is a form
     that goes straight to choosing a time for this one service. --}}
@if (empty($bookingMode))
@include('store_templates._kit.components.item-sheet')
@else
@php
    $__d = $item;
    $__gal = ($__d['gallery'] ?? [])[0] ?? null;
    $__b = ($__d['badges'] ?? [null])[0] ?? null;
    $__badgeIcons = ['popular' => 'flame', 'new' => 'sparkles', 'sale' => 'tag', 'offer' => 'tag'];
    $__home = $vm['endpoints']['home'];
@endphp
<div id="item-sheet" class="c-overlay" data-book-sheet role="dialog" aria-modal="true" aria-labelledby="sheet-title" @unless (($open ?? null) === 'item') hidden @endunless>
    <div class="c-overlay__scrim" aria-hidden="true" data-book-close></div>
    <div class="c-sheet c-sheet--svc">
        <form class="c-sheet__form" method="get" action="{{ $vm['endpoints']['times'] }}" data-book-sheet-form data-item="{{ $__d['id'] }}">
            <input type="hidden" name="services[]" value="{{ $__d['id'] }}">
            <div class="c-sheet__media">
                @if ($__gal)<img class="c-sheet__img" src="{{ $__gal['src'] }}" alt="" width="640" height="200" decoding="async">@endif
                <span class="c-sheet__handle" aria-hidden="true"></span>
                <a class="c-iconbtn c-iconbtn--glass c-sheet__close" href="{{ $__home }}" aria-label="{{ $kit->s('Close') }}" data-book-close>{{ $kit->icon('x') }}</a>
            </div>
            <div class="c-sheet__scroll">
                <div class="c-sheet__head">
                    @if ($__b)<span class="c-badge">{{ $kit->icon($__badgeIcons[$__b['kind']] ?? 'tag') }}<span>{{ $__b['label'] }}</span></span>@endif
                    <h2 class="c-sheet__title" id="sheet-title" tabindex="-1">{{ $__d['name'] }}</h2>
                    @if (! empty($__d['base_label']))<p class="c-sheet__base">{{ $kit->flow($__d['base_label']) }}</p>@endif
                    @if (! empty($__d['description']))<p class="c-sheet__desc">{{ $__d['description'] }}</p>@endif
                </div>
                @if (! empty($__d['deposit']) || ! empty($__d['policy_note']))
                <p class="c-policy">{{ $kit->icon('info') }}<span>{{ $kit->flow($__d['policy_note'] ?? '') }}@if (! empty($__d['deposit'])) <span>{{ $kit->t('A :amount deposit is taken when you book.', ['amount' => $kit->money($__d['deposit'])]) }}</span>@endif</span></p>
                @endif
            </div>
            <div class="c-sheet__foot">
                <button class="c-btn c-btn--primary c-btn--lg c-sheet__add" type="submit" data-book-add><span>{{ $kit->s('Add to booking') }}</span><bdi class="c-money">{{ $kit->flow(trim(explode('·', $__d['add_label'] ?? '', 2)[1] ?? '')) }}</bdi></button>
            </div>
        </form>
    </div>
</div>
@endif
