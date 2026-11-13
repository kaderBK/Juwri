{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: one service row — the name opens its sheet, the round toggle adds it to the booking (a checkbox of the booking form) --}}
@php
    $__b = ($card['badges'] ?? [null])[0] ?? null;
    $__badgeIcons = ['popular' => 'flame', 'new' => 'sparkles', 'sale' => 'tag', 'offer' => 'tag'];
    $__thumb = $card['image']['src'] ?? null;
@endphp
<li class="c-svc" data-item="{{ $card['id'] }}" data-min="{{ $card['duration_min'] ?? 0 }}" data-venue="{{ ($card['venue'] ?? true) ? '1' : '0' }}" data-at-home="{{ ($card['at_home'] ?? false) ? '1' : '0' }}">
    @if ($__thumb)<img class="c-svc__thumb" src="{{ $__thumb }}" alt="" width="64" height="64" decoding="async" loading="lazy">@endif
    <div class="c-svc__body">
        @if ($__b)<span class="c-badge">{{ $kit->icon($__badgeIcons[$__b['kind']] ?? 'tag') }}<span>{{ $__b['label'] }}</span></span>@endif
        <h3 class="c-svc__name"><a class="c-svc__link" href="{{ $card['url'] }}" data-book-open="{{ $card['id'] }}">{{ $card['name'] }}</a></h3>
        @if (! empty($card['meta_label']))<p class="c-svc__meta">{{ $kit->flow($card['meta_label']) }}</p>@endif
        @if (! empty($card['short']))<p class="c-svc__desc">{{ $card['short'] }}</p>@endif
    </div>
    <label class="c-svc__toggle" for="svc-{{ $card['id'] }}"><input class="c-svc__input u-vh" type="checkbox" id="svc-{{ $card['id'] }}" name="services[]" value="{{ $card['id'] }}" form="bk-form" data-book-service data-name="{{ $card['name'] }}" data-price="{{ $card['price']['formatted'] ?? '' }}" @disabled(! $accepting)><span class="c-svc__plus" aria-hidden="true">{{ $kit->icon('plus') }}</span><span class="c-svc__tick" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="u-sr">{{ $kit->s('Add :name', ['name' => $card['name']]) }}</span></label>
</li>
